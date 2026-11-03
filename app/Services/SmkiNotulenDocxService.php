<?php

namespace App\Services;

use App\Models\SmkiNotulen;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use DOMElement;
use Exception;
use ZipArchive;

class SmkiNotulenDocxService
{
    /**
     * Cari path template dokumen FR-011 Formulir Notulen Management Review SMKI.
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            base_path('../FR-011-SMKI Formulir NOTULEN MANAGEMENT REVIEW SMKI.docx'),
            resource_path('templates/FR-011-SMKI Formulir NOTULEN MANAGEMENT REVIEW SMKI.docx'),
            base_path('resources/templates/FR-011-SMKI Formulir NOTULEN MANAGEMENT REVIEW SMKI.docx'),
            storage_path('app/templates/FR-011-SMKI Formulir NOTULEN MANAGEMENT REVIEW SMKI.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-011 tidak ditemukan. Pastikan file FR-011-SMKI Formulir NOTULEN MANAGEMENT REVIEW SMKI.docx ada di direktori.');
    }

    /**
     * Generate file DOCX terisi berdasarkan model SmkiNotulen dengan Strict Layout Preservation.
     *
     * @param SmkiNotulen $notulen
     * @return string Path file sementara
     */
    public function generateDocx(SmkiNotulen $notulen): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeNum = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $notulen->nomor_notulen ?: 'FR-011');
        $outputPath = $tempDir . '/FR-011_' . $safeNum . '_' . uniqid() . '.docx';

        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin template FR-011.');
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

        Carbon::setLocale('id');
        $tanggalPelaksanaan = $notulen->tanggal
            ? Carbon::parse($notulen->tanggal)->translatedFormat('d F Y')
            : '-';
        $hariPelaksanaan = $notulen->tanggal
            ? Carbon::parse($notulen->tanggal)->translatedFormat('l')
            : '';
        $tanggalBerlakuFormatted = $notulen->tanggal_berlaku
            ? Carbon::parse($notulen->tanggal_berlaku)->translatedFormat('d F Y')
            : ($notulen->tanggal_berlaku ?: '20 Januari 2022');

        $waktuPelaksanaan = $notulen->waktu_mulai
            ? "{$notulen->waktu_mulai} - {$notulen->waktu_selesai} " . ($notulen->zona_waktu ?: 'WIB')
            : '-';

        $tables = $xpath->query('//w:tbl');

        // =========================================================================
        // 1. Table 0: Metadata Kop Formulir (No Dokumen, No Revisi, Tanggal Berlaku)
        // =========================================================================
        if ($tables->length >= 1) {
            $table0 = $tables->item(0);
            $rows0 = $xpath->query('w:tr', $table0);

            // Row 0: No. Dokumen
            if ($rows0->length > 0 && !empty($notulen->no_dokumen)) {
                $cells = $xpath->query('w:tc', $rows0->item(0));
                if ($cells->length >= 5) {
                    $this->setCellStrictText($xpath, $dom, $cells->item(4), $notulen->no_dokumen);
                }
            }
            // Row 1: No. Revisi
            if ($rows0->length > 1 && !empty($notulen->no_revisi)) {
                $cells = $xpath->query('w:tc', $rows0->item(1));
                if ($cells->length >= 5) {
                    $this->setCellStrictText($xpath, $dom, $cells->item(4), $notulen->no_revisi);
                }
            }
            // Row 2: Tanggal Berlaku
            if ($rows0->length > 2) {
                $cells = $xpath->query('w:tc', $rows0->item(2));
                if ($cells->length >= 5) {
                    $this->setCellStrictText($xpath, $dom, $cells->item(4), $tanggalBerlakuFormatted);
                }
            }
        }

        // =========================================================================
        // 2. Paragraf Judul Rapat, Tindak Lanjut, Tanggal TTD, dan Nama TTD
        // =========================================================================
        foreach ($xpath->query('//w:p') as $p) {
            $pText = $p->textContent;

            // Meeting banner
            if (str_contains($pText, 'Meeting :') || str_contains($pText, 'Meeting:')) {
                $judulRapat = $notulen->judul_rapat ?: 'ISO 27001:2013 Management Review';
                $this->replaceTextInNodeStrict($xpath, $p, 'ISO 27001:2013 Management Review', $judulRapat);
            }

            // Catatan tindak lanjut di baris penutup
            if (str_contains($pText, 'Hasil rapat management review ini akan diperiksa statusnya')) {
                if (!empty($notulen->tindak_lanjut)) {
                    $this->replaceTextInNodeStrict(
                        $xpath,
                        $p,
                        'Hasil rapat management review ini akan diperiksa statusnya pada rapat berikutnya yang pelaksanaannya akan ditentukan kemudian.',
                        $notulen->tindak_lanjut
                    );
                }
            }

            // Tanggal tanda tangan: Bandung, ……………… 202..
            if (str_contains($pText, 'Bandung,') && (str_contains($pText, '202..') || str_contains($pText, '……'))) {
                $this->replaceTextInNodeStrict($xpath, $p, '……………… 202..', $tanggalPelaksanaan);
            }

            // Notulis
            if (str_contains($pText, '(Notulis)')) {
                $notulisName = $notulen->notulis ?: 'Notulis';
                $this->replaceTextInNodeStrict($xpath, $p, '(Notulis)', '(' . $notulisName . ')');
            }

            // Koordinator SMKI
            if (str_contains($pText, '(…………………………………….)')) {
                $koordName = $notulen->koordinator_smki ?: '…………………………………….';
                $this->replaceTextInNodeStrict($xpath, $p, '(…………………………………….)', '(' . $koordName . ')');
            }
        }

        // =========================================================================
        // 3. Table 1: Peserta Rapat (Conditional Cloning & Clean Empty Rows)
        // =========================================================================
        if ($tables->length >= 2) {
            $table1 = $tables->item(1);
            $rows1 = $xpath->query('w:tr', $table1);

            $pesertas = $notulen->pesertas()->get();
            if ($rows1->length >= 2) {
                // Simpan baris template row 1 sebagai acuan style
                $templateRow = $rows1->item(1)->cloneNode(true);

                // Bersihkan SEMUA baris data lama (hapus dari index 1 ke atas)
                for ($i = $rows1->length - 1; $i >= 1; $i--) {
                    $table1->removeChild($rows1->item($i));
                }

                // Jika ada data peserta, clone HANYA sebanyak data yang ada (tanpa baris kosong sisa)
                if ($pesertas->isNotEmpty()) {
                    foreach ($pesertas as $idx => $pItem) {
                        $newRow = $templateRow->cloneNode(true);
                        $table1->appendChild($newRow);

                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $newRow, 0), (string)($idx + 1));
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $newRow, 1), $pItem->nama ?: '');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $newRow, 2), $pItem->jabatan ?: '');
                    }
                }
                // Jika array peserta kosong sama sekali, seluruh baris kosong sudah terhapus bersih.
            }
        }

        // =========================================================================
        // 4. Table 2: Pelaksanaan (Hari/Tanggal, Waktu, Tempat)
        // =========================================================================
        if ($tables->length >= 3) {
            $table2 = $tables->item(2);
            $rows2 = $xpath->query('w:tr', $table2);

            if ($rows2->length >= 2) {
                $dataRow = $rows2->item(1);
                $tanggalDisplay = $hariPelaksanaan ? "{$hariPelaksanaan}, {$tanggalPelaksanaan}" : $tanggalPelaksanaan;
                $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $dataRow, 0), $tanggalDisplay);
                $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $dataRow, 1), $waktuPelaksanaan);
                $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $dataRow, 2), $notulen->tempat ?: '-');
            }
        }

        // =========================================================================
        // 5. Table 3: Tujuan Pertemuan
        // =========================================================================
        if ($tables->length >= 4) {
            $table3 = $tables->item(3);
            $rows3 = $xpath->query('w:tr', $table3);

            if ($rows3->length >= 1 && !empty($notulen->tujuan)) {
                $row = $rows3->item(0);
                $cell = $this->getCellByIndex($xpath, $row, 1);
                if ($cell) {
                    $this->setCellStrictText($xpath, $dom, $cell, $notulen->tujuan);
                }
            }
        }

        // =========================================================================
        // 6. Table 4: Review Agenda (List Agenda)
        // =========================================================================
        if ($tables->length >= 5) {
            $table4 = $tables->item(4);
            $rows4 = $xpath->query('w:tr', $table4);

            $agendas = $notulen->agendas()->orderBy('nomor_agenda')->get();
            if ($agendas->isNotEmpty() && $rows4->length >= 1) {
                $firstRow = $rows4->item(0)->cloneNode(true);

                // Bersihkan semua baris lama
                for ($i = $rows4->length - 1; $i >= 0; $i--) {
                    $table4->removeChild($rows4->item($i));
                }

                foreach ($agendas as $idx => $ag) {
                    $currentRow = $firstRow->cloneNode(true);
                    $table4->appendChild($currentRow);

                    $cells = $xpath->query('w:tc', $currentRow);
                    if ($cells->length >= 3) {
                        // Cell 0: Teks "Review Agenda :" hanya di baris pertama, berikutnya kosong
                        if ($idx > 0) {
                            $this->setCellStrictText($xpath, $dom, $cells->item(0), '');
                        } else {
                            $this->setCellStrictText($xpath, $dom, $cells->item(0), 'Review Agenda :');
                        }
                        $this->setCellStrictText($xpath, $dom, $cells->item(1), ($ag->nomor_agenda ?: ($idx + 1)) . '.');
                        $this->setCellStrictText($xpath, $dom, $cells->item(2), $ag->agenda_item ?: '');
                    }
                }
            }
        }

        // =========================================================================
        // 7. Table 5: Pembahasan 10 Agenda (Conditional Cloning & Clean Empty Rows)
        // =========================================================================
        if ($tables->length >= 6) {
            $table5 = $tables->item(5);
            $rows5 = $xpath->query('w:tr', $table5);

            $agendas = $notulen->agendas()->orderBy('nomor_agenda')->get();
            // Baris 0 adalah Header Kolom
            // Baris 1 s/d selesai adalah data awal di template
            if ($rows5->length >= 2) {
                // Gunakan baris 1 sebagai master template row styling
                $templateRow = $rows5->item(1)->cloneNode(true);

                // Bersihkan SEMUA baris template lama yang kosong/dummy (sisakan hanya Header di index 0)
                for ($i = $rows5->length - 1; $i >= 1; $i--) {
                    $table5->removeChild($rows5->item($i));
                }

                // Jika ada data agenda, clone HANYA sebanyak data yang ada
                if ($agendas->isNotEmpty()) {
                    foreach ($agendas as $idx => $ag) {
                        $tglSelesai = $ag->tgl_penyelesaian
                            ? Carbon::parse($ag->tgl_penyelesaian)->translatedFormat('d F Y')
                            : '-';

                        $currentRow = $templateRow->cloneNode(true);
                        $table5->appendChild($currentRow);

                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 0), (string)($ag->nomor_agenda ?: ($idx + 1)));
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 1), $ag->agenda_item ?: '-');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 2), $ag->clause_iso ?: 'Clause 9.3');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 3), $ag->outcomes ?: '-');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 4), $ag->actions_output ?: '-');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 5), $ag->pic ?: '-');
                        $this->setCellStrictText($xpath, $dom, $this->getCellByIndex($xpath, $currentRow, 6), $tglSelesai);
                    }
                }
            }
        }

        // =========================================================================
        // 8. Header & Footer: Sesuai Instruksi Client
        // Halaman kedua dst TIDAK ADA header (hanya melanjutkan tabel dokumen).
        // =========================================================================
        // Hapus headerReference dan footerReference di section properties
        foreach ($xpath->query('//w:sectPr/w:headerReference') as $hRef) {
            $hRef->parentNode->removeChild($hRef);
        }
        foreach ($xpath->query('//w:sectPr/w:footerReference') as $fRef) {
            $fRef->parentNode->removeChild($fRef);
        }

        // Pastikan file header1.xml dan footer1.xml di dalam zip dikosongkan
        $emptyHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
        $emptyFooter = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
        
        $zip->addFromString('word/header1.xml', $emptyHeader);
        $zip->addFromString('word/footer1.xml', $emptyFooter);

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Helper mengambil Cell berdasarkan index secara aman.
     */
    private function getCellByIndex(DOMXPath $xpath, DOMElement $row, int $index): ?DOMElement
    {
        $cells = $xpath->query('w:tc', $row);
        if ($index < $cells->length) {
            return $cells->item($index);
        }
        return null;
    }

    /**
     * Helper mengisi text ke dalam sel TANPA merusak struktur <w:pPr> dan <w:rPr> bawaan template.
     * Ini menjamin font, ukuran, margin, spasi, dan alignment asli template 100% terjaga.
     */
    private function setCellStrictText(DOMXPath $xpath, DOMDocument $dom, ?DOMElement $cell, string $text): void
    {
        if (!$cell) {
            return;
        }

        // Ambil paragraf pertama di dalam cell
        $pList = $xpath->query('w:p', $cell);
        if ($pList->length > 0) {
            $p = $pList->item(0);
            // Hapus paragraf berlebih yang mungkin ada di cell tersebut
            for ($i = 1; $i < $pList->length; $i++) {
                $cell->removeChild($pList->item($i));
            }
        } else {
            $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
            $cell->appendChild($p);
        }

        // Ambil atau salin style font (<w:rPr>) dari run pertama yang ada
        $firstR = $xpath->query('w:r', $p)->item(0);
        $rPrClone = null;
        if ($firstR) {
            $rPr = $xpath->query('w:rPr', $firstR)->item(0);
            if ($rPr) {
                $rPrClone = $rPr->cloneNode(true);
            }
        }

        // Hapus semua run (<w:r>) lama di dalam paragraf tanpa menyentuh <w:pPr>
        foreach ($xpath->query('w:r', $p) as $r) {
            $p->removeChild($r);
        }

        // Masukkan baris teks. Jika multi-line, gunakan <w:br/> agar tidak membuat <w:p> baru yang merusak spasi
        $lines = explode("\n", $text);
        $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        if ($rPrClone) {
            $r->appendChild($rPrClone);
        }

        foreach ($lines as $lIdx => $line) {
            if ($lIdx > 0) {
                $br = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
                $r->appendChild($br);
            }
            $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->nodeValue = $line;
            $r->appendChild($t);
        }
        $p->appendChild($r);
    }

    /**
     * Helper replace text di dalam node run dengan mempertahankan run properties (<w:rPr>)
     */
    private function replaceTextInNodeStrict(DOMXPath $xpath, DOMElement $node, string $search, string $replace): void
    {
        // 1. Coba cari jika ada di single <w:t>
        $found = false;
        foreach ($xpath->query('.//w:t', $node) as $t) {
            if (str_contains($t->nodeValue, $search)) {
                $t->nodeValue = str_replace($search, $replace, $t->nodeValue);
                $found = true;
            }
        }

        // 2. Jika teks terpisah di beberapa run (Word XML chunking)
        if (!$found && str_contains($node->textContent, $search)) {
            $runs = $xpath->query('.//w:r', $node);
            if ($runs->length > 0) {
                $firstRun = $runs->item(0);
                $rPr = $xpath->query('w:rPr', $firstRun)->item(0);
                $rPrClone = $rPr ? $rPr->cloneNode(true) : null;

                $fullText = str_replace($search, $replace, $node->textContent);

                // Bersihkan semua runs
                foreach ($runs as $r) {
                    $node->removeChild($r);
                }

                $newR = $node->ownerDocument->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                if ($rPrClone) {
                    $newR->appendChild($rPrClone);
                }
                $newT = $node->ownerDocument->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $newT->setAttribute('xml:space', 'preserve');
                $newT->nodeValue = $fullText;
                $newR->appendChild($newT);
                $node->appendChild($newR);
            }
        }
    }
}
