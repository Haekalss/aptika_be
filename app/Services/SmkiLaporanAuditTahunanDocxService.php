<?php

namespace App\Services;

use App\Models\SmkiLaporanAuditTahunan;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use ZipArchive;

/**
 * Generator DOCX FR-008 (Formulir Tahunan Audit Internal) berbasis template resmi:
 * resources/templates/FR-008 Formulir Program Tahunan Audit Internal.docx
 * Tata letak, font, dan border template dipertahankan; hanya isi yang diganti/ditambah.
 */
class SmkiLaporanAuditTahunanDocxService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MIN_ROWS = 9;

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-008 Formulir Program Tahunan Audit Internal.docx'),
            storage_path('app/templates/FR-008 Formulir Program Tahunan Audit Internal.docx'),
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        throw new Exception('Template dokumen FR-008 tidak ditemukan di resources/templates.');
    }

    public function generate(SmkiLaporanAuditTahunan $laporan): string
    {
        $laporan->loadMissing(['timAuditor', 'objekAudit.klausul', 'objekAudit.jadwal']);

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $out = $tempDir . '/FR-008_' . $laporan->tahun_smki . '_' . uniqid() . '.docx';
        if (!copy(self::getTemplatePath(), $out)) {
            throw new Exception('Gagal menyalin template FR-008.');
        }

        $zip = new ZipArchive();
        if ($zip->open($out) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        // Header halaman 2 dst. (default header) — samakan nomor/judul dokumen.
        $hXml = $zip->getFromName('word/header1.xml');
        if ($hXml) {
            [$hDom, $hXp] = $this->load($hXml);
            $tbl = $hXp->query('//w:tbl')->item(0);
            if ($tbl) {
                $rows = $hXp->query('./w:tr', $tbl);
                $this->setCellByIndex($hXp, $rows, 0, 1, 'Formulir Tahunan Audit Internal');
                $this->setCellByIndex($hXp, $rows, 0, 2, 'No: ' . $laporan->no_dokumen);
                $this->setCellByIndex($hXp, $rows, 1, 2, 'Rev: ' . $laporan->no_revisi);
                $zip->addFromString('word/header1.xml', $hDom->saveXML());
            }
        }

        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('word/document.xml tidak ditemukan pada template FR-008.');
        }
        [$dom, $xp] = $this->load($xml);
        $this->fill($dom, $xp, $laporan);
        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $out;
    }

    private function load(string $xml): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        return [$dom, $xp];
    }

    private function tanggalIndo($value): string
    {
        return $value ? Carbon::parse($value)->locale('id')->translatedFormat('d F Y') : '';
    }

    private function text(DOMXPath $xp, \DOMNode $n): string
    {
        $t = '';
        foreach ($xp->query('.//w:t', $n) as $x) {
            $t .= $x->nodeValue;
        }
        return $t;
    }

    /** Ambil run bertemplate (klon) dari sebuah paragraf; bold dibuang bila diminta. */
    private function cloneRun(DOMXPath $xp, DOMElement $p, bool $stripBold = false): ?DOMElement
    {
        $run = $xp->query('./w:r[w:t]', $p)->item(0) ?? $xp->query('./w:r', $p)->item(0);
        if (!$run) {
            return null;
        }
        $c = $run->cloneNode(true);
        foreach (iterator_to_array($c->childNodes) as $child) {
            if ($child->nodeName !== 'w:rPr') {
                $c->removeChild($child);
            }
        }
        if ($stripBold) {
            foreach (['w:b', 'w:bCs'] as $tag) {
                foreach ($xp->query('./w:rPr/' . $tag, $c) as $b) {
                    $b->parentNode->removeChild($b);
                }
            }
        }
        return $c;
    }

    private function fillRun(DOMDocument $dom, DOMElement $run, string $text): void
    {
        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $run->appendChild($dom->createElementNS(self::W, 'w:br'));
            }
            $t = $dom->createElementNS(self::W, 'w:t');
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($line));
            $run->appendChild($t);
        }
    }

    /** Ganti seluruh isi paragraf dengan $text (format dari $tpl atau run pertama paragraf). */
    private function setPText(DOMDocument $dom, DOMXPath $xp, DOMElement $p, string $text, ?DOMElement $tpl = null, bool $stripBold = false): void
    {
        $base = $tpl ? $tpl->cloneNode(true) : $this->cloneRun($xp, $p, $stripBold);
        if (!$base) {
            $base = $dom->createElementNS(self::W, 'w:r');
        }
        foreach (iterator_to_array($xp->query('./w:r', $p)) as $r) {
            $p->removeChild($r);
        }
        $this->fillRun($dom, $base, $text);
        $p->appendChild($base);
    }

    /** "Label: nilai" — label mempertahankan format asli, nilai tanpa bold. */
    private function setLabelValue(DOMDocument $dom, DOMXPath $xp, DOMElement $p, string $label, string $value): void
    {
        $labelRun = $this->cloneRun($xp, $p);
        $valueRun = $this->cloneRun($xp, $p, true);
        foreach (iterator_to_array($xp->query('./w:r', $p)) as $r) {
            $p->removeChild($r);
        }
        if ($labelRun) {
            $this->fillRun($dom, $labelRun, $label);
            $p->appendChild($labelRun);
        }
        if ($valueRun) {
            $this->fillRun($dom, $valueRun, ' ' . $value);
            $p->appendChild($valueRun);
        }
    }

    private function centerP(DOMDocument $dom, DOMXPath $xp, DOMElement $p): void
    {
        $pPr = $xp->query('./w:pPr', $p)->item(0);
        if (!$pPr) {
            $pPr = $dom->createElementNS(self::W, 'w:pPr');
            $p->insertBefore($pPr, $p->firstChild);
        }
        foreach ($xp->query('./w:jc', $pPr) as $j) {
            $pPr->removeChild($j);
        }
        $jc = $dom->createElementNS(self::W, 'w:jc');
        $jc->setAttributeNS(self::W, 'w:val', 'center');
        $pPr->appendChild($jc);
    }

    private function setCellByIndex(DOMXPath $xp, $rows, int $r, int $c, string $text): void
    {
        $row = $rows->item($r);
        if (!$row) {
            return;
        }
        $cell = $xp->query('./w:tc', $row)->item($c);
        if (!$cell) {
            return;
        }
        $dom = $cell->ownerDocument;
        $p = $xp->query('./w:p', $cell)->item(0);
        if ($p) {
            $this->setPText($dom, $xp, $p, $text);
        }
    }

    private function fill(DOMDocument $dom, DOMXPath $xp, SmkiLaporanAuditTahunan $l): void
    {
        $outer = $xp->query('//w:body/w:tbl')->item(0);
        if (!$outer) {
            throw new Exception('Tabel utama FR-008 tidak ditemukan.');
        }

        // 1. Kop (Row 0..2, kolom ke-5 = nilai)
        $oRows = $xp->query('./w:tr', $outer);
        $refRun = null;
        $r1c4 = $xp->query('./w:tc', $oRows->item(1))->item(4);
        if ($r1c4) {
            $refP = $xp->query('./w:p', $r1c4)->item(0);
            $refRun = $refP ? $this->cloneRun($xp, $refP) : null;
        }
        $vals = [0 => $l->no_dokumen, 1 => $l->no_revisi, 2 => $this->tanggalIndo($l->tanggal_berlaku)];
        foreach ($vals as $ri => $v) {
            $cell = $xp->query('./w:tc', $oRows->item($ri))->item(4);
            $p = $cell ? $xp->query('./w:p', $cell)->item(0) : null;
            if ($p) {
                $this->setPText($dom, $xp, $p, (string) $v, $refRun);
            }
        }

        // 2. Tabel bersarang (isi formulir)
        $nested = $xp->query('.//w:tbl', $oRows->item(3))->item(0);
        if (!$nested) {
            throw new Exception('Tabel isi formulir FR-008 tidak ditemukan.');
        }
        $rows = iterator_to_array($xp->query('./w:tr', $nested));

        $headerIdx = null;
        $dataIdx = [];
        $sigLabelIdx = null;
        foreach ($rows as $i => $row) {
            $first = trim($this->text($xp, $xp->query('./w:tc', $row)->item(0)));
            if ($first === 'No') {
                $headerIdx = $i;
            } elseif ($headerIdx !== null && preg_match('/^\d+$/', $first)) {
                $dataIdx[] = $i;
            } elseif (str_starts_with($first, 'Disahkan')) {
                $sigLabelIdx = $i;
            }
        }
        if ($headerIdx === null || empty($dataIdx)) {
            throw new Exception('Struktur tabel jadwal FR-008 tidak sesuai template.');
        }

        // Template run baris data diambil sebelum baris dimodifikasi.
        $dataRow0 = $rows[$dataIdx[0]];
        $dataCells0 = $xp->query('./w:tc', $dataRow0);
        $tplData = $this->cloneRun($xp, $xp->query('./w:p', $dataCells0->item(0))->item(0), true);

        foreach ($rows as $i => $row) {
            if ($i >= $headerIdx) {
                break;
            }
            $cells = $xp->query('./w:tc', $row);
            $first = trim($this->text($xp, $cells->item(0)));
            $p0 = $xp->query('./w:p', $cells->item(0))->item(0);

            if (str_starts_with($first, 'Sistem Manajemen')) {
                $tp = $cells->length > 1 ? $xp->query('./w:p', $cells->item(1))->item(0) : null;
                if ($tp) {
                    $this->setLabelValue($dom, $xp, $tp, 'Tahun:', (string) $l->tahun_smki);
                }
            } elseif (str_starts_with($first, 'Kriteria Audit') || str_starts_with($first, 'Tujuan') || str_starts_with($first, 'Tim Auditor')) {
                // Gabungkan sel baris ini agar teks panjang tidak terpotong kolom sempit.
                $this->mergeRow($dom, $xp, $row, 15);
                if (str_starts_with($first, 'Kriteria Audit')) {
                    $this->setLabelValue($dom, $xp, $p0, 'Kriteria Audit:', (string) $l->kriteria_audit);
                } elseif (str_starts_with($first, 'Tujuan')) {
                    $this->setLabelValue($dom, $xp, $p0, 'Tujuan:', (string) $l->tujuan);
                }
            } elseif (str_starts_with($first, 'Lead Auditor')) {
                $this->fillAuditors($dom, $xp, $cells->item(0), $l);
            }
        }

        // 3. Baris data objek audit
        $objeks = $l->objekAudit->values();
        $total = max($objeks->count(), self::MIN_ROWS);
        $templateRow = $dataRow0->cloneNode(true);
        foreach ($dataIdx as $i) {
            $nested->removeChild($rows[$i]);
        }
        $anchor = $sigLabelIdx !== null ? $rows[$sigLabelIdx] : null;

        for ($n = 0; $n < $total; $n++) {
            $row = $templateRow->cloneNode(true);
            $cells = $xp->query('./w:tc', $row);
            $o = $objeks[$n] ?? null;

            $vals = [(string) ($n + 1)];
            if ($o) {
                $name = trim(($o->kode_objek ? $o->kode_objek . ' - ' : '') . $o->objek_audit . ($o->unit_kerja ? ' (' . $o->unit_kerja . ')' : ''));
                $vals[] = $name;
                $vals[] = $o->klausul->pluck('klausul_annex')->implode("\n");
                $months = $o->jadwal->pluck('bulan')->map(fn ($b) => (int) $b)->all();
                for ($m = 1; $m <= 12; $m++) {
                    $vals[] = in_array($m, $months, true) ? '√' : '';
                }
            }

            foreach ($vals as $ci => $v) {
                $cell = $cells->item($ci);
                $p = $cell ? $xp->query('./w:p', $cell)->item(0) : null;
                if (!$p) {
                    continue;
                }
                if ($v === '' && $ci > 0) {
                    foreach (iterator_to_array($xp->query('./w:r', $p)) as $r) {
                        $p->removeChild($r);
                    }
                    continue;
                }
                $this->setPText($dom, $xp, $p, $v, $tplData);
                if ($ci === 0 || $ci >= 3) {
                    $this->centerP($dom, $xp, $p);
                }
            }
            if (!$o) {
                // baris cadangan: kosongkan sel selain nomor
                for ($ci = 1; $ci < $cells->length; $ci++) {
                    $p = $xp->query('./w:p', $cells->item($ci))->item(0);
                    if ($p) {
                        foreach (iterator_to_array($xp->query('./w:r', $p)) as $r) {
                            $p->removeChild($r);
                        }
                    }
                }
                $first = $xp->query('./w:p', $cells->item(0))->item(0);
                if ($first) {
                    $this->setPText($dom, $xp, $first, (string) ($n + 1), $tplData);
                    $this->centerP($dom, $xp, $first);
                }
            }

            if ($anchor) {
                $nested->insertBefore($row, $anchor);
            } else {
                $nested->appendChild($row);
            }
        }

        // 4. Tanda tangan
        if ($sigLabelIdx !== null && isset($rows[$sigLabelIdx + 1])) {
            $sig = $rows[$sigLabelIdx + 1];
            $cells = $xp->query('./w:tc', $sig);
            $pairs = [
                0 => [$l->disahkan_oleh, $l->jabatan_pengesah],
                1 => [$l->disusun_oleh, $l->jabatan_penyusun],
            ];
            foreach ($pairs as $ci => [$nama, $jabatan]) {
                $cell = $cells->item($ci);
                if (!$cell) {
                    continue;
                }
                $paras = $xp->query('./w:p', $cell);
                foreach ($paras as $pi => $p) {
                    $t = trim($this->text($xp, $p));
                    if (str_contains($t, '(…')) {
                        $this->setPText($dom, $xp, $p, '(' . ($nama ?: '…………………………………….') . ')');
                    }
                }
                if ($jabatan && $paras->length > 0) {
                    $this->setPText($dom, $xp, $paras->item(0), (string) $jabatan, $tplData);
                    $this->centerP($dom, $xp, $paras->item(0));
                }
            }
        }
    }

    /** Gabungkan semua sel pada satu baris menjadi satu sel selebar $span kolom. */
    private function mergeRow(DOMDocument $dom, DOMXPath $xp, DOMElement $row, int $span): void
    {
        $cells = $xp->query('./w:tc', $row);
        if ($cells->length < 2) {
            return;
        }
        for ($i = $cells->length - 1; $i >= 1; $i--) {
            $row->removeChild($cells->item($i));
        }
        $first = $cells->item(0);
        $tcPr = $xp->query('./w:tcPr', $first)->item(0);
        if (!$tcPr) {
            $tcPr = $dom->createElementNS(self::W, 'w:tcPr');
            $first->insertBefore($tcPr, $first->firstChild);
        }
        foreach ($xp->query('./w:gridSpan|./w:tcW', $tcPr) as $old) {
            $tcPr->removeChild($old);
        }
        $gs = $dom->createElementNS(self::W, 'w:gridSpan');
        $gs->setAttributeNS(self::W, 'w:val', (string) $span);
        $tcPr->insertBefore($gs, $tcPr->firstChild);
    }

    /** Isi "Lead Auditor:" dan daftar "Auditor:" pada sel gabungan berparagraf banyak. */
    private function fillAuditors(DOMDocument $dom, DOMXPath $xp, DOMElement $cell, SmkiLaporanAuditTahunan $l): void
    {
        $paras = iterator_to_array($xp->query('./w:p', $cell));
        $leadP = null;
        $audP = null;
        foreach ($paras as $p) {
            $t = trim($this->text($xp, $p));
            if (str_starts_with($t, 'Lead Auditor')) {
                $leadP = $p;
            } elseif (str_starts_with($t, 'Auditor')) {
                $audP = $p;
            }
        }
        $tim = $l->timAuditor->values();
        $lead = $tim->firstWhere('peran', 'Lead Auditor');
        $others = $tim->filter(fn ($t) => $t->peran !== 'Lead Auditor')->values();

        $fmt = fn ($t) => $t->nama_auditor . ($t->unit_kerja && $t->unit_kerja !== '-' ? ' (' . $t->unit_kerja . ')' : '');

        if ($leadP) {
            $this->setLabelValue($dom, $xp, $leadP, 'Lead Auditor:', $lead ? $fmt($lead) : '');
        }
        if (!$audP) {
            return;
        }
        $tplRun = $this->cloneRun($xp, $audP, true);

        // Daftar auditor di paragraf kosong setelah "Auditor:" (tambah paragraf bila perlu).
        $empties = [];
        $seen = false;
        foreach ($paras as $p) {
            if ($p === $audP) {
                $seen = true;
                continue;
            }
            if ($seen && trim($this->text($xp, $p)) === '') {
                $empties[] = $p;
            }
        }
        $prev = $audP;
        foreach ($others as $i => $t) {
            $line = ($i + 1) . '. ' . $fmt($t);
            if (isset($empties[$i])) {
                $p = $empties[$i];
            } else {
                $p = $audP->cloneNode(false);
                $pPr = $xp->query('./w:pPr', $audP)->item(0);
                if ($pPr) {
                    $p->appendChild($pPr->cloneNode(true));
                }
                $prev->parentNode->insertBefore($p, $prev->nextSibling);
            }
            $this->setPText($dom, $xp, $p, $line, $tplRun);
            $prev = $p;
        }
    }
}
