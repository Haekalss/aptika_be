<?php

namespace App\Services\Concerns;

use App\Services\TteResolverService;
use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Menyisipkan gambar spesimen TTE (PNG) ke dokumen .docx berbasis template.
 *
 * Dipakai service ekspor SMKI yang belum memakai FillsDocxTemplate
 * (FR-011, FR-037, FR-039, FR-044). Nama method sengaja unik agar tidak
 * bertabrakan dengan helper yang sudah ada di masing-masing service.
 */
trait InsertsTteImage
{
    private const TTE_W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Path file PNG spesimen untuk NIP/nama, atau null bila tidak ada. */
    private function resolveTtePath(?string $nip, ?string $nama): ?string
    {
        $nip = trim((string) $nip);
        $nama = trim((string) $nama);
        if ($nip === '' && $nama === '') {
            return null;
        }

        try {
            return app(TteResolverService::class)->imagePath($nip, $nama);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Paragraf kosong berisi gambar (wadah gambar TTE). */
    private function tteImageParagraph(DOMDocument $dom, ?string $align = 'center')
    {
        $p = $dom->createElementNS(self::TTE_W_NS, 'w:p');
        $pPr = $dom->createElementNS(self::TTE_W_NS, 'w:pPr');

        $spacing = $dom->createElementNS(self::TTE_W_NS, 'w:spacing');
        $spacing->setAttributeNS(self::TTE_W_NS, 'w:before', '0');
        $spacing->setAttributeNS(self::TTE_W_NS, 'w:after', '60');
        $pPr->appendChild($spacing);

        if ($align !== null) {
            $jc = $dom->createElementNS(self::TTE_W_NS, 'w:jc');
            $jc->setAttributeNS(self::TTE_W_NS, 'w:val', $align);
            $pPr->appendChild($jc);
        }

        $p->appendChild($pPr);

        return $p;
    }

    /**
     * Sisipkan gambar PNG ke paragraf .docx (inline, lebar $widthPx @96dpi).
     * Menangani cache rels sendiri karena ZipArchive::getFromName() gagal
     * membaca entry yang baru ditulis lewat addFromString() di sesi yang sama.
     */
    private function insertTteImage(
        \ZipArchive $zip,
        DOMDocument $dom,
        DOMXPath $xp,
        DOMNode $paragraph,
        string $imagePath,
        int $widthPx = 250
    ): bool {
        if (!is_file($imagePath)) {
            return false;
        }

        $binary = @file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            return false;
        }

        $size = @getimagesize($imagePath);
        if (!$size || $size[0] <= 0) {
            return false;
        }

        static $seq = 0;
        $seq++;
        $mediaName = 'tte_' . substr(sha1($binary), 0, 10) . '_' . $seq . '.png';
        $zip->addFromString('word/media/' . $mediaName, $binary);

        static $relsCache = [];
        $key = spl_object_id($zip);
        if (array_key_exists($key, $relsCache)) {
            $relsXml = $relsCache[$key];
        } else {
            $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
            if ($relsXml === false) {
                return false;
            }
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
        $relsCache[$key] = $relsXml;
        $zip->addFromString('word/_rels/document.xml.rels', $relsXml);

        $cx = (int) round($widthPx * 9525);
        $cy = (int) round(($widthPx * $size[1] / $size[0]) * 9525);

        $tmp = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<root xmlns:w="' . self::TTE_W_NS . '"'
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
