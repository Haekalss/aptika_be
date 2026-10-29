<?php

namespace App\Services;

use App\Models\LaporanKerusakanDataCenter;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Exception;
use ZipArchive;

class SmkiLaporanKerusakanDocxService
{
    /**
     * Dapatkan path template FR-024.
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            resource_path('templates/FR-024 Formulir Laporan Kerusakan.docx'),
            base_path('resources/templates/FR-024 Formulir Laporan Kerusakan.docx'),
            base_path('../FR-024 Formulir Laporan Kerusakan, GangguanInsiden Perangkat Fasilitas Data Center.docx'),
            'd:/TUGAS/SEMESTER 7/Diskominfo/Develop/FR-024 Formulir Laporan Kerusakan, GangguanInsiden Perangkat Fasilitas Data Center.docx',
            storage_path('app/templates/FR-024 Formulir Laporan Kerusakan.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-024 tidak ditemukan. Pastikan file FR-024 Formulir Laporan Kerusakan.docx ada di direktori resources/templates.');
    }

    /**
     * Format tanggal berlaku ke Bahasa Indonesia (contoh: 20 Januari 2022).
     */
    private function formatTanggalIndo(?string $value, string $fallback = '20 Januari 2022'): string
    {
        if (empty($value)) {
            return $fallback;
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value))) {
                return Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
            }
            return trim($value);
        } catch (\Throwable $e) {
            return trim($value) ?: $fallback;
        }
    }

    /**
     * Generate file DOCX untuk 1 laporan kerusakan.
     */
    public function generateSingleDocx(LaporanKerusakanDataCenter $laporan, array $meta = []): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeNum = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $laporan->nomor_laporan ?: 'FR-024');
        $outputPath = $tempDir . '/FR-024_' . $safeNum . '_' . uniqid() . '.docx';

        $templatePath = self::getTemplatePath();
        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin template FR-024.');
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

        // Logika Tanda Tangan Realistis (SMKI ISO 27001):
        // 1. Disusun: Selalu ditandatangani oleh Teknisi Pelapor
        $disusun = $laporan->pelapor_nama ?: ($laporan->pelapor?->name ?: 'Teknisi Data Center');

        // 2. Mengetahui: Ditandatangani oleh Supervisor / Pejabat Penyetuju
        $isClosed = ($laporan->status === 'Closed' || !empty($laporan->validated_at));
        if ($isClosed) {
            // Sudah sah dan divalidasi oleh Supervisor
            $mengetahui = $laporan->supervisor_nama ?: ($laporan->supervisor?->name ?: 'Supervisor Data Center');
            $tanggalTtd = $laporan->validated_at
                ? Carbon::parse($laporan->validated_at)->locale('id')->translatedFormat('d F Y')
                : ($laporan->tanggal ? Carbon::parse($laporan->tanggal)->locale('id')->translatedFormat('d F Y') : Carbon::now()->locale('id')->translatedFormat('d F Y'));
        } else {
            // Belum divalidasi supervisor (Draft / Sedang Ditangani Teknisi)
            if (!empty($laporan->supervisor_nama)) {
                $mengetahui = $laporan->supervisor_nama . ' (Draft / Menunggu Validasi)';
            } else {
                $mengetahui = '……………………………….';
            }
            $tanggalTtd = $laporan->tanggal
                ? Carbon::parse($laporan->tanggal)->locale('id')->translatedFormat('d F Y')
                : Carbon::now()->locale('id')->translatedFormat('d F Y');
        }

        $docMeta = [
            'no_dokumen' => $meta['no_dokumen'] ?? ($laporan->no_dokumen ?: 'F24-SMKI'),
            'no_revisi' => $meta['no_revisi'] ?? ($laporan->no_revisi ?: '1.0'),
            'tanggal_berlaku' => $this->formatTanggalIndo(
                $meta['tanggal_berlaku'] ?? ($laporan->tanggal_berlaku ? Carbon::parse($laporan->tanggal_berlaku)->format('Y-m-d') : null)
            ),
        ];

        $this->populateDocument($dom, $xpath, [$laporan], $disusun, $mengetahui, $tanggalTtd, $docMeta);

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Generate file DOCX untuk banyak laporan (batch export).
     */
    public function generateBatchDocx($laporans, array $meta = []): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $outputPath = $tempDir . '/FR-024_Laporan_Kerusakan_' . date('Ymd_His') . '_' . uniqid() . '.docx';

        $templatePath = self::getTemplatePath();
        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin template FR-024.');
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

        $disusun = $meta['disusun'] ?? '……………………………….';
        $mengetahui = $meta['mengetahui'] ?? '……………………………….';
        $tanggalTtd = $meta['tanggal'] ?? Carbon::now()->locale('id')->translatedFormat('d F Y');

        $firstReport = (is_iterable($laporans) && count($laporans) > 0) ? $laporans[0] : null;
        $docMeta = [
            'no_dokumen' => $meta['no_dokumen'] ?? ($firstReport?->no_dokumen ?: 'F24-SMKI'),
            'no_revisi' => $meta['no_revisi'] ?? ($firstReport?->no_revisi ?: '1.0'),
            'tanggal_berlaku' => $this->formatTanggalIndo(
                $meta['tanggal_berlaku'] ?? ($firstReport?->tanggal_berlaku ? Carbon::parse($firstReport->tanggal_berlaku)->format('Y-m-d') : null)
            ),
        ];

        $this->populateDocument($dom, $xpath, $laporans, $disusun, $mengetahui, $tanggalTtd, $docMeta);

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Mengisi tabel data dan tanda tangan ke dalam XML dokumen FR-024.
     */
    private function populateDocument(
        DOMDocument $dom,
        DOMXPath $xpath,
        $laporans,
        string $disusunNama,
        string $mengetahuiNama,
        string $tanggalTtd,
        array $docMeta = []
    ): void {
        Carbon::setLocale('id');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length < 1) {
            throw new Exception('Tabel laporan tidak ditemukan dalam template FR-024.');
        }

        // -------------------------------------------------------------
        // 1. TABEL 0: Header Kop & Data Laporan
        // -------------------------------------------------------------
        $dataTable = $tables->item(0);
        $rows = $xpath->query('w:tr', $dataTable);

        // Isi identitas dokumen pada kop tabel (Row 0..2, Cell 4)
        $headerMap = [
            0 => $docMeta['no_dokumen'] ?? null,
            1 => $docMeta['no_revisi'] ?? null,
            2 => $docMeta['tanggal_berlaku'] ?? null,
        ];
        foreach ($headerMap as $rowIndex => $val) {
            if (!empty($val) && $rows->length > $rowIndex) {
                $hRow = $rows->item($rowIndex);
                $hCells = $xpath->query('w:tc', $hRow);
                if ($hCells->length > 4) {
                    $this->writeCellText($dom, $xpath, $hCells->item(4), (string) $val);
                }
            }
        }

        // Row 4 adalah baris header (10 kolom)
        // Row 5..8 adalah baris sampel
        if ($rows->length >= 6) {
            $sampleRow = $rows->item(5);
            $templateRow = $sampleRow->cloneNode(true);

            // Bersihkan baris sampel lama (mulai dari row 5 hingga akhir tabel data)
            for ($i = $rows->length - 1; $i >= 5; $i--) {
                $dataTable->removeChild($rows->item($i));
            }

            // Jika tidak ada data laporan sama sekali
            if (empty($laporans) || count($laporans) === 0) {
                $emptyRow = $templateRow->cloneNode(true);
                $cells = $xpath->query('w:tc', $emptyRow);
                $this->writeCellText($dom, $xpath, $cells->item(0), '1');
                $this->writeCellText($dom, $xpath, $cells->item(1), '-');
                $this->writeCellText($dom, $xpath, $cells->item(2), '-');
                $this->writeCellText($dom, $xpath, $cells->item(3), 'Tidak ada data laporan kerusakan');
                for ($c = 4; $c < $cells->length; $c++) {
                    $this->writeCellText($dom, $xpath, $cells->item($c), '-');
                }
                $dataTable->appendChild($emptyRow);
            } else {
                $no = 1;
                foreach ($laporans as $item) {
                    $newRow = $templateRow->cloneNode(true);
                    $cells = $xpath->query('w:tc', $newRow);

                    // 1. No
                    $nomorText = (string) $no++;

                    // 2. Tanggal
                    $tanggalText = $item->tanggal
                        ? Carbon::parse($item->tanggal)->translatedFormat('d F Y')
                        : '-';

                    // 3. Jam
                    $jamText = $item->jam ?: '-';

                    // 4. Nama Aset/Perangkat
                    $namaAset = $item->nama_aset ?: ($item->asset?->nama_aset ?: '-');
                    if (!empty($item->kode_aset)) {
                        $namaAset .= ' (' . $item->kode_aset . ')';
                    }
                    if (!empty($item->lokasi_rak)) {
                        $namaAset .= ' - Lokasi: ' . $item->lokasi_rak;
                    }

                    // 5. Jenis Kerusakan/Gangguan
                    $jenisKerusakan = $item->jenis_kerusakan ?: '-';

                    // 6. Penyebab Kerusakan/Gangguan
                    $penyebab = $item->penyebab ?: '-';

                    // 7. Uraian Tindakan Perbaikan
                    $uraian = $item->uraian_tindakan ?: '-';

                    // 8. Penanggung Jawab
                    $pj = $item->penanggung_jawab_nama ?: ($item->penanggungJawab?->name ?: '-');

                    // 9. Status Perbaikan
                    $statusLower = strtolower((string) $item->status);
                    $statusHuman = match ($statusLower) {
                        'selesai', 'closed' => 'Selesai',
                        'sedang_dikerjakan', 'diproses' => 'Sedang Dikerjakan',
                        'dibatalkan' => 'Dibatalkan',
                        default => 'Baru Dilaporkan',
                    };

                    // 10. Target Selesai
                    $targetSelesaiText = $item->target_selesai
                        ? Carbon::parse($item->target_selesai)->translatedFormat('d F Y')
                        : '-';

                    $rowValues = [
                        $nomorText,
                        $tanggalText,
                        $jamText,
                        $namaAset,
                        $jenisKerusakan,
                        $penyebab,
                        $uraian,
                        $pj,
                        $statusHuman,
                        $targetSelesaiText,
                    ];

                    for ($c = 0; $c < count($rowValues) && $c < $cells->length; $c++) {
                        $cell = $cells->item($c);
                        $this->writeCellText($dom, $xpath, $cell, $rowValues[$c], [
                            'font' => 'Arial',
                            'size' => 9,
                        ]);
                    }

                    $dataTable->appendChild($newRow);
                }
            }
        }

        // -------------------------------------------------------------
        // 2. TABEL 1: Footer & Tanda Tangan
        // -------------------------------------------------------------
        if ($tables->length >= 2) {
            $ttdTable = $tables->item(1);
            $ttdRows = $xpath->query('w:tr', $ttdTable);

            // Row 0: "Bandung, ………………….."
            if ($ttdRows->length > 0) {
                $row0 = $ttdRows->item(0);
                $cells0 = $xpath->query('w:tc', $row0);
                if ($cells0->length > 0) {
                    $cellTgl = $cells0->item(0);
                    $this->replaceTextInCell($xpath, $cellTgl, 'Bandung, …………………..', 'Bandung, ' . $tanggalTtd);
                }
            }

            // Row 1: Disusun & Mengetahui
            if ($ttdRows->length > 1) {
                $row1 = $ttdRows->item(1);
                $cells1 = $xpath->query('w:tc', $row1);

                // Cell 0: Disusun
                if ($cells1->length > 0) {
                    $cellDisusun = $cells1->item(0);
                    $this->replaceTextInCell($xpath, $cellDisusun, '(………………………………..)', '(' . $disusunNama . ')');
                }

                // Cell 2: Mengetahui
                if ($cells1->length > 2) {
                    $cellMengetahui = $cells1->item(2);
                    $this->replaceTextInCell($xpath, $cellMengetahui, '(………………………………..)', '(' . $mengetahuiNama . ')');
                }
            }
        }
    }

    /**
     * Tulis teks ke dalam cell tabel DOCX.
     */
    private function writeCellText(DOMDocument $dom, DOMXPath $xpath, $cell, string $text, array $fontOptions = []): void
    {
        $textNodes = $xpath->query('.//w:t', $cell);

        if ($textNodes->length > 0) {
            $textNodes->item(0)->nodeValue = htmlspecialchars($text, ENT_XML1, 'UTF-8');
            for ($k = 1; $k < $textNodes->length; $k++) {
                $tn = $textNodes->item($k);
                $tn->parentNode->removeChild($tn);
            }
            if (!empty($fontOptions)) {
                $runNode = $textNodes->item(0)->parentNode;
                if ($runNode) {
                    $this->applyRunFont($dom, $xpath, $runNode, $fontOptions);
                }
            }
        } else {
            $p = $xpath->query('.//w:p', $cell)->item(0);
            if ($p) {
                $r = $dom->createElement('w:r');
                if (!empty($fontOptions)) {
                    $this->applyRunFont($dom, $xpath, $r, $fontOptions);
                }
                $t = $dom->createElement('w:t');
                $t->appendChild($dom->createTextNode($text));
                $r->appendChild($t);
                $p->appendChild($r);
            }
        }
    }

    /**
     * Ganti substring di dalam suatu cell w:tc.
     */
    private function replaceTextInCell(DOMXPath $xpath, $cell, string $search, string $replace): void
    {
        $textNodes = $xpath->query('.//w:t', $cell);
        foreach ($textNodes as $t) {
            if (str_contains($t->nodeValue, $search)) {
                $t->nodeValue = str_replace($search, $replace, $t->nodeValue);
                return;
            }
        }

        // Jika string terpecah antar node w:t di paragraf yang sama
        $paras = $xpath->query('.//w:p', $cell);
        foreach ($paras as $p) {
            if (str_contains($p->textContent, $search)) {
                $pTextNodes = $xpath->query('.//w:t', $p);
                if ($pTextNodes->length > 0) {
                    $newFull = str_replace($search, $replace, $p->textContent);
                    $pTextNodes->item(0)->nodeValue = htmlspecialchars($newFull, ENT_XML1, 'UTF-8');
                    for ($k = 1; $k < $pTextNodes->length; $k++) {
                        $pTextNodes->item($k)->parentNode->removeChild($pTextNodes->item($k));
                    }
                }
                return;
            }
        }
    }

    /**
     * Terapkan font family dan ukuran pada run (w:r).
     */
    private function applyRunFont(DOMDocument $dom, DOMXPath $xpath, $runNode, array $fontOptions): void
    {
        $fontName = $fontOptions['font'] ?? null;
        $fontSize = isset($fontOptions['size']) ? (int) $fontOptions['size'] : null;

        if (!$fontName && !$fontSize) {
            return;
        }

        $rPrList = $xpath->query('w:rPr', $runNode);
        if ($rPrList->length > 0) {
            $rPr = $rPrList->item(0);
        } else {
            $rPr = $dom->createElement('w:rPr');
            $runNode->insertBefore($rPr, $runNode->firstChild);
        }

        if ($fontName) {
            $oldFonts = $xpath->query('w:rFonts', $rPr);
            foreach ($oldFonts as $of) {
                $rPr->removeChild($of);
            }
            $rFonts = $dom->createElement('w:rFonts');
            $rFonts->setAttribute('w:ascii', $fontName);
            $rFonts->setAttribute('w:hAnsi', $fontName);
            $rFonts->setAttribute('w:cs', $fontName);
            $rPr->insertBefore($rFonts, $rPr->firstChild);
        }

        if ($fontSize) {
            $halfPoints = (string) ($fontSize * 2);
            $oldSz = $xpath->query('w:sz', $rPr);
            foreach ($oldSz as $os) {
                $rPr->removeChild($os);
            }
            $szEl = $dom->createElement('w:sz');
            $szEl->setAttribute('w:val', $halfPoints);
            $rPr->appendChild($szEl);

            $oldSzCs = $xpath->query('w:szCs', $rPr);
            foreach ($oldSzCs as $os) {
                $rPr->removeChild($os);
            }
            $szCsEl = $dom->createElement('w:szCs');
            $szCsEl->setAttribute('w:val', $halfPoints);
            $rPr->appendChild($szCsEl);
        }
    }
}
