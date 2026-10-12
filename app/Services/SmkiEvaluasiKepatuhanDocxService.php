<?php

namespace App\Services;

use App\Models\SmkiEvaluasiKepatuhan;
use DOMDocument;
use DOMXPath;
use Exception;
use ZipArchive;

class SmkiEvaluasiKepatuhanDocxService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /**
     * Path template dokumen FR-044.
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            resource_path('templates/FR-044 Formulir Evaluasi Kepatuhan.docx'),
            base_path('resources/templates/FR-044 Formulir Evaluasi Kepatuhan.docx'),
            storage_path('app/templates/FR-044 Formulir Evaluasi Kepatuhan.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-044 Formulir Evaluasi Kepatuhan.docx tidak ditemukan di server.');
    }

    private function formatTanggalIndo($date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime((string) $date);
        if (!$ts) {
            return (string) $date;
        }
        return (int) date('j', $ts) . ' ' . self::MONTHS[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    /**
     * Generate dokumen FR-044 untuk satu sesi evaluasi.
     *
     * @param array $options ['no_dokumen', 'no_revisi', 'tanggal_berlaku'] (kosong = pakai default template)
     * @return string Path file temporary .docx
     */
    public function generateDocx(SmkiEvaluasiKepatuhan $evaluasi, array $options = []): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $outputPath = $tempDir . '/FR-044_generated_' . uniqid() . '.docx';
        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin file template FR-044.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $headerValues = [
            0 => $options['no_dokumen'] ?? null,
            1 => $options['no_revisi'] ?? null,
            2 => $options['tanggal_berlaku'] ?? null,
        ];

        // ---------------------------------------------------------
        // 1. Header halaman 2 dst (word/header1.xml)
        //    Kop halaman pertama berada di body (lihat bagian 2).
        // ---------------------------------------------------------
        foreach (['word/header1.xml', 'word/header2.xml'] as $headerPart) {
            $hXml = $zip->getFromName($headerPart);
            if (!$hXml) {
                continue;
            }
            $hDom = new DOMDocument();
            $hDom->preserveWhiteSpace = true;
            @$hDom->loadXML($hXml);
            $hXp = $this->xpath($hDom);

            $hTable = $hXp->query('//w:tbl')->item(0);
            if ($hTable) {
                $this->fillKopTable($hDom, $hXp, $hTable, $headerValues);
                $zip->addFromString($headerPart, $hDom->saveXML());
            }
        }

        // ---------------------------------------------------------
        // 2. Isi dokumen
        // ---------------------------------------------------------
        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak memiliki word/document.xml.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);
        $xpath = $this->xpath($dom);

        $bodyTables = $xpath->query('//w:body/w:tbl');
        if ($bodyTables->length >= 2) {
            // Template baru: tabel pertama = kop halaman 1, tabel kedua = rincian
            $this->fillKopTable($dom, $xpath, $bodyTables->item(0), $headerValues);
            $table = $bodyTables->item(1);
        } else {
            $table = $bodyTables->item(0);
        }

        // Tabel rincian: baris 0 = judul kolom, baris >= 1 = baris kosong (tinggi bervariasi)
        if (!$table) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-044 tidak valid.');
        }
        $rows = $xpath->query('w:tr', $table);
        if ($rows->length < 2) {
            $zip->close();
            throw new Exception('Template FR-044 tidak memiliki baris data acuan.');
        }

        // Pakai baris terakhir (tanpa tinggi tetap) sebagai acuan
        $templateRow = $rows->item($rows->length - 1)->cloneNode(true);
        foreach ($xpath->query('w:trPr/w:trHeight', $templateRow) as $h) {
            $h->parentNode->removeChild($h);
        }
        for ($i = $rows->length - 1; $i >= 1; $i--) {
            $table->removeChild($rows->item($i));
        }

        $aligns = ['center', 'left', 'left', 'center'];
        $no = 1;
        foreach ($evaluasi->items as $item) {
            $newRow = $templateRow->cloneNode(true);
            $this->preventRowSplit($dom, $xpath, $newRow);
            $cells = $xpath->query('w:tc', $newRow);
            $data = [
                (string) $no++,
                (string) $item->dokumen_kepatuhan,
                (string) $item->deskripsi,
                (string) $item->evaluasi_status,
            ];
            for ($c = 0; $c < count($data) && $c < $cells->length; $c++) {
                $this->writeCellText($dom, $xpath, $cells->item($c), $data[$c], $aligns[$c]);
                $this->padCell($dom, $xpath, $cells->item($c));
            }
            $table->appendChild($newRow);
        }

        // Ditandatangani di / Tanggal
        $this->fillSignatureLine($dom, $xpath, 'Ditandatangani di', (string) ($evaluasi->ditandatangani_di ?? ''));
        $this->fillSignatureLine($dom, $xpath, 'Tanggal', $this->formatTanggalIndo($evaluasi->tanggal_ttd));

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        return $xp;
    }

    /**
     * Isi baris "Label   : " dengan nilai (ditambahkan setelah titik dua).
     * Dicocokkan lewat teks gabungan paragraf agar tahan terhadap run yang terpecah.
     */
    private function fillSignatureLine(DOMDocument $dom, DOMXPath $xpath, string $label, string $value): void
    {
        $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*$/u';

        // '//w:p' (bukan hanya anak langsung body) karena baris tanda tangan ada di dalam kotak teks
        foreach ($xpath->query('//w:p') as $p) {
            $text = trim(preg_replace('/\s+/u', ' ', $p->textContent));
            if (!preg_match($pattern, $text)) {
                continue;
            }

            if ($value === '') {
                return;
            }
            $tNodes = $xpath->query('.//w:t', $p);
            if ($tNodes->length === 0) {
                return;
            }
            $last = $tNodes->item($tNodes->length - 1);
            $last->nodeValue = rtrim($last->nodeValue) . ' ' . $value;
            $last->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            return;
        }
    }

    /**
     * Tulis teks ke dalam cell. Format font mengikuti run pertama; bila cell kosong
     * (belum punya run) mengikuti format default paragraf (pPr/rPr).
     * Baris baru ("\n") dipertahankan sebagai <w:br/>.
     */
    private function writeCellText(DOMDocument $dom, DOMXPath $xpath, $cell, string $text, ?string $align = null): void
    {
        $paragraphs = $xpath->query('w:p', $cell);
        if ($paragraphs->length === 0) {
            return;
        }
        $p = $paragraphs->item(0);

        $rPr = null;
        $runs = $xpath->query('w:r', $p);
        if ($runs->length > 0) {
            $existing = $xpath->query('w:rPr', $runs->item(0))->item(0);
            if ($existing) {
                $rPr = $existing->cloneNode(true);
            }
        }
        if (!$rPr) {
            $paraRPr = $xpath->query('w:pPr/w:rPr', $p)->item(0);
            if ($paraRPr) {
                $rPr = $paraRPr->cloneNode(true);
            }
        }

        foreach (iterator_to_array($runs) as $run) {
            $p->removeChild($run);
        }

        if ($align) {
            $pPr = $xpath->query('w:pPr', $p)->item(0);
            if (!$pPr) {
                $pPr = $dom->createElementNS(self::W, 'w:pPr');
                $p->insertBefore($pPr, $p->firstChild);
            }
            $jc = $xpath->query('w:jc', $pPr)->item(0);
            if (!$jc) {
                $jc = $dom->createElementNS(self::W, 'w:jc');
                $paraRPrNode = $xpath->query('w:rPr', $pPr)->item(0);
                $paraRPrNode ? $pPr->insertBefore($jc, $paraRPrNode) : $pPr->appendChild($jc);
            }
            $jc->setAttributeNS(self::W, 'w:val', $align);
        }

        $run = $dom->createElementNS(self::W, 'w:r');
        if ($rPr) {
            $run->appendChild($rPr);
        }

        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $run->appendChild($dom->createElementNS(self::W, 'w:br'));
            }
            $t = $dom->createElementNS(self::W, 'w:t');
            $t->appendChild($dom->createTextNode($line));
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $run->appendChild($t);
        }
        $p->appendChild($run);
    }

    /**
     * Isi nilai No. Dokumen / No. Revisi / Tanggal Berlaku pada tabel kop.
     * Nilai kosong = pakai default template.
     */
    private function fillKopTable(DOMDocument $dom, DOMXPath $xpath, $table, array $values): void
    {
        $rows = $xpath->query('w:tr', $table);
        foreach ($values as $rowIdx => $val) {
            if ($val === null || trim((string) $val) === '' || $rows->length <= $rowIdx) {
                continue;
            }
            $cells = $xpath->query('w:tc', $rows->item($rowIdx));
            if ($cells->length > 4) {
                $this->writeCellText($dom, $xpath, $cells->item(4), trim((string) $val));
            }
        }
    }

    /**
     * Beri jarak dalam sel (atas/bawah/kiri/kanan) dan rata atas agar baris tidak terlalu rapat.
     */
    private function padCell(DOMDocument $dom, DOMXPath $xpath, $cell): void
    {
        $tcPr = $xpath->query('w:tcPr', $cell)->item(0);
        if (!$tcPr) {
            $tcPr = $dom->createElementNS(self::W, 'w:tcPr');
            $cell->insertBefore($tcPr, $cell->firstChild);
        }
        foreach (['w:tcMar', 'w:vAlign'] as $tag) {
            foreach (iterator_to_array($xpath->query($tag, $tcPr)) as $old) {
                $tcPr->removeChild($old);
            }
        }
        $mar = $dom->createElementNS(self::W, 'w:tcMar');
        foreach (['top' => 70, 'left' => 100, 'bottom' => 70, 'right' => 100] as $side => $w) {
            $e = $dom->createElementNS(self::W, 'w:' . $side);
            $e->setAttributeNS(self::W, 'w:w', (string) $w);
            $e->setAttributeNS(self::W, 'w:type', 'dxa');
            $mar->appendChild($e);
        }
        $tcPr->appendChild($mar);
        $va = $dom->createElementNS(self::W, 'w:vAlign');
        $va->setAttributeNS(self::W, 'w:val', 'top');
        $tcPr->appendChild($va);
    }

    /**
     * Cegah satu baris terpotong di batas halaman.
     */
    private function preventRowSplit(DOMDocument $dom, DOMXPath $xpath, $row): void
    {
        $trPr = $xpath->query('w:trPr', $row)->item(0);
        if (!$trPr) {
            $trPr = $dom->createElementNS(self::W, 'w:trPr');
            $first = null;
            foreach ($row->childNodes as $child) {
                if ($child->nodeName !== 'w:tblPrEx') {
                    $first = $child;
                    break;
                }
            }
            $first ? $row->insertBefore($trPr, $first) : $row->appendChild($trPr);
        }
        if (!$xpath->query('w:cantSplit', $trPr)->length) {
            $trPr->insertBefore($dom->createElementNS(self::W, 'w:cantSplit'), $trPr->firstChild);
        }
    }
}
