<?php

namespace App\Services\Concerns;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Helper pengisian template .docx (WordprocessingML) lewat DOM, dipakai
 * service ekspor SMKI (FR-038, FR-039, FR-040, FR-045). Format run (font,
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

    /**
     * Skalakan semua tabel (lebar tabel, grid, dan sel) ke lebar area isi dan hilangkan indentasi.
     */
    private function fitTablesToWidth($xp, int $target, string $query = '//w:tbl'): void
    {
        foreach ($xp->query($query) as $tbl) {
            $tblW = $xp->query('w:tblPr/w:tblW', $tbl)->item(0);
            $cur = $tblW ? (int) $tblW->getAttributeNS(self::W_NS, 'w') : 0;
            if ($cur <= 0) {
                continue;
            }
            $f = $target / $cur;
            $tblW->setAttributeNS(self::W_NS, 'w:w', (string) $target);
            $ind = $xp->query('w:tblPr/w:tblInd', $tbl)->item(0);
            if ($ind) {
                $ind->setAttributeNS(self::W_NS, 'w:w', '0');
            }
            foreach ($xp->query('w:tblGrid/w:gridCol', $tbl) as $g) {
                $g->setAttributeNS(self::W_NS, 'w:w', (string) max(1, (int) round(((int) $g->getAttributeNS(self::W_NS, 'w')) * $f)));
            }
            foreach ($xp->query('w:tr/w:tc/w:tcPr/w:tcW', $tbl) as $w) {
                $w->setAttributeNS(self::W_NS, 'w:w', (string) max(1, (int) round(((int) $w->getAttributeNS(self::W_NS, 'w')) * $f)));
            }
        }
    }

    /**
     * Cache isi word/_rels/document.xml.rels per paket ZIP.
     *
     * ZipArchive::getFromName() mengembalikan false untuk entry yang sudah
     * ditulis lewat addFromString() pada sesi yang sama, sehingga relasi
     * gambar kedua dan seterusnya tidak pernah terdaftar. Isi rels karenanya
     * disimpan di sini dan dibaca dari cache.
     */
    private array $docxRelsCache = [];

    private function docxRels(\ZipArchive $zip): ?string
    {
        $key = spl_object_id($zip);
        if (array_key_exists($key, $this->docxRelsCache)) {
            return $this->docxRelsCache[$key];
        }

        $xml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($xml === false) {
            return null;
        }

        return $this->docxRelsCache[$key] = $xml;
    }

    private function putDocxRels(\ZipArchive $zip, string $xml): void
    {
        $this->docxRelsCache[spl_object_id($zip)] = $xml;
        $zip->addFromString('word/_rels/document.xml.rels', $xml);
    }

    /**
     * Sisipkan gambar (PNG) ke dalam paragraf .docx.
     *
     * Menambahkan file gambar ke word/media/, mendaftarkannya di
     * word/_rels/document.xml.rels, lalu menyisipkan elemen <w:drawing>
     * berisi gambar inline ke paragraf tujuan.
     *
     * @param  string      $imagePath  Path absolut file PNG
     * @param  DOMDocument $dom        Dokumen word/document.xml
     * @param  DOMNode     $paragraph  Paragraf <w:p> tempat gambar disisipkan
     * @param  int         $widthPx    Lebar tampilan (piksel @96dpi)
     * @return bool true bila berhasil
     */
    private function insertImageIntoParagraph(
        \ZipArchive $zip,
        DOMDocument $dom,
        DOMXPath $xp,
        DOMNode $paragraph,
        string $imagePath,
        int $widthPx = 300
    ): bool {
        if (!is_file($imagePath)) {
            return false;
        }

        $binary = file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            return false;
        }

        $size = @getimagesize($imagePath);
        if (!$size || $size[0] <= 0) {
            return false;
        }

        // Simpan gambar ke dalam paket docx
        static $seq = 0;
        $seq++;
        $mediaName = 'tte_' . substr(sha1($binary), 0, 10) . '_' . $seq . '.png';
        $zip->addFromString('word/media/' . $mediaName, $binary);

        // Daftarkan relasi gambar.
        //
        // CATATAN: setelah addFromString() pada entry yang sudah ada,
        // ZipArchive::getFromName() mengembalikan false untuk entry itu.
        // Karena itu isi rels disimpan di cache dan dibaca dari cache,
        // bukan dibaca ulang dari paket.
        $relsXml = $this->docxRels($zip);
        if ($relsXml === null || $relsXml === '') {
            return false;
        }

        $existingIds = [];
        if (preg_match_all('/Id="([^"]+)"/', $relsXml, $m)) {
            $existingIds = $m[1];
        }
        $n = 1;
        while (in_array('rIdTte' . $n, $existingIds, true)) {
            $n++;
        }
        $relId = 'rIdTte' . $n;

        $relXml = '<Relationship Id="' . $relId . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
            . ' Target="media/' . $mediaName . '"/>';

        if (strpos($relsXml, '</Relationships>') === false) {
            return false;
        }
        $relsXml = str_replace('</Relationships>', $relXml . '</Relationships>', $relsXml);
        $this->putDocxRels($zip, $relsXml);

        // Rasio asli gambar
        $cx = (int) round($widthPx * 9525);            // 1 px = 9525 EMU
        $cy = (int) round(($widthPx * $size[1] / $size[0]) * 9525);

        // Bangun run di dokumen sementara (namespace dideklarasikan di root),
        // lalu impor ke dokumen target — cara ini andal untuk DOMDocument.
        $tmp = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<root xmlns:w="' . self::W_NS . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . (1000 + $seq) . '" name="TTE ' . $seq . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . (2000 + $seq) . '" name="tte.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></root>';

        if (!@$tmp->loadXML($wrapped)) {
            return false;
        }

        $runNode = $tmp->documentElement->firstChild;
        if (!$runNode) {
            return false;
        }

        $imported = $dom->importNode($runNode, true);
        if (!$imported) {
            return false;
        }
        $paragraph->appendChild($imported);

        return true;
    }
}
