<?php

namespace App\Services;

use App\Models\SmkiByodForm;
use App\Services\Concerns\FillsDocxTemplate;
use DOMDocument;
use Exception;
use ZipArchive;

/**
 * Ekspor Formulir BYOD / Penggunaan Perangkat Pribadi (FR-045/KOM.03/05/SANDIKAMI)
 * ke .docx dengan mengisi template asli "FR-045 Formulir BYOD.docx".
 */
class SmkiByodDocxService
{
    use FillsDocxTemplate;

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-045 Formulir BYOD.docx'),
            base_path('resources/templates/FR-045 Formulir BYOD.docx'),
            storage_path('app/templates/FR-045 Formulir BYOD.docx'),
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-045 Formulir BYOD.docx tidak ditemukan di server.');
    }

    /**
     * @return string Path file .docx sementara (hapus setelah dikirim)
     */
    public function generateDocx(SmkiByodForm $form): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $output = $tempDir . '/FR-045_generated_' . uniqid() . '.docx';
        if (!copy(self::getTemplatePath(), $output)) {
            throw new Exception('Gagal menyalin file template FR-045.');
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

        $tables = $xp->query('//w:body/w:tbl');
        if ($tables->length < 2) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-045 tidak valid.');
        }

        $this->fillIdentity($dom, $xp, $tables->item(0), $form);
        $this->fillUnitKerjaSentence($xp, $form);
        $this->fillDevices($dom, $xp, $tables->item(1), $form);
        $this->fillSignatureDate($xp, $form);
        $zip->addFromString('word/document.xml', $dom->saveXML());

        // Footer template memakai " of 1" statis; ganti dengan field NUMPAGES agar benar bila >1 halaman.
        $footer = $this->loadPart($zip, 'word/footer1.xml');
        if ($footer) {
            $this->useNumPagesField($footer, $this->docxXPath($footer));
            $zip->addFromString('word/footer1.xml', $footer->saveXML());
        }

        $zip->close();

        return $output;
    }

    private function fillIdentity($dom, $xp, $table, SmkiByodForm $form): void
    {
        $rows = $xp->query('w:tr', $table);
        $values = [
            0 => (string) $form->nama,
            1 => (string) $form->nip,
            2 => (string) $form->jabatan,
            3 => (string) $form->unit_kerja,
        ];
        foreach ($values as $i => $value) {
            if ($i >= $rows->length || trim($value) === '') {
                continue;
            }
            $cells = $xp->query('w:tc', $rows->item($i));
            if ($cells->length < 2) {
                continue;
            }
            $labelP = $xp->query('w:p', $cells->item(0))->item(0);
            $rPr = $labelP ? $this->paragraphRPr($xp, $labelP) : null; // Calibri 12 seperti label
            $cell = $cells->item(1);

            if ($i === 2) {
                // Template: "Kepala " biasa + "[Unit Kerja]" tebal.
                $p = $xp->query('w:p', $cell)->item(0);
                foreach (iterator_to_array($xp->query('w:r', $p)) as $r) {
                    $p->removeChild($r);
                }
                if (preg_match('/^(Kepala )(.+)$/u', trim($value), $m)) {
                    $p->appendChild($this->makeRun($dom, $m[1], $rPr));
                    $p->appendChild($this->makeRun($dom, $m[2], $rPr, true));
                } else {
                    $p->appendChild($this->makeRun($dom, trim($value), $rPr));
                }
            } else {
                $this->setCellText($dom, $xp, $cell, trim($value), null, $rPr);
            }
        }
    }

    /** "…di [Unit Kerja] adalah sebagai berikut:" */
    private function fillUnitKerjaSentence($xp, SmkiByodForm $form): void
    {
        $unit = trim((string) $form->unit_kerja);
        if ($unit === '') {
            return;
        }
        foreach ($xp->query('//w:body/w:p//w:t') as $t) {
            if (strpos($t->nodeValue, '[Unit Kerja]') !== false) {
                $t->nodeValue = str_replace('[Unit Kerja]', $unit, $t->nodeValue);
            }
        }
    }

    private function fillDevices($dom, $xp, $table, SmkiByodForm $form): void
    {
        $devices = $form->perangkat;
        $rows = $this->ensureDataRows($xp, $table, 1, max(5, $devices->count()));

        $numP = $xp->query('w:tc[1]/w:p', $rows[0])->item(0);
        $rPr = $numP ? $this->paragraphRPr($xp, $numP) : null;

        foreach ($devices as $i => $d) {
            $tr = $rows[$i];
            $this->preventRowSplit($dom, $xp, $tr);
            $cells = $xp->query('w:tc', $tr);
            $data = [
                (string) ($i + 1),
                (string) $d->nama,
                (string) ($d->nip ?? ''),
                (string) $d->jenis_perangkat,
                (string) $d->merek_tipe,
                (string) $d->nomor_seri,
            ];
            foreach ($data as $c => $text) {
                if ($c < $cells->length) {
                    $this->setCellText($dom, $xp, $cells->item($c), $text, $c === 0 ? 'left' : null, $rPr);
                }
            }
        }
        // Baris tambahan hasil penggandaan tetap bernomor bila tidak terpakai
        foreach ($rows as $i => $tr) {
            if ($i >= $devices->count()) {
                $cell = $xp->query('w:tc', $tr)->item(0);
                $this->setCellText($dom, $xp, $cell, (string) ($i + 1), 'left', $rPr);
            }
        }
    }

    /** "Bandung, Tanggal Bulan Tahun" -> "Kota, 17 Oktober 2026" */
    private function fillSignatureDate($xp, SmkiByodForm $form): void
    {
        $di = trim((string) $form->ditandatangani_di);
        $tanggal = $this->tanggalIndo($form->tanggal_ttd);
        foreach ($xp->query('//w:body/w:p') as $p) {
            if (strpos($p->textContent, 'Bandung, ') === false || strpos($p->textContent, 'Tahun') === false) {
                continue;
            }
            $seenTanggal = false;
            foreach ($xp->query('.//w:t', $p) as $t) {
                $v = $t->nodeValue;
                if ($v === 'Bandung, ' && $di !== '') {
                    $t->nodeValue = $di . ', ';
                } elseif ($tanggal !== '' && $v === 'Tanggal Bulan') {
                    $t->nodeValue = $tanggal;
                    $seenTanggal = true;
                } elseif ($tanggal !== '' && $seenTanggal && ($v === ' ' || $v === 'Tahun')) {
                    $t->nodeValue = '';
                }
            }
            break;
        }
    }

    private function useNumPagesField(DOMDocument $dom, $xp): void
    {
        foreach ($xp->query('//w:t') as $t) {
            if ($t->nodeValue !== ' of 1') {
                continue;
            }
            $run = $t->parentNode;
            $t->nodeValue = ' of ';
            $rPr = $xp->query('w:rPr', $run)->item(0);
            $after = $run->nextSibling;
            $parent = $run->parentNode;
            $mk = function (string $kind, ?string $text = null) use ($dom, $rPr) {
                $r = $dom->createElementNS(self::W_NS, 'w:r');
                if ($rPr) {
                    $r->appendChild($rPr->cloneNode(true));
                }
                if ($kind === 'instr') {
                    $i = $dom->createElementNS(self::W_NS, 'w:instrText');
                    $i->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
                    $i->appendChild($dom->createTextNode(' NUMPAGES '));
                    $r->appendChild($i);
                } elseif ($kind === 'text') {
                    $tt = $dom->createElementNS(self::W_NS, 'w:t');
                    $tt->appendChild($dom->createTextNode($text));
                    $r->appendChild($tt);
                } else {
                    $f = $dom->createElementNS(self::W_NS, 'w:fldChar');
                    $f->setAttributeNS(self::W_NS, 'w:fldCharType', $kind);
                    $r->appendChild($f);
                }

                return $r;
            };
            foreach ([$mk('begin'), $mk('instr'), $mk('separate'), $mk('text', '1'), $mk('end')] as $n) {
                $parent->insertBefore($n, $after);
            }
            break;
        }
    }
}
