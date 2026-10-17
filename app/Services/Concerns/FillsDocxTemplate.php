<?php

namespace App\Services\Concerns;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Helper pengisian template .docx (WordprocessingML) lewat DOM, dipakai
 * service ekspor FR-040 (Review Log) dan FR-045 (BYOD). Format run (font,
 * ukuran) selalu mengikuti template asli.
 */
trait FillsDocxTemplate
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    private const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private function docxXPath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W_NS);

        return $xp;
    }

    private function tanggalIndo($date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime((string) $date);
        if (!$ts) {
            return (string) $date;
        }

        return (int) date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    /** Tanggal ringkas gaya kop dokumen, mis. 07-Jul-2022. */
    private function tanggalRingkas($date): string
    {
        if (!$date) {
            return '';
        }
        $ts = strtotime((string) $date);
        if (!$ts) {
            return (string) $date;
        }
        $short = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return date('d', $ts) . '-' . $short[(int) date('n', $ts) - 1] . '-' . date('Y', $ts);
    }

    private function loadPart(\ZipArchive $zip, string $name): ?DOMDocument
    {
        $xml = $zip->getFromName($name);
        if ($xml === false) {
            return null;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        @$dom->loadXML($xml);

        return $dom;
    }

    /** Salin <w:rPr> dari run pertama paragraf (atau pPr/rPr bila tidak ada run). */
    private function paragraphRPr(DOMXPath $xp, DOMNode $p, bool $last = false): ?DOMNode
    {
        $runs = $xp->query('w:r[w:t]', $p);
        if ($runs->length === 0) {
            $runs = $xp->query('w:r', $p);
        }
        if ($runs->length > 0) {
            $run = $runs->item($last ? $runs->length - 1 : 0);
            $r = $xp->query('w:rPr', $run)->item(0);
            if ($r) {
                return $r->cloneNode(true);
            }
        }
        $r = $xp->query('w:pPr/w:rPr', $p)->item(0);

        return $r ? $r->cloneNode(true) : null;
    }

    private function makeRun(DOMDocument $dom, string $text, ?DOMNode $rPr = null, bool $bold = false): DOMElement
    {
        $run = $dom->createElementNS(self::W_NS, 'w:r');
        if ($rPr) {
            $rPr = $rPr->cloneNode(true);
            if ($bold && !$this->hasChild($rPr, 'b')) {
                $rPr->insertBefore($dom->createElementNS(self::W_NS, 'w:b'), $rPr->firstChild);
            }
            $run->appendChild($rPr);
        }
        foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
            if ($i > 0) {
                $run->appendChild($dom->createElementNS(self::W_NS, 'w:br'));
            }
            $t = $dom->createElementNS(self::W_NS, 'w:t');
            $t->appendChild($dom->createTextNode($line));
            $t->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
            $run->appendChild($t);
        }

        return $run;
    }

    private function hasChild(DOMNode $node, string $local): bool
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $local) {
                return true;
            }
        }

        return false;
    }

    /** Hapus semua run paragraf lalu tulis ulang sebagai satu run (format = run acuan). */
    private function setParagraphText(DOMDocument $dom, DOMXPath $xp, DOMNode $p, string $text, ?string $align = null, ?DOMNode $rPr = null): void
    {
        $rPr = $rPr ?: $this->paragraphRPr($xp, $p);
        foreach (iterator_to_array($xp->query('w:r|w:hyperlink|w:proofErr|w:bookmarkStart|w:bookmarkEnd', $p)) as $n) {
            $p->removeChild($n);
        }
        if ($align) {
            $this->setAlign($dom, $xp, $p, $align);
        }
        $p->appendChild($this->makeRun($dom, $text, $rPr));
    }

    /** Tambah run di akhir paragraf (mis. nilai setelah "Label :"). */
    private function appendToParagraph(DOMDocument $dom, DOMXPath $xp, DOMNode $p, string $text): void
    {
        $p->appendChild($this->makeRun($dom, $text, $this->paragraphRPr($xp, $p)));
    }

    private function setAlign(DOMDocument $dom, DOMXPath $xp, DOMNode $p, string $align): void
    {
        $pPr = $xp->query('w:pPr', $p)->item(0);
        if (!$pPr) {
            $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');
            $p->insertBefore($pPr, $p->firstChild);
        }
        $jc = $xp->query('w:jc', $pPr)->item(0);
        if (!$jc) {
            $jc = $dom->createElementNS(self::W_NS, 'w:jc');
            $rp = $xp->query('w:rPr', $pPr)->item(0);
            $rp ? $pPr->insertBefore($jc, $rp) : $pPr->appendChild($jc);
        }
        $jc->setAttributeNS(self::W_NS, 'w:val', $align);
    }

    /** Isi sel tabel: paragraf pertama diisi teks, paragraf lain dibuang. */
    private function setCellText(DOMDocument $dom, DOMXPath $xp, DOMNode $cell, string $text, ?string $align = null, ?DOMNode $rPr = null): void
    {
        $paras = $xp->query('w:p', $cell);
        if ($paras->length === 0) {
            return;
        }
        $this->setParagraphText($dom, $xp, $paras->item(0), $text, $align, $rPr);
        for ($i = $paras->length - 1; $i >= 1; $i--) {
            $cell->removeChild($paras->item($i));
        }
    }

    private function preventRowSplit(DOMDocument $dom, DOMXPath $xp, DOMNode $row): void
    {
        $trPr = $xp->query('w:trPr', $row)->item(0);
        if (!$trPr) {
            $trPr = $dom->createElementNS(self::W_NS, 'w:trPr');
            $first = null;
            foreach ($row->childNodes as $child) {
                if ($child->nodeName !== 'w:tblPrEx') {
                    $first = $child;
                    break;
                }
            }
            $first ? $row->insertBefore($trPr, $first) : $row->appendChild($trPr);
        }
        if (!$xp->query('w:cantSplit', $trPr)->length) {
            $trPr->insertBefore($dom->createElementNS(self::W_NS, 'w:cantSplit'), $trPr->firstChild);
        }
    }

    /**
     * Sesuaikan jumlah baris data tabel: baris acuan = baris data terakhir.
     * Baris template yang kosong dipakai dulu; bila data lebih banyak, baris acuan digandakan.
     *
     * @return DOMNode[] baris data yang siap diisi (sejumlah $needed)
     */
    private function ensureDataRows(DOMXPath $xp, DOMNode $table, int $firstDataRow, int $needed): array
    {
        $rows = $xp->query('w:tr', $table);
        $template = $rows->item($rows->length - 1);
        $existing = [];
        for ($i = $firstDataRow; $i < $rows->length; $i++) {
            $existing[] = $rows->item($i);
        }
        while (count($existing) < $needed) {
            $clone = $template->cloneNode(true);
            $table->appendChild($clone);
            $existing[] = $clone;
        }

        return $existing;
    }
}
