<?php

namespace App\Services;

use App\Models\SmkiPemeriksaanCctv;
use DOMDocument;
use DOMXPath;
use Exception;
use ZipArchive;

class SmkiPemeriksaanCctvDocxService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-037 Formulir Checklist Monitoring CCTV.docx'),
            base_path('resources/templates/FR-037 Formulir Checklist Monitoring CCTV.docx'),
            storage_path('app/templates/FR-037 Formulir Checklist Monitoring CCTV.docx'),
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        throw new Exception('Template dokumen FR-037 Formulir Checklist Monitoring CCTV.docx tidak ditemukan di server.');
    }

    private function formatTanggalIndo($date): string
    {
        $ts = $date ? strtotime((string) $date) : false;
        if (!$ts) {
            return '';
        }
        return (int) date('j', $ts) . ' ' . self::MONTHS[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    /**
     * Generate dokumen FR-037 untuk satu sesi pemeriksaan.
     * Pilihan (Fungsi/Rekaman/Obstacle/Lingkup) ditulis sesuai yang terpilih saja.
     *
     * @param array $options ['no_dokumen', 'no_revisi', 'tanggal_berlaku'] (kosong = default template)
     */
    public function generateDocx(SmkiPemeriksaanCctv $pemeriksaan, array $options = []): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $outputPath = $tempDir . '/FR-037_generated_' . uniqid() . '.docx';
        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin file template FR-037.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak memiliki word/document.xml.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        @$dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        $table = $xpath->query('//w:body/w:tbl')->item(0);
        $rows = $table ? $xpath->query('w:tr', $table) : null;
        if (!$rows || $rows->length < 9) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-037 tidak valid.');
        }

        // Baris 0-2: kop (No. Dokumen / No. Revisi / Tanggal Berlaku) -> sel ke-5
        $kop = [
            0 => $options['no_dokumen'] ?? null,
            1 => $options['no_revisi'] ?? null,
            2 => $options['tanggal_berlaku'] ?? null,
        ];
        foreach ($kop as $i => $val) {
            if ($val !== null && trim((string) $val) !== '') {
                $this->setCellText($dom, $xpath, $rows->item($i), 4, trim((string) $val));
            }
        }

        // Baris 3-6: informasi pemeriksaan -> sel ke-3 (nilai)
        $info = [
            3 => $pemeriksaan->lingkup,
            4 => $pemeriksaan->perangkat_perekaman,
            5 => $this->formatTanggalIndo($pemeriksaan->tanggal_pemeriksaan),
            6 => $pemeriksaan->kesesuaian_waktu,
        ];
        foreach ($info as $i => $val) {
            $this->setCellText($dom, $xpath, $rows->item($i), 2, (string) $val, 'left');
        }

        // Baris 7 = judul kolom; baris 8..akhir = baris data kosong. Pakai baris terakhir sebagai acuan.
        $templateRow = $rows->item($rows->length - 1)->cloneNode(true);
        for ($i = $rows->length - 1; $i >= 8; $i--) {
            $table->removeChild($rows->item($i));
        }

        $no = 1;
        foreach ($pemeriksaan->items as $item) {
            $row = $templateRow->cloneNode(true);
            $this->preventRowSplit($dom, $xpath, $row);
            $cells = $xpath->query('w:tc', $row);
            $data = [
                (string) $no++,
                (string) $item->kode_cctv,
                (string) $item->nama_cctv,
                (string) $item->lokasi,
                (string) $item->fungsi,
                (string) $item->rekaman,
                (string) $item->obstacle,
                (string) ($item->keterangan ?? ''),
                (string) ($item->tindak_lanjut ?? ''),
                (string) ($item->petugas ?? ''),
            ];
            // Kolom teks rata kiri; Fungsi/Rekaman/Obstacle tetap rata tengah (mengikuti template)
            $aligns = ['left', 'left', 'left', 'left', null, null, null, 'left', 'left', 'left'];
            for ($c = 0; $c < count($data) && $c < $cells->length; $c++) {
                $this->writeCellText($dom, $xpath, $cells->item($c), $data[$c], $aligns[$c]);
            }
            $table->appendChild($row);
        }

        // Nama pemeriksa pada kolom tanda tangan: "(…………)" -> "(Nama)"
        $nama = trim((string) $pemeriksaan->nama_pemeriksa);
        if ($nama !== '') {
            // '//w:p' karena baris nama berada di dalam kotak teks di dasar halaman
            foreach ($xpath->query('//w:p') as $p) {
                $text = trim(preg_replace('/\s+/u', ' ', $p->textContent));
                if (preg_match('/^\(\s*[….\.]+\s*\)$/u', $text)) {
                    $this->setParagraphText($dom, $xpath, $p, ' (' . $nama . ')');
                    break;
                }
            }
        }

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    private function setCellText(DOMDocument $dom, DOMXPath $xpath, $row, int $cellIndex, string $text, ?string $align = null): void
    {
        $cells = $xpath->query('w:tc', $row);
        if ($cells->length > $cellIndex) {
            $this->writeCellText($dom, $xpath, $cells->item($cellIndex), $text, $align);
        }
    }

    private function writeCellText(DOMDocument $dom, DOMXPath $xpath, $cell, string $text, ?string $align = null): void
    {
        $p = $xpath->query('w:p', $cell)->item(0);
        if ($p) {
            $this->setParagraphText($dom, $xpath, $p, $text, $align);
        }
    }

    /**
     * Ganti isi paragraf dengan $text. Format font mengikuti run pertama; bila belum ada run,
     * mengikuti format default paragraf (pPr/rPr). "\n" dipertahankan sebagai <w:br/>.
     */
    private function setParagraphText(DOMDocument $dom, DOMXPath $xpath, $p, string $text, ?string $align = null): void
    {
        $rPr = null;
        $runs = $xpath->query('w:r', $p);
        if ($runs->length > 0) {
            $first = $xpath->query('w:rPr', $runs->item(0))->item(0);
            if ($first) {
                $rPr = $first->cloneNode(true);
            }
        }
        if (!$rPr) {
            $paraRPr = $xpath->query('w:pPr/w:rPr', $p)->item(0);
            if ($paraRPr) {
                $rPr = $paraRPr->cloneNode(true);
            }
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
                $pr = $xpath->query('w:rPr', $pPr)->item(0);
                $pr ? $pPr->insertBefore($jc, $pr) : $pPr->appendChild($jc);
            }
            $jc->setAttributeNS(self::W, 'w:val', $align);
        }

        // hapus run & penanda ejaan yang lama
        foreach (iterator_to_array($p->childNodes) as $child) {
            if (in_array($child->nodeName, ['w:r', 'w:proofErr', 'w:bookmarkStart', 'w:bookmarkEnd'], true)) {
                $p->removeChild($child);
            }
        }

        $run = $dom->createElementNS(self::W, 'w:r');
        if ($rPr) {
            $run->appendChild($rPr);
        }
        foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
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
     * Cegah satu baris terpotong di batas halaman.
     */
    private function preventRowSplit(DOMDocument $dom, DOMXPath $xpath, $row): void
    {
        $trPr = $xpath->query('w:trPr', $row)->item(0);
        if (!$trPr) {
            $trPr = $dom->createElementNS(self::W, 'w:trPr');
            $row->insertBefore($trPr, $row->firstChild);
        }
        if (!$xpath->query('w:cantSplit', $trPr)->length) {
            $trPr->insertBefore($dom->createElementNS(self::W, 'w:cantSplit'), $trPr->firstChild);
        }
    }
}
