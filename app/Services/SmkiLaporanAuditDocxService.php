<?php

namespace App\Services;

use App\Models\SmkiLaporanAudit;
use App\Services\Concerns\FillsDocxTemplate;
use App\Services\TteResolverService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use DOMElement;
use Exception;
use ZipArchive;

class SmkiLaporanAuditDocxService
{
    use FillsDocxTemplate;
    /**
     * Cari path template dokumen FR-006 Laporan Audit Internal.
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            resource_path('templates/FR-006 Laporan Audit.docx'),
            base_path('resources/templates/FR-006 Laporan Audit.docx'),
            'D:/Dokumen dan Sertifikat Penting/Kuliah/MagangAptika/FR-006 Laporan Audit (1).docx',
            'D:/Dokumen dan Sertifikat Penting/Kuliah/MagangAptika/be_aptika/resources/templates/FR-006 Laporan Audit.docx',
            storage_path('app/templates/FR-006 Laporan Audit.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-006 tidak ditemukan. Pastikan file FR-006 Laporan Audit.docx ada di direktori resources/templates.');
    }

    /**
     * Generate file DOCX terisi berdasarkan model SmkiLaporanAudit.
     *
     * @param SmkiLaporanAudit $report
     * @return string Path file sementara
     */
    public function generateDocx(SmkiLaporanAudit $report): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeNum = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $report->nomor_laporan ?: 'FR-006');
        $outputPath = $tempDir . '/FR-006_' . $safeNum . '_' . uniqid() . '.docx';

        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin template FR-006.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak valid: word/document.xml tidak ditemukan.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Format tanggal
        Carbon::setLocale('id');
        $tanggalAuditFormatted = $report->tanggal_audit
            ? Carbon::parse($report->tanggal_audit)->translatedFormat('d F Y')
            : Carbon::now()->translatedFormat('d F Y');

        $unitKerjaName = $report->nama_unit_kerja ?: ($report->unitKerja?->nama_unit_kerja ?: 'Unit Kerja');
        $auditorName = $report->auditor ?: 'Auditor Internal';
        $auditeeName = $report->auditee ?: 'Pihak Auditee';

        // 1. Update text di paragraph (Tanggal XXXXX, Ruang Lingkup, Temuan Major/Minor/OFI)
        foreach ($xpath->query('//w:p') as $p) {
            $pText = $p->textContent;

            if (str_contains($pText, 'XXXXX')) {
                $this->replaceTextInNode($xpath, $dom, $p, 'XXXXX', $tanggalAuditFormatted);
            }
            if (str_contains($pText, 'Ruang lingkup audit mencakup ke beberapa unit kerja terkait, diantaranya :')) {
                $this->appendTextToNode($dom, $p, ' ' . $unitKerjaName . '.');
            }
            if (str_contains($pText, 'Temuan Major') && str_contains($pText, ':')) {
                $this->appendTextToNode($dom, $p, ' ' . $report->temuan_major);
            }
            if (str_contains($pText, 'Temuan Minor') && str_contains($pText, ':')) {
                $this->appendTextToNode($dom, $p, ' ' . $report->temuan_minor);
            }
            if (str_contains($pText, 'OFI') && str_contains($pText, ':')) {
                $this->appendTextToNode($dom, $p, ' ' . $report->ofi);
            }
        }

        $tables = $xpath->query('//w:tbl');

        // Table 0: Metadata Unit Kerja, Auditor, Auditee
        if ($tables->length >= 1) {
            $table0 = $tables->item(0);
            $rows0 = $xpath->query('w:tr', $table0);

            if ($rows0->length > 0) {
                $this->setCellText($xpath, $dom, $rows0->item(0), 1, ': ' . $unitKerjaName);
            }
            if ($rows0->length > 1) {
                $this->setCellText($xpath, $dom, $rows0->item(1), 1, ': ' . $auditorName);
            }
            if ($rows0->length > 2) {
                $this->setCellText($xpath, $dom, $rows0->item(2), 1, ': ' . $auditeeName);
            }
        }

        // Table 1: Rincian Temuan
        if ($tables->length >= 2) {
            $table1 = $tables->item(1);
            $rows1 = $xpath->query('w:tr', $table1);

            $findings = $report->detailTemuans()->get();

            // Baris 0 adalah Header
            // Baris 1 adalah Template baris kosong
            if ($rows1->length >= 2) {
                $templateRow = $rows1->item(1);

                if ($findings->isEmpty()) {
                    $this->setCellText($xpath, $dom, $templateRow, 0, '1');
                    $this->setCellText($xpath, $dom, $templateRow, 1, $tanggalAuditFormatted);
                    $this->setCellText($xpath, $dom, $templateRow, 2, '-');
                    $this->setCellText($xpath, $dom, $templateRow, 3, '-');
                    $this->setCellText($xpath, $dom, $templateRow, 4, 'Tidak ada temuan.');
                } else {
                    $first = true;
                    foreach ($findings as $idx => $f) {
                        $fDate = $f->tanggal_audit
                            ? Carbon::parse($f->tanggal_audit)->translatedFormat('d F Y')
                            : $tanggalAuditFormatted;

                        $desc = $f->deskripsi_temuan;
                        if (!empty($f->rekomendasi)) {
                            $desc .= "\n\nRekomendasi: " . $f->rekomendasi;
                        }

                        if ($first) {
                            $currentRow = $templateRow;
                            $first = false;
                        } else {
                            $currentRow = $templateRow->cloneNode(true);
                            $table1->appendChild($currentRow);
                        }

                        $this->setCellText($xpath, $dom, $currentRow, 0, (string)($idx + 1));
                        $this->setCellText($xpath, $dom, $currentRow, 1, $fDate);
                        $this->setCellText($xpath, $dom, $currentRow, 2, $f->kategori_temuan ?: '-');
                        $this->setCellText($xpath, $dom, $currentRow, 3, $f->klausul_annex ?: '-');
                        $this->setCellText($xpath, $dom, $currentRow, 4, $desc);
                    }
                }
            }
        }

        // Table 2: Tanda Tangan
        if ($tables->length >= 3) {
            $table2 = $tables->item(2);
            $rows2 = $xpath->query('w:tr', $table2);

            $todayFormatted = Carbon::now()->translatedFormat('d F Y');
            if ($rows2->length > 0) {
                $this->setCellText($xpath, $dom, $rows2->item(0), 0, 'Bandung, ' . $todayFormatted);
                $this->setCellText($xpath, $dom, $rows2->item(0), 1, 'Bandung, ' . $todayFormatted);
            }
            if ($rows2->length > 1) {
                $cellsRow1 = $xpath->query('w:tc', $rows2->item(1));
                if ($cellsRow1->length > 0) {
                    $this->fillSignatureCell($zip, $dom, $xpath, $cellsRow1->item(0), 'Auditor,', $auditorName);
                }
                if ($cellsRow1->length > 1) {
                    $this->fillSignatureCell($zip, $dom, $xpath, $cellsRow1->item(1), 'Auditee,', $auditeeName);
                }
            }
        }

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Helper untuk mengganti isi text suatu cell di row tabel Word XML
     */
    private function setCellText(DOMXPath $xpath, DOMDocument $dom, DOMElement $row, int $cellIndex, string $text): void
    {
        $cells = $xpath->query('w:tc', $row);
        if ($cellIndex >= $cells->length) {
            return;
        }

        $cell = $cells->item($cellIndex);

        // Cari atau buat w:p
        $pList = $xpath->query('w:p', $cell);
        if ($pList->length > 0) {
            $p = $pList->item(0);
            // Hapus isi w:r lama di p pertama
            foreach ($xpath->query('w:r', $p) as $r) {
                $p->removeChild($r);
            }
            // Hapus p kedua dan seterusnya jika ada
            for ($i = 1; $i < $pList->length; $i++) {
                $cell->removeChild($pList->item($i));
            }
        } else {
            $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
            $cell->appendChild($p);
        }

        // Tangani text multi-line
        $lines = explode("\n", $text);
        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $pNew = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
                $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $t->setAttribute('xml:space', 'preserve');
                $t->nodeValue = $line;
                $r->appendChild($t);
                $pNew->appendChild($r);
                $cell->appendChild($pNew);
            } else {
                $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $t->setAttribute('xml:space', 'preserve');
                $t->nodeValue = $line;
                $r->appendChild($t);
                $p->appendChild($r);
            }
        }
    }

    private function replaceTextInNode(DOMXPath $xpath, DOMDocument $dom, DOMElement $node, string $search, string $replace): void
    {
        foreach ($xpath->query('.//w:t', $node) as $t) {
            if (str_contains($t->nodeValue, $search)) {
                $t->nodeValue = str_replace($search, $replace, $t->nodeValue);
            }
        }
    }

    private function appendTextToNode(DOMDocument $dom, DOMElement $node, string $text): void
    {
        $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $t->setAttribute('xml:space', 'preserve');
        $t->nodeValue = $text;
        $r->appendChild($t);
        $node->appendChild($r);
    }

    /**
     * Sisipkan gambar spesimen TTE ber-QR Code ke dalam sel tanda tangan.
     */
    private function fillSignatureCell(
        ZipArchive $zip,
        DOMDocument $dom,
        DOMXPath $xpath,
        DOMElement $cell,
        string $roleTitle,
        string $signerName
    ): void {
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        $titleP = $this->createParagraph($dom, $roleTitle, 'center');
        $cell->appendChild($titleP);

        $path = $signerName !== '' ? app(TteResolverService::class)->imagePath('', $signerName) : null;

        if ($path !== null) {
            $imgP = $this->createImageParagraph($dom, 'center');
            if ($this->insertImageIntoParagraph($zip, $dom, $xpath, $imgP, $path, 340)) {
                $cell->appendChild($imgP);
            } else {
                $nameP = $this->createParagraph($dom, "\n\n\n\n\n(" . $signerName . ")", 'center');
                $cell->appendChild($nameP);
            }
        } else {
            $nameP = $this->createParagraph($dom, "\n\n\n\n\n(" . ($signerName ?: '……………………………………') . ")", 'center');
            $cell->appendChild($nameP);
        }
    }

    private function createParagraph(DOMDocument $dom, string $text, string $align = 'center'): DOMElement
    {
        $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
        $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
        $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
        $jc->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', $align);
        $pPr->appendChild($jc);
        $p->appendChild($pPr);

        $lines = explode("\n", $text);
        foreach ($lines as $idx => $line) {
            if ($idx > 0) {
                $rBr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $rBr->appendChild($dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br'));
                $p->appendChild($rBr);
            }
            if ($line !== '') {
                $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $t->setAttribute('xml:space', 'preserve');
                $t->appendChild($dom->createTextNode($line));
                $r->appendChild($t);
                $p->appendChild($r);
            }
        }

        return $p;
    }

    private function createImageParagraph(DOMDocument $dom, string $align = 'center'): DOMElement
    {
        $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
        $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
        $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
        $jc->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', $align);
        $pPr->appendChild($jc);
        $spacing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:spacing');
        $spacing->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:before', '60');
        $spacing->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:after', '60');
        $pPr->appendChild($spacing);
        $p->appendChild($pPr);

        return $p;
    }
}
