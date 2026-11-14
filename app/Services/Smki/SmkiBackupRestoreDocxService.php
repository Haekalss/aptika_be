<?php

namespace App\Services\Smki;

use App\Models\SmkiBackupRestoreEntry;
use App\Models\SmkiBackupRestoreReport;
use App\Services\Concerns\FillsDocxTemplate;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class SmkiBackupRestoreDocxService
{
    use FillsDocxTemplate;

    private DOMDocument $relationships;

    private DOMDocument $contentTypes;

    private int $imageNumber = 0;

    public function __construct(private readonly BackupRestoreSpecimenService $specimens) {}

    public function generateDocx(SmkiBackupRestoreReport $report): string
    {
        $report->loadMissing('assets.entries.attachments');
        $template = resource_path('templates/FR-030 Formulir Berita Acara Backup.docx');
        if (! is_file($template)) {
            throw new RuntimeException('Template FR-030 tidak ditemukan.');
        }
        $directory = storage_path('app/private/fr030-exports');
        File::ensureDirectoryExists($directory);
        $temp = tempnam($directory, 'FR030_');
        if ($temp === false) {
            throw new RuntimeException('Direktori ekspor tidak tersedia.');
        }
        $output = $temp.'.docx';
        if (! rename($temp, $output)) {
            @unlink($temp);
            throw new RuntimeException('Gagal membuat file ekspor.');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if (! copy($template, $output) || $zip->open($output) !== true) {
                throw new RuntimeException('Gagal membuka template FR-030.');
            }
            $opened = true;
            $this->imageNumber = 0;
            $this->relationships = $this->requiredPart($zip, 'word/_rels/document.xml.rels');
            $this->contentTypes = $this->requiredPart($zip, '[Content_Types].xml');
            $dom = $this->requiredPart($zip, 'word/document.xml');
            $xp = $this->docxXPath($dom);
            $tables = $xp->query('/w:document/w:body/w:tbl');
            if ($tables->length !== 2) {
                throw new RuntimeException('Struktur template FR-030 berubah.');
            }
            $matrix = $tables->item(0);
            $yearCell = $xp->query('w:tr[1]/w:tc[last()]', $matrix)->item(0);
            $this->setCellText($dom, $xp, $yearCell, 'Tahun: '.$report->tahun.($report->status === 'draft' ? ' - DRAF' : ''), 'center');
            foreach ($xp->query('w:tr[position() <= 2]', $matrix) as $heading) {
                $properties = $xp->query('w:trPr', $heading)->item(0);
                if (! $properties) {
                    $properties = $dom->createElementNS(self::W_NS, 'w:trPr');
                    $heading->insertBefore($properties, $heading->firstChild);
                }
                $properties->appendChild($dom->createElementNS(self::W_NS, 'w:tblHeader'));
            }
            $templateSlots = $xp->query('w:tr', $matrix)->length - 2;
            $rows = $this->ensureDataRows($xp, $matrix, 2, max($templateSlots, $report->assets->count()));
            foreach ($rows as $index => $row) {
                $this->preventRowSplit($dom, $xp, $row);
                $cells = $xp->query('w:tc', $row);
                $asset = $report->assets->get($index);
                $this->setCellText($dom, $xp, $cells->item(0), $asset ? ($index + 1).'. '.($asset->nama_aset ?? '') : '', 'left');
                for ($month = 1; $month <= 12; $month++) {
                    $entries = $asset?->entries->where('month', $month)->keyBy('operation');
                    $text = $asset ? 'B: '.$this->summary($entries?->get('backup'))."\nR: ".$this->summary($entries?->get('restore')) : '';
                    $this->setCellText($dom, $xp, $cells->item($month), $text, 'center');
                }
            }
            $this->signatories($zip, $dom, $xp, $tables->item(1), $report);
            $this->appendix($zip, $dom, $xp, $report);
            // The source defaults keepNext=true, which chains the whole dynamic body onto blank pages.
            foreach ($xp->query('/w:document/w:body//w:p') as $paragraph) {
                $properties = $xp->query('w:pPr', $paragraph)->item(0);
                if (! $properties) {
                    $properties = $dom->createElementNS(self::W_NS, 'w:pPr');
                    $paragraph->insertBefore($properties, $paragraph->firstChild);
                }
                $keepNext = $xp->query('w:keepNext', $properties)->item(0);
                if (! $keepNext) {
                    $keepNext = $dom->createElementNS(self::W_NS, 'w:keepNext');
                    $properties->insertBefore($keepNext, $properties->firstChild);
                }
                $keepNext->setAttributeNS(self::W_NS, 'w:val', $xp->query('w:outlineLvl', $properties)->length ? '1' : '0');
            }
            foreach ($xp->query('/w:document/w:body/w:tbl[2]/w:tr[1]//w:keepNext') as $keepNext) {
                $keepNext->setAttributeNS(self::W_NS, 'w:val', '1');
            }
            foreach ($xp->query('/w:document/w:body/w:p[w:r/w:drawing]/preceding-sibling::w:p[1]/w:pPr/w:keepNext') as $keepNext) {
                $keepNext->setAttributeNS(self::W_NS, 'w:val', '1');
            }
            $xp->query('/w:document/w:body/w:p[starts-with(normalize-space(.), "LAMPIRAN")]/w:pPr/w:keepNext')->item(0)->setAttributeNS(self::W_NS, 'w:val', '1');
            $firstHeader = $xp->query('//w:sectPr/w:headerReference[@w:type="first"]')->item(0);
            $defaultHeader = $xp->query('//w:sectPr/w:headerReference[@w:type="default"]')->item(0);
            $defaultHeader->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $firstHeader->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'));
            $zip->addFromString('word/document.xml', $dom->saveXML());
            foreach (['word/header1.xml', 'word/header2.xml'] as $part) {
                $header = $this->requiredPart($zip, $part);
                $hx = $this->docxXPath($header);
                foreach (iterator_to_array($hx->query('//w:tblPr/w:tblpPr|//w:tblPr/w:tblOverlap')) as $floating) {
                    $floating->parentNode->removeChild($floating);
                }
                $headerProperties = $hx->query('//w:tblPr')->item(0);
                $alignment = $header->createElementNS(self::W_NS, 'w:jc');
                $alignment->setAttributeNS(self::W_NS, 'w:val', 'center');
                $headerProperties->insertBefore($alignment, $hx->query('w:tblW', $headerProperties)->item(0)->nextSibling);
                $rows = $hx->query('//w:tbl/w:tr');
                foreach ([$report->nomor_dokumen ?? '', $report->nomor_revisi ?? '', $report->tanggal_terbit?->format('d/m/Y') ?? ''] as $index => $value) {
                    $cell = $hx->query('w:tc[last()]', $rows->item($index))->item(0);
                    $this->setCellText($header, $hx, $cell, $value);
                }
                $zip->addFromString($part, $header->saveXML());
            }
            $zip->addFromString('word/_rels/document.xml.rels', $this->relationships->saveXML());
            $zip->addFromString('[Content_Types].xml', $this->contentTypes->saveXML());
            if (! $zip->close()) {
                throw new RuntimeException('Gagal menyelesaikan paket DOCX.');
            }
            $opened = false;

            return $output;
        } catch (Throwable $error) {
            if ($opened) {
                $zip->close();
            }
            @unlink($output);
            throw $error;
        }
    }

    private function requiredPart(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = $zip->getFromName($name);
        $dom = new DOMDocument;
        if ($xml === false || ! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Bagian DOCX tidak valid: '.$name);
        }

        return $dom;
    }

    private function summary(?SmkiBackupRestoreEntry $entry): string
    {
        if (! $entry) {
            return '–';
        }
        if (! $entry->result || ! $entry->performed_on) {
            return '?';
        }

        return $entry->result === 'berhasil' ? '✓' : '×';
    }

    private function paragraph(DOMDocument $dom, string $text): DOMNode
    {
        $p = $dom->createElementNS(self::W_NS, 'w:p');
        $p->appendChild($this->makeRun($dom, $text));

        return $p;
    }

    private function signatories(ZipArchive $zip, DOMDocument $dom, DOMXPath $xp, DOMNode $table, SmkiBackupRestoreReport $report): void
    {
        $row = $xp->query('w:tr[2]', $table)->item(0);
        $this->preventRowSplit($dom, $xp, $row);
        foreach (['pelaksana', 'penanggung-jawab'] as $index => $role) {
            $cell = $xp->query('w:tc', $row)->item($index);
            foreach (iterator_to_array($xp->query('w:p', $cell)) as $p) {
                $cell->removeChild($p);
            }
            $p = $this->paragraph($dom, '');
            $this->setAlign($dom, $xp, $p, 'center');
            $cell->appendChild($p);
            $path = $this->specimens->imagePath($report, $role);
            if ($path) {
                $this->embedImage($zip, $dom, $p, $path, 200);
            } else {
                $p->appendChild($this->makeRun($dom, 'Belum dipilih'));
            }
        }
    }

    private function appendix(ZipArchive $zip, DOMDocument $dom, DOMXPath $xp, SmkiBackupRestoreReport $report): void
    {
        $section = $xp->query('/w:document/w:body/w:sectPr')->item(0);
        $heading = $xp->query('/w:document/w:body/w:p[starts-with(normalize-space(.), "LAMPIRAN")]')->item(0);
        if (! $heading) {
            throw new RuntimeException('Judul lampiran template FR-030 tidak ditemukan.');
        }
        $properties = $xp->query('w:pPr', $heading)->item(0);
        if (! $properties) {
            $properties = $dom->createElementNS(self::W_NS, 'w:pPr');
            $heading->insertBefore($properties, $heading->firstChild);
        }
        $properties->appendChild($dom->createElementNS(self::W_NS, 'w:pageBreakBefore'));
        $spacer = $heading->previousSibling;
        while ($spacer && $spacer->localName === 'p' && trim($spacer->textContent) === '') {
            $previous = $spacer->previousSibling;
            $spacer->parentNode->removeChild($spacer);
            $spacer = $previous;
        }
        $spacer = $heading->nextSibling;
        while ($spacer && $spacer->localName === 'p' && trim($spacer->textContent) === '') {
            $next = $spacer->nextSibling;
            $spacer->parentNode->removeChild($spacer);
            $spacer = $next;
        }
        $legend = $this->paragraph($dom, 'B = Backup; R = Restore; ✓ = Berhasil; × = Gagal; – = Belum dicatat; ? = Belum lengkap (Draf).');
        $section->parentNode->insertBefore($legend, $section);
        foreach ($report->assets as $assetIndex => $asset) {
            $previousMonth = null;
            foreach ($asset->entries->sortBy(fn ($entry) => sprintf('%02d-%s', $entry->month, $entry->operation)) as $entry) {
                if ($previousMonth !== $entry->month) {
                    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $group = $this->paragraph($dom, ($assetIndex + 1).'. '.($asset->nama_aset ?? '').' - '.$months[$entry->month - 1].' '.$report->tahun);
                    $pr = $dom->createElementNS(self::W_NS, 'w:pPr');
                    $outline = $dom->createElementNS(self::W_NS, 'w:outlineLvl');
                    $outline->setAttributeNS(self::W_NS, 'w:val', '1');
                    $pr->appendChild($outline);
                    $group->insertBefore($pr, $group->firstChild);
                    $section->parentNode->insertBefore($group, $section);
                    $previousMonth = $entry->month;
                }
                $details = ucfirst($entry->operation).' | '.($entry->result ? ucfirst($entry->result) : 'Belum lengkap').' | '.($entry->performed_on?->locale('id')->translatedFormat('j F Y') ?? 'Tanggal belum diisi');
                $activity = $this->paragraph($dom, $details);
                $section->parentNode->insertBefore($activity, $section);
                if (filled($entry->notes)) {
                    $section->parentNode->insertBefore($this->paragraph($dom, 'Catatan: '.$entry->notes), $section);
                }
                foreach ($entry->attachments->values() as $fileIndex => $file) {
                    abort_unless($file->disk === 'local' && str_starts_with($file->path, 'smki/backup-restore/'.$report->id.'/') && ! str_contains($file->path, '..') && Storage::disk('local')->exists($file->path), 503, 'Bukti kegiatan tidak tersedia.');
                    $section->parentNode->insertBefore($this->paragraph($dom, 'Bukti '.($fileIndex + 1)), $section);
                    $p = $this->paragraph($dom, '');
                    $section->parentNode->insertBefore($p, $section);
                    $this->embedImage($zip, $dom, $p, Storage::disk('local')->path($file->path), 640);
                }
            }
        }
    }

    private function embedImage(ZipArchive $zip, DOMDocument $dom, DOMNode $paragraph, string $path, int $width): void
    {
        $size = @getimagesize($path);
        if (! $size || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new RuntimeException('Gambar dokumen tidak valid.');
        }
        $extension = $size[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $number = ++$this->imageNumber;
        $name = 'fr030_'.$number.'.'.$extension;
        $id = 'rIdFR030_'.$number;
        if (! $zip->addFile($path, 'word/media/'.$name)) {
            throw new RuntimeException('Gagal menyisipkan gambar.');
        }
        $relationship = $this->relationships->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship');
        foreach (['Id' => $id, 'Type' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', 'Target' => 'media/'.$name] as $key => $value) {
            $relationship->setAttribute($key, $value);
        }
        $this->relationships->documentElement->appendChild($relationship);
        $types = new DOMXPath($this->contentTypes);
        $types->registerNamespace('c', 'http://schemas.openxmlformats.org/package/2006/content-types');
        if (! $types->query('/c:Types/c:Default[@Extension="'.$extension.'"]')->length) {
            $type = $this->contentTypes->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Default');
            $type->setAttribute('Extension', $extension);
            $type->setAttribute('ContentType', $size['mime']);
            $this->contentTypes->documentElement->appendChild($type);
        }
        $scale = min($width / $size[0], 380 / $size[1]);
        $cx = max(1, (int) round($size[0] * $scale * 9525));
        $cy = max(1, (int) round($size[1] * $scale * 9525));
        $drawingId = 10000 + $number;
        $drawing = new DOMDocument;
        $drawing->loadXML('<w:r xmlns:w="'.self::W_NS.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$drawingId.'" name="FR030 image '.$number.'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="'.$drawingId.'" name="'.$name.'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>');
        $paragraph->appendChild($dom->importNode($drawing->documentElement, true));
    }
}
