<?php

namespace App\Services\Smki;

use App\Models\SmkiTindakanPerbaikan;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class SmkiTindakanPerbaikanDocxService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function generateDocx(SmkiTindakanPerbaikan $report): string
    {
        $template = resource_path('templates/FR-009 Form Tindakan Perbaikan dan Pencegahan.docx');
        if (! is_file($template)) {
            throw new RuntimeException('Template resmi FR-009 belum tersedia di server.');
        }

        $directory = storage_path('app/private/fr009-exports/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        $output = $directory.'/FR-009.docx';
        $zip = new ZipArchive;
        $opened = false;

        try {
            if (! copy($template, $output) || $zip->open($output) !== true) {
                throw new RuntimeException('Gagal membuka salinan template FR-009.');
            }
            $opened = true;
            $dom = $this->parseXml($zip->getFromName('word/document.xml'));
            $xpath = $this->xpath($dom);
            $tables = $xpath->query('/w:document/w:body/w:tbl');
            if ($tables->length !== 2) {
                throw new RuntimeException('Struktur template FR-009 berubah.');
            }

            $paragraphs = $xpath->query('/w:document/w:body/w:p');
            $values = [
                $report->nomor_laporan,
                $report->area,
                $this->formatDate($report->tanggal_laporan),
                $report->diajukan_kepada_nama,
                $report->dilaporkan_oleh_nama,
            ];
            foreach ($values as $index => $value) {
                $paragraph = $paragraphs->item($index);
                if (! $paragraph instanceof DOMElement) {
                    throw new RuntimeException('Identitas laporan pada template FR-009 tidak valid.');
                }
                $this->appendValue($dom, $xpath, $paragraph, $value);
            }

            $this->fillSources($dom, $xpath, $tables->item(0), $report);
            $rows = iterator_to_array($xpath->query('w:tr', $tables->item(1)));
            if (count($rows) !== 10) {
                throw new RuntimeException('Tabel isi template FR-009 tidak valid.');
            }
            $this->fillNarrative($dom, $xpath, $rows[0], $report->deskripsi);
            $this->fillNarrative($dom, $xpath, $rows[2], $report->akar_masalah);
            $this->fillNarrative($dom, $xpath, $rows[8], $report->catatan_verifikasi);

            foreach ($xpath->query('.//w:t', $rows[1]) as $text) {
                if (str_contains($text->textContent, 'Minor')) {
                    $text->nodeValue = str_replace('Minor', $report->status_temuan ?? '', $text->textContent);
                }
            }

            $items = $report->items->sortBy('urutan')->values();
            $this->fillActions($dom, $xpath, $rows[4], $items->where('jenis', 'koreksi')->values());
            $this->fillActions($dom, $xpath, $rows[6], $items->where('jenis', 'tindakan_korektif')->values());
            $verificationCells = $xpath->query('w:tc', $rows[7]);
            $this->fillDecision($dom, $xpath, $verificationCells->item(1), $report->verifikasi);
            $this->appendValue($dom, $xpath, $xpath->query('w:p', $verificationCells->item(2))->item(0), $this->formatDate($report->tanggal_verifikasi));

            $signatureCells = $xpath->query('w:tc', $rows[9]);
            $audit = count(array_intersect($report->sumber_codes ?? [], ['audit_internal', 'audit_eksternal'])) > 0;
            $this->writeParagraph($dom, $xpath, $xpath->query('w:p', $signatureCells->item(0))->item(3), $audit ? $report->auditor_nama : null);
            $this->writeParagraph($dom, $xpath, $xpath->query('w:p', $signatureCells->item(1))->item(3), $report->atasan_nama);

            if (! $zip->addFromString('word/document.xml', $dom->saveXML())) {
                throw new RuntimeException('Gagal menyimpan isi DOCX.');
            }
            $this->updatePageCount($zip);
            if (! $zip->close()) {
                throw new RuntimeException('Gagal menyelesaikan DOCX.');
            }
            $opened = false;

            return $output;
        } catch (Throwable $exception) {
            if ($opened) {
                $zip->close();
            }
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    private function fillSources(DOMDocument $dom, DOMXPath $xpath, DOMNode $table, SmkiTindakanPerbaikan $report): void
    {
        $codes = [
            ['audit_internal', 'laporan_insiden'],
            ['audit_eksternal', 'usulan_saran'],
            ['pengukuran_pemantauan', 'lainnya'],
        ];
        $rows = $xpath->query('w:tr', $table);
        if ($rows->length !== 4) {
            throw new RuntimeException('Pilihan sumber pada template FR-009 tidak valid.');
        }
        foreach ($codes as $rowIndex => $rowCodes) {
            $cells = $xpath->query('w:tc', $rows->item($rowIndex + 1));
            foreach ($rowCodes as $column => $code) {
                $selected = in_array($code, $report->sumber_codes ?? [], true);
                $this->writeParagraph($dom, $xpath, $xpath->query('w:p', $cells->item($column * 2))->item(0), $selected ? 'X' : '');
            }
        }
        if (in_array('lainnya', $report->sumber_codes ?? [], true)) {
            $otherCell = $xpath->query('w:tc', $rows->item(3))->item(3);
            $this->writeParagraph($dom, $xpath, $xpath->query('w:p', $otherCell)->item(0), 'Lain-lain: '.($report->sumber_lainnya ?? ''));
        }
    }

    private function fillNarrative(DOMDocument $dom, DOMXPath $xpath, DOMNode $row, ?string $value): void
    {
        $cell = $xpath->query('w:tc', $row)->item(0);
        $this->writeParagraph($dom, $xpath, $xpath->query('w:p', $cell)->item(1), $value);
    }

    private function fillActions(DOMDocument $dom, DOMXPath $xpath, DOMNode $templateRow, Collection $items): void
    {
        // shortcut: inner action headers do not repeat across pages; revise after overflow layout approval.
        if ($items->isEmpty()) {
            return;
        }
        foreach ($items as $index => $item) {
            $row = $templateRow->cloneNode(true);
            $cells = $xpath->query('w:tc', $row);
            $values = [(string) ($index + 1), $item->uraian, $item->pic_nama, $this->formatDate($item->batas_waktu)];
            foreach ($values as $column => $value) {
                $paragraph = $xpath->query('w:p', $cells->item($column))->item(0);
                $minimumLines = 1 + $xpath->query('.//w:br', $paragraph)->length;
                $value = (string) ($value ?? '');
                $value .= str_repeat("\n", max(0, $minimumLines - count(preg_split('/\r\n|\r|\n/', $value))));
                $this->writeParagraph($dom, $xpath, $paragraph, $value);
            }
            $templateRow->parentNode->insertBefore($row, $templateRow);
        }
        $templateRow->parentNode->removeChild($templateRow);
    }

    private function fillDecision(DOMDocument $dom, DOMXPath $xpath, DOMNode $cell, ?string $decision): void
    {
        foreach ($xpath->query('.//w:r', $cell) as $run) {
            $properties = $xpath->query('w:rPr', $run)->item(0);
            foreach (iterator_to_array($xpath->query('w:rPr/w:strike', $run)) as $strike) {
                $strike->parentNode->removeChild($strike);
            }
            $unselected = $decision === 'Open' ? 'Close' : ($decision === 'Close' ? 'Open' : null);
            if ($unselected && str_contains($run->textContent, $unselected)) {
                if (! $properties) {
                    $properties = $dom->createElementNS(self::W, 'w:rPr');
                    $run->insertBefore($properties, $run->firstChild);
                }
                $properties->appendChild($dom->createElementNS(self::W, 'w:strike'));
            }
        }
    }

    private function appendValue(DOMDocument $dom, DOMXPath $xpath, DOMNode $paragraph, $value): void
    {
        if ($value !== null && $value !== '') {
            $this->appendRun($dom, $xpath, $paragraph, ' '.$value);
        }
    }

    private function writeParagraph(DOMDocument $dom, DOMXPath $xpath, ?DOMNode $paragraph, $value): void
    {
        if (! $paragraph instanceof DOMElement) {
            throw new RuntimeException('Sel isian pada template FR-009 tidak valid.');
        }
        $properties = $xpath->query('w:r/w:rPr', $paragraph)->item(0)
            ?? $xpath->query('w:pPr/w:rPr', $paragraph)->item(0);
        $properties = $properties?->cloneNode(true);
        foreach (iterator_to_array($paragraph->childNodes) as $node) {
            if ($node->localName !== 'pPr') {
                $paragraph->removeChild($node);
            }
        }
        $this->appendRun($dom, $xpath, $paragraph, (string) ($value ?? ''), $properties);
    }

    private function appendRun(DOMDocument $dom, DOMXPath $xpath, DOMNode $paragraph, string $value, ?DOMNode $properties = null): void
    {
        $properties ??= $xpath->query('w:r[last()]/w:rPr', $paragraph)->item(0)
            ?? $xpath->query('w:pPr/w:rPr', $paragraph)->item(0);
        $run = $dom->createElementNS(self::W, 'w:r');
        if ($properties) {
            $run->appendChild($properties->cloneNode(true));
        }
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        foreach (preg_split('/\r\n|\r|\n/', $value) as $index => $line) {
            if ($index > 0) {
                $run->appendChild($dom->createElementNS(self::W, 'w:br'));
            }
            $text = $dom->createElementNS(self::W, 'w:t');
            $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $text->appendChild($dom->createTextNode($line));
            $run->appendChild($text);
        }
        $paragraph->appendChild($run);
    }

    private function updatePageCount(ZipArchive $zip): void
    {
        $footer = $zip->getFromName('word/footer1.xml');
        if ($footer !== false) {
            $dom = $this->parseXml($footer);
            $xpath = $this->xpath($dom);
            foreach ($xpath->query('//w:t') as $text) {
                if ($text->textContent !== ' of 1') {
                    continue;
                }
                $text->nodeValue = ' of ';
                $run = $text->parentNode;
                $field = $dom->createElementNS(self::W, 'w:fldSimple');
                $field->setAttributeNS(self::W, 'w:instr', ' NUMPAGES ');
                $result = $run->cloneNode(true);
                $xpath->query('w:t', $result)->item(0)->nodeValue = '1';
                $field->appendChild($result);
                $run->parentNode->appendChild($field);
                break;
            }
            if (! $zip->addFromString('word/footer1.xml', $dom->saveXML())) {
                throw new RuntimeException('Gagal menyimpan footer DOCX.');
            }
        }
        $settings = $zip->getFromName('word/settings.xml');
        if ($settings !== false) {
            $dom = $this->parseXml($settings);
            $xpath = $this->xpath($dom);
            $update = $xpath->query('/w:settings/w:updateFields')->item(0);
            if (! $update) {
                $update = $dom->createElementNS(self::W, 'w:updateFields');
                $next = $xpath->query('/w:settings/w:footnotePr | /w:settings/w:endnotePr | /w:settings/w:compat')->item(0);
                $next ? $dom->documentElement->insertBefore($update, $next) : $dom->documentElement->appendChild($update);
            }
            $update->setAttributeNS(self::W, 'w:val', 'true');
            if (! $zip->addFromString('word/settings.xml', $dom->saveXML())) {
                throw new RuntimeException('Gagal menyimpan pengaturan DOCX.');
            }
        }
    }

    private function formatDate($date): string
    {
        return $date ? Carbon::parse($date)->locale('id')->translatedFormat('j F Y') : '';
    }

    private function parseXml(string|false $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if (! $xml || ! $dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Bagian XML template FR-009 tidak valid.');
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        return $xpath;
    }
}
