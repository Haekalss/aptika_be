<?php

namespace App\Services;

use Exception;
use ZipArchive;

/**
 * Menghasilkan dokumen Word (.docx) FR-033 Formulir Daftar Role / User Access Matrix.
 * Dibuat langsung dari OOXML agar tidak bergantung pada file template.
 */
class SmkiDaftarRoleDocxService
{
    /** @param \Illuminate\Support\Collection|array $items */
    public function generateDocx($items, array $options = []): string
    {
        $items = is_array($items) ? $items : $items->all();
        if (! $items) {
            throw new Exception('Tidak ada formulir FR-033 untuk diekspor.');
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $outputPath = $tempDir.'/FR-033_generated_'.uniqid().'.docx';

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Gagal membuat file DOCX.');
        }

        $logoPath = resource_path('templates/logo-jabar.png');
        $hasLogo = is_file($logoPath) && is_readable($logoPath);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRels($hasLogo));
        $zip->addFromString('word/styles.xml', $this->styles());
        if ($hasLogo) {
            $zip->addFromString('word/media/logo.png', file_get_contents($logoPath));
        }
        $zip->addFromString('word/document.xml', $this->document($items, $options, $hasLogo));
        $zip->close();

        return $outputPath;
    }

    private function document(array $items, array $options, bool $hasLogo): string
    {
        $body = '';
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $body .= $this->renderItem($item, $options, $hasLogo);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'.
            ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'.
            ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'.
            ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'.
            ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
            '<w:body>'.$body.
            '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'.
            '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="720" w:footer="720" w:gutter="0"/>'.
            '</w:sectPr></w:body></w:document>';
    }

    private function renderItem($item, array $options, bool $hasLogo): string
    {
        $get = static function (string $key, string $default = '-') use ($item): string {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return ($value === null || $value === '') ? $default : (string) $value;
        };

        $noDokumen = $get('documentNumber', 'FR-033/KOM.03.05/SANDIKAMI');
        $revisi = $get('revision', '0.0');
        $tanggal = $this->formatDate($get('effectiveDate'));
        $lastUpdate = $this->formatDate(substr($get('updatedAt', ''), 0, 10));

        // Kop formulir
        $header = '<w:tbl><w:tblPr><w:tblW w:w="9200" w:type="dxa"/>'.
            $this->borders().'</w:tblPr>';
        $header .= '<w:tr>';
        $logo = $hasLogo
            ? $this->logoDrawing()
            : $this->p('DISKOMINFO', ['bold' => true, 'size' => 9, 'align' => 'center']).
              $this->p('JAWA BARAT', ['bold' => true, 'size' => 9, 'align' => 'center']);
        $header .= $this->cell($logo, 1400);
        $header .= $this->cell($this->p('FORMULIR DAFTAR ROLE', ['bold' => true, 'size' => 15, 'align' => 'center']), 4500);
        $meta = $this->metaRow('No Dokumen', $noDokumen).
            $this->metaRow('Revisi', $revisi).
            $this->metaRow('Tanggal', $tanggal).
            $this->metaRow('Last Update', $lastUpdate);
        $header .= $this->cell($meta, 3300);
        $header .= '</w:tr></w:tbl>';

        $body = $header;
        $body .= $this->p('');
        $body .= $this->p('Unit Kerja : '.$get('unit'), ['size' => 10]);
        $body .= $this->p('');

        // Tabel role
        $roles = is_array($item) ? ($item['roles'] ?? []) : ($item->roles ?? []);
        $roles = is_array($roles) ? $roles : [];
        $rows = [
            $this->row([$this->paragraphCell('Role', true, true), $this->paragraphCell('Akses pada sistem', true, true)]),
        ];
        $count = max(12, count($roles));
        for ($i = 0; $i < $count; $i++) {
            $role = $roles[$i] ?? [];
            $roleName = is_array($role) ? (string) ($role['role'] ?? '') : (string) ($role->role ?? '');
            $access = is_array($role) ? (string) ($role['access'] ?? '') : (string) ($role->access ?? '');
            $rows[] = $this->row([
                $this->paragraphCell($roleName, false, false, 2800),
                $this->paragraphCell($access, false, false, 6400),
            ]);
        }
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="9200" w:type="dxa"/>'.$this->borders().'</w:tblPr>'.implode('', $rows).'</w:tbl>';

        $body .= $this->p('');
        $body .= $this->p('Catatan : '.$get('note', 'Role dapat ditambahkan sesuai dengan kondisi masing-masing Unit Kerja.'), ['size' => 9]);
        $body .= $this->p('');

        // Tanda tangan
        $city = $get('city', 'Bandung');
        $docDate = $this->formatDate($get('documentDate'));
        $preparedNip = $get('preparedByNip', '');
        $approvedNip = $get('approvedByNip', '');
        $sign = '<w:tbl><w:tblPr><w:tblW w:w="9200" w:type="dxa"/>'.$this->borders().'</w:tblPr>';
        $sign .= '<w:tr>';
        $sign .= $this->cell($this->p($city.', '.$docDate, ['size' => 9]), 4600);
        $sign .= $this->cell($this->p($city.', '.$docDate, ['size' => 9]), 4600);
        $sign .= '</w:tr><w:tr>';
        $sign .= $this->cell(
            $this->p('Dibuat oleh :', ['size' => 9]).
            $this->p('', []).$this->p('', []).
            $this->p($get('preparedBy', '(........................)'), ['size' => 9, 'align' => 'center']).
            ($preparedNip !== '' && $preparedNip !== '-' ? $this->p('NIP: '.$preparedNip, ['size' => 8, 'align' => 'center']) : ''),
            4600
        );
        $sign .= $this->cell(
            $this->p('Disetujui oleh :', ['size' => 9]).
            $this->p('', []).$this->p('', []).
            $this->p($get('approvedBy', '(........................)'), ['size' => 9, 'align' => 'center']).
            ($approvedNip !== '' && $approvedNip !== '-' ? $this->p('NIP: '.$approvedNip, ['size' => 8, 'align' => 'center']) : ''),
            4600
        );
        $sign .= '</w:tr></w:tbl>';

        return $body.$sign;
    }

    private function metaRow(string $label, string $value): string
    {
        $labelCell = '<w:tc><w:tcPr><w:tcW w:w="1200" w:type="dxa"/></w:tcPr>'.
            $this->p($label, ['bold' => true, 'size' => 8]).'</w:tc>';
        $valueCell = '<w:tc><w:tcPr><w:tcW w:w="2100" w:type="dxa"/></w:tcPr>'.
            $this->p($value, ['size' => 8]).'</w:tc>';

        return '<w:tbl><w:tblPr><w:tblLayout w:type="fixed"/></w:tblPr>'.
            '<w:tr>'.$labelCell.$valueCell.'</w:tr></w:tbl>';
    }

    private function logoDrawing(): string
    {
        $cx = 495300;
        $cy = 647700;

        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr><w:r><w:drawing>'.
            '<wp:inline distT="0" distB="0" distL="0" distR="0">'.
            '<wp:extent cx="'.$cx.'" cy="'.$cy.'"/>'.
            '<wp:docPr id="1" name="Logo Diskominfo Jawa Barat"/>'.
            '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
            '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'.
            '<pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'.
            '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'.
            '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'.
            '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private function borders(): string
    {
        $edges = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $edges .= '<w:'.$edge.' w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
        }
        return '<w:tblBorders>'.$edges.'</w:tblBorders>';
    }

    private function row(array $cells): string
    {
        return '<w:tr>'.implode('', $cells).'</w:tr>';
    }

    private function cell(string $content, int $width): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/></w:tcPr>'.$content.'</w:tc>';
    }

    private function paragraphCell(string $text, bool $bold, bool $shade, int $width = 4600): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.
            ($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="d1d5db"/>' : '').
            '</w:tcPr>'.$this->p($text, ['bold' => $bold, 'size' => 9, 'align' => $shade ? 'center' : 'left']).'</w:tc>';
    }

    private function p(string $text, array $opts = []): string
    {
        $align = $opts['align'] ?? null;
        $pPr = '<w:pPr><w:spacing w:after="40"/>'.($align ? '<w:jc w:val="'.$align.'"/>' : '').'</w:pPr>';
        if ($text === '') {
            return '<w:p>'.$pPr.'</w:p>';
        }
        $rPr = '<w:rPr>';
        if (! empty($opts['bold'])) $rPr .= '<w:b/>';
        if (isset($opts['size'])) $rPr .= '<w:sz w:val="'.((int) $opts['size'] * 2).'"/>';
        $rPr .= '</w:rPr>';

        return '<w:p>'.$pPr.'<w:r>'.$rPr.'<w:t xml:space="preserve">'.$this->esc($text).'</w:t></w:r></w:p>';
    }

    private function formatDate(string $value): string
    {
        if (! $value) return '-';
        try {
            return \Carbon\Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
            '<Default Extension="xml" ContentType="application/xml"/>'.
            '<Default Extension="png" ContentType="image/png"/>'.
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'.
            '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'.
            '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'.
            '</Relationships>';
    }

    private function documentRels(bool $hasLogo = false): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        if ($hasLogo) {
            $rels .= '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.
            '<w:docDefaults><w:rPrDefault><w:rPr>'.
            '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'.
            '<w:sz w:val="20"/><w:szCs w:val="20"/>'.
            '</w:rPr></w:rPrDefault></w:docDefaults>'.
            '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'.
            '</w:styles>';
    }
}
