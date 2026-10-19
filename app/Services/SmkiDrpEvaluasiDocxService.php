<?php

namespace App\Services;

use App\Models\SmkiDrpEvaluasi;
use App\Services\Concerns\FillsDocxTemplate;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Exception;
use ZipArchive;

/**
 * Ekspor Formulir Laporan Evaluasi dan Pengujian DRP (FR-039/KOM.03.05/SANDIKAMI)
 * ke .docx dengan mengisi template asli "FR-039 Formulir Evaluasi Pengujian DRP.docx".
 *
 * Template asli (hasil ekspor Google Docs) memakai tabel dengan lebar berbeda-beda
 * (ada yang melebihi lebar kertas), sehingga tata letak dirapikan: A4 potret, margin kiri/kanan
 * 720 twip, dan semua tabel (termasuk tabel kop pada header) selebar area isi.
 */
class SmkiDrpEvaluasiDocxService
{
    use FillsDocxTemplate;

    /** Lebar area isi (twip): A4 11900 - margin kiri 720 - margin kanan 720. */
    private const CONTENT_WIDTH = 10460;

    /** Jumlah baris minimum tiap tabel (sesuai template). */
    private const MIN_WAKTU = 2;
    private const MIN_PROSES = 8;
    private const MIN_TIM = 5;

    /** Lebar kolom tabel waktu (4 kolom) dan tabel proses/team (5 kolom grid). */
    private const WIDTHS_WAKTU = [2800, 1900, 2000, 3760];
    private const WIDTHS_KOP = [1276, 4324, 1500, 300, 3060];
    private const WIDTHS_PROSES = [1900, 2700, 2200, 2400, 1260];

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-039 Formulir Evaluasi Pengujian DRP.docx'),
            base_path('resources/templates/FR-039 Formulir Evaluasi Pengujian DRP.docx'),
            storage_path('app/templates/FR-039 Formulir Evaluasi Pengujian DRP.docx'),
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-039 Formulir Evaluasi Pengujian DRP.docx tidak ditemukan di server.');
    }

    /**
     * @return string Path file .docx sementara (hapus setelah dikirim)
     */
    public function generateDocx(SmkiDrpEvaluasi $evaluasi): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $output = $tempDir . '/FR-039_generated_' . uniqid() . '.docx';
        if (!copy(self::getTemplatePath(), $output)) {
            throw new Exception('Gagal menyalin file template FR-039.');
        }

        $zip = new ZipArchive();
        if ($zip->open($output) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $dom = $this->loadPart($zip, 'word/document.xml');
        if (!$dom) {
            $zip->close();
            throw new Exception('Format dokumen tidak memiliki word/document.xml.');
        }
        $xp = $this->docxXPath($dom);

        // 0 info+waktu | 1 proses+team | 2 checklist | 3 tanda tangan | 4 lampiran
        $tables = $xp->query('//w:body/w:tbl');
        if ($tables->length < 5) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-039 tidak valid.');
        }

        $this->fillInfo($dom, $xp, $tables->item(0), $evaluasi);
        $this->fillWaktu($dom, $xp, $tables->item(0), $evaluasi);
        $this->fillProsesDanTim($dom, $xp, $tables->item(1), $evaluasi);
        $this->fillSignature($dom, $xp, $tables->item(3), $evaluasi);
        $this->fillLampiran($dom, $xp, $evaluasi);
        $this->applyLayout($xp, $tables);
        $zip->addFromString('word/document.xml', $dom->saveXML());

        // Tabel kop (header) disamakan lebarnya dengan tabel isi.
        $header = $this->loadPart($zip, 'word/header1.xml');
        if ($header) {
            $hx = $this->docxXPath($header);
            $this->fitWidth($hx, '//w:tbl');
            // Kolom label kop dilebarkan agar "No. Dokumen" tidak terpecah per huruf.
            $hTbl = $hx->query('//w:tbl')->item(0);
            if ($hTbl) {
                $this->setColumns($hx, $hTbl, self::WIDTHS_KOP);
            }
            $zip->addFromString('word/header1.xml', $header->saveXML());
        }

        $zip->close();

        return $output;
    }

    // ------------------------------------------------------------------
    // Bagian A: Pengujian DRP
    // ------------------------------------------------------------------

    private function waktuPengujian(SmkiDrpEvaluasi $e): string
    {
        $text = $this->tanggalIndo($e->tanggal_pengujian);
        $mulai = $e->waktu_mulai ? substr((string) $e->waktu_mulai, 0, 5) : '';
        $selesai = $e->waktu_selesai ? substr((string) $e->waktu_selesai, 0, 5) : '';
        if ($mulai !== '' && $selesai !== '') {
            $text .= ', ' . $mulai . ' - ' . $selesai . ' WIB';
        } elseif ($mulai !== '' || $selesai !== '') {
            $text .= ', ' . ($mulai ?: $selesai) . ' WIB';
        }

        return $text;
    }

    private function fillInfo(DOMDocument $dom, DOMXPath $xp, DOMNode $table, SmkiDrpEvaluasi $e): void
    {
        $map = [
            'Tanggal dan Waktu Pengujian'   => $this->waktuPengujian($e),
            'Tujuan Pengujian'              => (string) $e->tujuan,
            'Cakupan Pengujian'             => (string) $e->cakupan,
            'Tipe Pengujian'                => (string) $e->tipe_pengujian,
            'Asumsi Bencana'                => (string) $e->asumsi_bencana,
            'Asumsi Ketersediaan Sumber Daya' => (string) $e->asumsi_sumber_daya,
        ];

        foreach ($xp->query('w:tr', $table) as $tr) {
            $paras = $xp->query('w:tc/w:p', $tr);
            if ($paras->length < 2) {
                continue;
            }
            $label = trim(rtrim(trim($paras->item(0)->textContent), ':'));
            if (!array_key_exists($label, $map)) {
                continue;
            }
            // Paragraf ke-2 berisi placeholder "[Berisikan ...]" (biru, miring): ganti dengan isian
            // berformat teks biasa (Arial 11, hitam) dengan jarak baris seragam.
            $this->writeParagraph($dom, $xp, $paras->item(1), trim($map[$label]), $this->dataRPr($dom, 22), 276, 0);
        }
    }

    private function dataRPr(DOMDocument $dom, int $size = 20): DOMNode
    {
        $rPr = $dom->createElementNS(self::W_NS, 'w:rPr');
        $f = $dom->createElementNS(self::W_NS, 'w:rFonts');
        foreach (['ascii', 'cs', 'eastAsia', 'hAnsi'] as $a) {
            $f->setAttributeNS(self::W_NS, 'w:' . $a, 'Arial');
        }
        $rPr->appendChild($f);
        foreach (['sz', 'szCs'] as $tag) {
            $s = $dom->createElementNS(self::W_NS, 'w:' . $tag);
            $s->setAttributeNS(self::W_NS, 'w:val', (string) $size);
            $rPr->appendChild($s);
        }

        return $rPr;
    }

    private function fillWaktu(DOMDocument $dom, DOMXPath $xp, DOMNode $table, SmkiDrpEvaluasi $e): void
    {
        $rows = $xp->query('w:tr', $table);
        $headerIdx = null;
        foreach ($rows as $i => $tr) {
            if (trim($tr->textContent) !== '' && strpos($tr->textContent, 'Sistem TI') === 0) {
                $headerIdx = $i;
            }
        }
        if ($headerIdx === null) {
            throw new Exception('Tabel Persyaratan Waktu pada template FR-039 tidak ditemukan.');
        }

        $data = $e->waktu;
        $rowsData = $this->ensureDataRows($xp, $table, $headerIdx + 1, max(self::MIN_WAKTU, $data->count()));
        $rPr = $this->dataRPr($dom);
        foreach ($rowsData as $i => $tr) {
            $this->preventRowSplit($dom, $xp, $tr);
            $d = $data[$i] ?? null;
            $values = [
                $d ? (string) $d->sistem_ti : '',
                $d ? (string) $d->rto_ideal : '',
                $d ? (string) $d->pencapaian_rto : '',
                $d ? (string) $d->kendala : '',
            ];
            $this->writeRow($dom, $xp, $tr, $values, $rPr);
        }
    }

    // ------------------------------------------------------------------
    // Evaluasi - Proses DRP & Evaluasi - Team (satu tabel pada template)
    // ------------------------------------------------------------------

    private function fillProsesDanTim(DOMDocument $dom, DOMXPath $xp, DOMNode $table, SmkiDrpEvaluasi $e): void
    {
        $rows = $xp->query('w:tr', $table);
        $prosesHead = $teamTitle = $teamHead = null;
        foreach ($rows as $i => $tr) {
            $t = trim($tr->textContent);
            if (strpos($t, 'Proses') === 0 && strpos($t, 'Aktivitas') !== false) {
                $prosesHead = $i;
            } elseif (strpos($t, 'Evaluasi') === 0 && strpos($t, 'Team') !== false) {
                $teamTitle = $i;
            } elseif (strpos($t, 'Peran') === 0) {
                $teamHead = $i;
            }
        }
        if ($prosesHead === null || $teamTitle === null || $teamHead === null) {
            throw new Exception('Tabel Proses DRP / Team pada template FR-039 tidak ditemukan.');
        }

        $rowList = iterator_to_array($rows);
        $prosesRows = array_slice($rowList, $prosesHead + 1, $teamTitle - $prosesHead - 1);
        $timRows = array_slice($rowList, $teamHead + 1);

        // Baris data template dipakai sebagai acuan; sel gabungan vertikal (vMerge) dilepas
        // supaya tiap baris berdiri sendiri dan isinya mudah diatur.
        foreach (array_merge($prosesRows, $timRows) as $tr) {
            $this->normalizeDataRow($dom, $xp, $tr);
        }

        $proses = $e->proses;
        $needed = max(self::MIN_PROSES, $proses->count());
        $last = end($prosesRows);
        $anchor = $last->nextSibling; // baris judul "Evaluasi – Team"
        while (count($prosesRows) < $needed) {
            $clone = $last->cloneNode(true);
            $table->insertBefore($clone, $anchor);
            $prosesRows[] = $clone;
        }

        $tim = $e->tim;
        $needed = max(self::MIN_TIM, $tim->count());
        $lastTim = end($timRows);
        while (count($timRows) < $needed) {
            $clone = $lastTim->cloneNode(true);
            $table->appendChild($clone);
            $timRows[] = $clone;
        }

        $rPr = $this->dataRPr($dom);
        foreach ($prosesRows as $i => $tr) {
            $this->preventRowSplit($dom, $xp, $tr);
            $d = $proses[$i] ?? null;
            $this->writeRow($dom, $xp, $tr, [
                $d ? (string) $d->proses : '',
                $d ? (string) $d->aktivitas : '',
                $d ? (string) $d->hasil_evaluasi : '',
                $d ? (string) $d->rekomendasi : '',
                $d ? (string) $d->pic : '',
            ], $rPr);
        }
        foreach ($timRows as $i => $tr) {
            $this->preventRowSplit($dom, $xp, $tr);
            $d = $tim[$i] ?? null;
            $this->writeRow($dom, $xp, $tr, [
                $d ? (string) $d->peran : '',
                $d ? (string) $d->tanggung_jawab : '',
                $d ? (string) $d->evaluasi : '',
                $d ? (string) $d->rekomendasi : '',
            ], $rPr);
        }
    }

    /** Lepas vMerge, beri garis tepi penuh pada setiap sel, dan bersihkan format paragraf. */
    private function normalizeDataRow(DOMDocument $dom, DOMXPath $xp, DOMNode $tr): void
    {
        foreach ($xp->query('w:tc', $tr) as $tc) {
            $tcPr = $xp->query('w:tcPr', $tc)->item(0);
            if (!$tcPr) {
                continue;
            }
            foreach (iterator_to_array($xp->query('w:vMerge|w:vAlign|w:tcBorders', $tcPr)) as $n) {
                $tcPr->removeChild($n);
            }
            $borders = $dom->createElementNS(self::W_NS, 'w:tcBorders');
            foreach (['top', 'left', 'bottom', 'right'] as $side) {
                $b = $dom->createElementNS(self::W_NS, 'w:' . $side);
                $b->setAttributeNS(self::W_NS, 'w:val', 'single');
                $b->setAttributeNS(self::W_NS, 'w:sz', '4');
                $b->setAttributeNS(self::W_NS, 'w:space', '0');
                $b->setAttributeNS(self::W_NS, 'w:color', '000000');
                $borders->appendChild($b);
            }
            // urutan skema: gridSpan, vMerge, tcBorders, shd, ... -> setelah gridSpan/tcW
            $ref = null;
            foreach ($tcPr->childNodes as $c) {
                if (in_array($c->localName, ['shd', 'noWrap', 'tcMar', 'textDirection', 'tcFitText', 'vAlign', 'hideMark'], true)) {
                    $ref = $c;
                    break;
                }
            }
            $ref ? $tcPr->insertBefore($borders, $ref) : $tcPr->appendChild($borders);
        }
    }

    /**
     * Tulis nilai ke sel-sel baris: satu paragraf rata kiri dengan jarak atas/bawah kecil.
     */
    private function writeRow(DOMDocument $dom, DOMXPath $xp, DOMNode $tr, array $values, DOMNode $rPr): void
    {
        $cells = $xp->query('w:tc', $tr);
        foreach ($values as $c => $text) {
            if ($c >= $cells->length) {
                break;
            }
            $tc = $cells->item($c);
            $paras = $xp->query('w:p', $tc);
            for ($i = $paras->length - 1; $i >= 1; $i--) {
                $tc->removeChild($paras->item($i));
            }
            $p = $paras->item(0);
            $this->writeParagraph($dom, $xp, $p, $text, $rPr, 240, 40);
        }
    }

    /** Tulis ulang satu paragraf: tanpa indentasi, rata kiri, format run seragam. */
    private function writeParagraph(DOMDocument $dom, DOMXPath $xp, DOMNode $p, string $text, DOMNode $rPr, int $line, int $gap): void
    {
        $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');
        $sp = $dom->createElementNS(self::W_NS, 'w:spacing');
        $sp->setAttributeNS(self::W_NS, 'w:before', (string) $gap);
        $sp->setAttributeNS(self::W_NS, 'w:after', (string) $gap);
        $sp->setAttributeNS(self::W_NS, 'w:line', (string) $line);
        $sp->setAttributeNS(self::W_NS, 'w:lineRule', 'auto');
        $pPr->appendChild($sp);
        $jc = $dom->createElementNS(self::W_NS, 'w:jc');
        $jc->setAttributeNS(self::W_NS, 'w:val', 'left');
        $pPr->appendChild($jc);
        $pPr->appendChild($rPr->cloneNode(true));
        while ($p->firstChild) {
            $p->removeChild($p->firstChild);
        }
        $p->appendChild($pPr);
        $p->appendChild($this->makeRun($dom, trim($text), $rPr));
    }

    // ------------------------------------------------------------------
    // Tanda tangan & lampiran
    // ------------------------------------------------------------------

    private function fillSignature(DOMDocument $dom, DOMXPath $xp, DOMNode $table, SmkiDrpEvaluasi $e): void
    {
        $rows = $xp->query('w:tr', $table);
        if ($rows->length < 3) {
            throw new Exception('Tabel tanda tangan pada template FR-039 tidak valid.');
        }
        $people = [
            [$e->dibuat_oleh_nama, $e->dibuat_oleh_tanggal],
            [$e->diperiksa_oleh_nama, $e->diperiksa_oleh_tanggal],
            [$e->disetujui_oleh_nama, $e->disetujui_oleh_tanggal],
        ];
        $dateCells = $xp->query('w:tc', $rows->item(1));
        $signCells = $xp->query('w:tc', $rows->item(2));
        foreach ($people as $i => [$nama, $tanggal]) {
            if ($tanggal && $i < $dateCells->length) {
                $p = $xp->query('w:p', $dateCells->item($i))->item(0);
                if ($p) {
                    $this->setParagraphText($dom, $xp, $p, 'Tanggal: ' . $this->tanggalIndo($tanggal));
                }
            }
            $nama = trim((string) $nama);
            if ($nama !== '' && $i < $signCells->length) {
                $cell = $signCells->item($i);
                $tcPr = $xp->query('w:tcPr', $cell)->item(0);
                if ($tcPr && !$xp->query('w:vAlign', $tcPr)->length) {
                    $va = $dom->createElementNS(self::W_NS, 'w:vAlign');
                    $va->setAttributeNS(self::W_NS, 'w:val', 'bottom');
                    $tcPr->appendChild($va);
                }
                $p = $xp->query('w:p', $cell)->item(0);
                $rPr = $this->dataRPr($dom, 22);
                $this->setParagraphText($dom, $xp, $p, '(' . $nama . ')', 'center', $rPr);
            }
        }
    }

    /** Teks lampiran ditulis pada paragraf kosong di bawah judul "Lampiran Laporan Pengujian DRP". */
    private function fillLampiran(DOMDocument $dom, DOMXPath $xp, SmkiDrpEvaluasi $e): void
    {
        $text = trim((string) $e->lampiran);
        if ($text === '') {
            return;
        }
        foreach ($xp->query('//w:body/w:p') as $p) {
            if (strpos($p->textContent, 'Lampiran Laporan Pengujian DRP') === false) {
                continue;
            }
            $next = $p->nextSibling;
            while ($next && $next->nodeName === 'w:p' && trim($next->textContent) !== '') {
                $next = $next->nextSibling;
            }
            if ($next && $next->nodeName === 'w:p') {
                $this->setParagraphText($dom, $xp, $next, $text, 'left', $this->dataRPr($dom, 22));
            }
            break;
        }
    }

    // ------------------------------------------------------------------
    // Tata letak
    // ------------------------------------------------------------------

    private function applyLayout(DOMXPath $xp, $tables): void
    {
        $pgSz = $xp->query('//w:body/w:sectPr/w:pgSz')->item(0);
        if ($pgSz) {
            $pgSz->setAttributeNS(self::W_NS, 'w:w', '11900');
            $pgSz->setAttributeNS(self::W_NS, 'w:h', '16840');
        }
        $pgMar = $xp->query('//w:body/w:sectPr/w:pgMar')->item(0);
        if ($pgMar) {
            $pgMar->setAttributeNS(self::W_NS, 'w:left', '720');
            $pgMar->setAttributeNS(self::W_NS, 'w:right', '720');
        }

        $this->fitWidth($xp, '//w:body/w:tbl');
        $this->setColumns($xp, $tables->item(0), self::WIDTHS_WAKTU);
        $this->setColumns($xp, $tables->item(1), self::WIDTHS_PROSES);
    }

    /** Samakan semua tabel dengan lebar area isi (indentasi dibuang, tabel tengah). */
    private function fitWidth(DOMXPath $xp, string $query): void
    {
        $this->fitTablesToWidth($xp, self::CONTENT_WIDTH, $query);
        foreach ($xp->query($query) as $tbl) {
            foreach ($xp->query('w:tblGrid/w:tblGridChange', $tbl) as $chg) {
                $chg->parentNode->removeChild($chg);
            }
        }
    }

    /** Atur ulang lebar kolom grid + lebar sel (menghormati gridSpan). */
    private function setColumns(DOMXPath $xp, DOMNode $tbl, array $widths): void
    {
        foreach ($xp->query('w:tblGrid/w:gridCol', $tbl) as $i => $g) {
            if (isset($widths[$i])) {
                $g->setAttributeNS(self::W_NS, 'w:w', (string) $widths[$i]);
            }
        }
        foreach ($xp->query('w:tr', $tbl) as $tr) {
            $col = 0;
            foreach ($xp->query('w:tc', $tr) as $tc) {
                $span = $xp->query('w:tcPr/w:gridSpan', $tc)->item(0);
                $n = $span ? max(1, (int) $span->getAttributeNS(self::W_NS, 'val')) : 1;
                $w = $xp->query('w:tcPr/w:tcW', $tc)->item(0);
                if ($w) {
                    $w->setAttributeNS(self::W_NS, 'w:w', (string) array_sum(array_slice($widths, $col, $n)));
                }
                $col += $n;
            }
        }
    }
}
