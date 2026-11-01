<?php

namespace App\Services;

use App\Models\SmkiFormulirHardening;
use App\Services\Concerns\FillsDocxTemplate;
use App\Services\TteResolverService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use DOMElement;
use Exception;
use ZipArchive;

class SmkiHardeningDocxService
{
    use FillsDocxTemplate;
    /**
     * Cari path template dokumen FR-047 Formulir Hardening Pengecekan Aset.
     */
    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            'D:/1. KULIAH/NEW APTIKA TOOLS/FR-047 Formulir Hardening Pengecekan Aset/FR-047 Formulir Hardening Pengecekan Aset (1) (2).docx',
            resource_path('templates/FR-047 Formulir Hardening Pengecekan Aset.docx'),
            base_path('resources/templates/FR-047 Formulir Hardening Pengecekan Aset.docx'),
            storage_path('app/templates/FR-047 Formulir Hardening Pengecekan Aset.docx'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-047 tidak ditemukan. Pastikan file template ada.');
    }

    /**
     * Generate file DOCX terisi berdasarkan model SmkiFormulirHardening.
     *
     * @param SmkiFormulirHardening $form
     * @return string Path file sementara (temporary docx file)
     */
    public function generateDocx(SmkiFormulirHardening $form): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeDoc    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $form->no_dokumen);
        $outputPath = $tempDir . '/FR-047_' . $safeDoc . '_' . uniqid() . '.docx';

        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin file template FR-047.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX untuk diedit.');
        }

        // ===================================================================
        // 1. MODIFIKASI word/document.xml
        // ===================================================================
        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak valid: word/document.xml tidak ditemukan.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;
        @$dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // 1. HAPUS SEMUA properti floating table (tblpPr)
        // BUG FIX KRUSIAL: Template asli Word mendefinisikan Table 1 sebagai "floating table",
        // yang menyebabkan teks 'Checklist hardening' melompat ke atas tabel,
        // '*Coret salah satu' terlempar ke margin samping kanan, dan tabel menimpa tanda tangan.
        foreach ($xpath->query('//w:tblPr/w:tblpPr') as $tblpPr) {
            $tblpPr->parentNode->removeChild($tblpPr);
        }

        // 2. Optimasi margin halaman agar seluruh konten (6 kategori + tanda tangan) muat presisi dalam 1 HALAMAN (seperti PDF)
        foreach ($xpath->query('//w:sectPr/w:pgMar') as $m) {
            $m->setAttribute('w:top', '1100');     // ~1.9 cm
            $m->setAttribute('w:bottom', '850');   // ~1.5 cm (mencegah pecah ke halaman 2)
            $m->setAttribute('w:left', '1100');    // ~1.9 cm
            $m->setAttribute('w:right', '1100');   // ~1.9 cm
            $m->setAttribute('w:header', '500');
            $m->setAttribute('w:footer', '500');
        }

        $tables = $xpath->query('//w:tbl');

        // --- Tabel 0: Metadata Aset (Nomor Aset, Jenis Aset, Merek/Tipe) ---
        if ($tables->length >= 1) {
            $table0 = $tables->item(0);
            $rows0  = $xpath->query('w:tr', $table0);

            if ($rows0->length > 0) {
                $this->setCellText($xpath, $dom, $rows0->item(0), 2, $form->nomor_aset ?: '-');
            }
            if ($rows0->length > 1) {
                $this->setCellText($xpath, $dom, $rows0->item(1), 2, $form->jenis_aset ?: 'Laptop/PC');
            }
            if ($rows0->length > 2) {
                $this->setCellText($xpath, $dom, $rows0->item(2), 2, $form->merek_tipe ?: '-');
            }

            // Buat spasi Tabel 0 rapi dan ringkas
            $this->compactTableRows($xpath, $dom, $table0, 20);
        }

        // --- Tabel 1: Hardening Checklist ---
        if ($tables->length >= 2) {
            $table1     = $tables->item(1);
            $rows1      = $xpath->query('w:tr', $table1);
            $checklists = $form->checklists()->orderBy('urutan', 'asc')->get();

            $checkIdx = 0;
            for ($ri = 1; $ri < $rows1->length; $ri++) {
                $row   = $rows1->item($ri);
                $cells = $xpath->query('w:tc', $row);

                if ($cells->length < 4) {
                    continue;
                }

                $cell0Text = trim($cells->item(0)->textContent ?? '');

                // Jika kolom 0 berisi nomor kategori (1, 2, 3, dst), ini baris kategori
                if ($cell0Text !== '' && is_numeric($cell0Text)) {
                    continue; // Header kategori dibiarkan
                }

                // Sub-item checklist
                if ($checkIdx < count($checklists)) {
                    $item = $checklists[$checkIdx];
                    
                    // Simbol checklist: centang jika Pass, tanda strip jika Fail
                    $isPass = in_array(strtolower(trim($item->checklist ?? '')), ['pass', 'sesuai', 'ada', '1', 'true', 'ya']);
                    $symbol = $isPass ? '✓' : '-';

                    // Kolom 2: Checklist
                    $this->setCellText($xpath, $dom, $row, 2, $symbol);

                    // Kolom 3: Keterangan
                    $this->setCellText($xpath, $dom, $row, 3, $item->keterangan ?: '-');

                    $checkIdx++;
                }
            }

            // Format seluruh baris Tabel 1 agar font 9.5pt (sz=19) & line-spacing proporsional persis PDF
            $this->compactTableRows($xpath, $dom, $table1, 19);
        }

        // --- 3. GANTI BAGIAN BAWAH TABEL 1 DENGAN DUA KOLOM SEMPURNA PERSIS PDF ---
        // Menghapus seluruh paragraf lama yang memiliki tab-stop rusak (yang memecah "Specimen pembuat")
        // dan menggantinya dengan tabel 2-kolom tanpa border (borderless):
        // Kolom Kiri: Checklist hardening & *Coret salah satu
        // Kolom Kanan: Tempat/Tanggal, Specimen pembuat,, Tanda Tangan, Nama Auditor (Bold) & NIP
        $tglPengesahan = $form->tanggal_pengesahan
            ? Carbon::parse($form->tanggal_pengesahan)->translatedFormat('d F Y')
            : ($form->tanggal_check ? Carbon::parse($form->tanggal_check)->translatedFormat('d F Y') : Carbon::now()->translatedFormat('d F Y'));

        $kotaTanggal = ($form->kota ?: 'Bandung') . ', ' . $tglPengesahan;
        $auditorName = $form->nama_auditor ?: 'Tim IT Security';
        $auditorRawNip = $form->nip_auditor ?: '';
        $auditorNip  = $form->nip_auditor ? 'NIP. ' . $form->nip_auditor : '';

        $ttePath = ($auditorRawNip === '' && $auditorName === '') ? null : app(TteResolverService::class)->imagePath($auditorRawNip, $auditorName);

        if ($tables->length >= 2) {
            $table1 = $tables->item(1);
            $after = false;
            $toRemove = [];
            foreach ($xpath->query('/w:document/w:body/*') as $node) {
                if ($node === $table1) {
                    $after = true;
                    continue;
                }
                if ($after && $node->nodeName !== 'w:sectPr') {
                    $toRemove[] = $node;
                }
            }
            foreach ($toRemove as $rem) {
                if ($rem->parentNode) {
                    $rem->parentNode->removeChild($rem);
                }
            }

            $sectPr = $xpath->query('/w:document/w:body/w:sectPr')->item(0);

            $nipXml = $auditorNip ? '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:br/><w:t>' . htmlspecialchars($auditorNip) . '</w:t></w:r>' : '';

            $sigTableXml = '
<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:tblPr>
    <w:tblW w:w="9210" w:type="dxa"/>
    <w:tblBorders>
      <w:top w:val="none" w:sz="0" w:space="0" w:color="auto"/>
      <w:left w:val="none" w:sz="0" w:space="0" w:color="auto"/>
      <w:bottom w:val="none" w:sz="0" w:space="0" w:color="auto"/>
      <w:right w:val="none" w:sz="0" w:space="0" w:color="auto"/>
      <w:insideH w:val="none" w:sz="0" w:space="0" w:color="auto"/>
      <w:insideV w:val="none" w:sz="0" w:space="0" w:color="auto"/>
    </w:tblBorders>
    <w:tblLayout w:type="fixed"/>
  </w:tblPr>
  <w:tr>
    <w:tc>
      <w:tcPr>
        <w:tcW w:w="4800" w:type="dxa"/>
        <w:vAlign w:val="top"/>
      </w:tcPr>
      <w:p>
        <w:pPr>
          <w:spacing w:before="60" w:after="20"/>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:i/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:i/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
          <w:t>Checklist hardening</w:t>
        </w:r>
      </w:p>
      <w:p>
        <w:pPr>
          <w:spacing w:before="0" w:after="0"/>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:i/>
            <w:sz w:val="17"/>
            <w:szCs w:val="17"/>
          </w:rPr>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:i/>
            <w:sz w:val="17"/>
            <w:szCs w:val="17"/>
          </w:rPr>
          <w:t>*Coret salah satu</w:t>
        </w:r>
      </w:p>
    </w:tc>
    <w:tc>
      <w:tcPr>
        <w:tcW w:w="4410" w:type="dxa"/>
        <w:vAlign w:val="top"/>
      </w:tcPr>
      <w:p>
        <w:pPr>
          <w:jc w:val="left"/>
          <w:spacing w:before="40" w:after="10"/>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
          <w:t>' . htmlspecialchars($kotaTanggal) . '</w:t>
        </w:r>
      </w:p>
      <w:p>
        <w:pPr>
          <w:jc w:val="left"/>
          <w:spacing w:before="0" w:after="0"/>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:sz w:val="19"/>
            <w:szCs w:val="19"/>
          </w:rPr>
          <w:t>Specimen pembuat,</w:t>
        </w:r>
        ' . ($ttePath !== null ? '' : '<w:r><w:br/><w:br/><w:br/></w:r>') . '
      </w:p>
      <w:p>
        <w:pPr>
          <w:jc w:val="left"/>
          <w:spacing w:before="0" w:after="0"/>
        </w:pPr>
        <w:r>
          <w:rPr>
            <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
            <w:b/>
            <w:sz w:val="20"/>
            <w:szCs w:val="20"/>
          </w:rPr>
          <w:t>( ' . htmlspecialchars($auditorName) . ' )</w:t>
        </w:r>
        ' . $nipXml . '
      </w:p>
    </w:tc>
  </w:tr>
</w:tbl>';

            $frag = $dom->createDocumentFragment();
            $frag->appendXML($sigTableXml);
            if ($sectPr) {
                $sectPr->parentNode->insertBefore($frag, $sectPr);
            } else {
                $dom->getElementsByTagName('body')->item(0)->appendChild($frag);
            }

            if ($ttePath !== null) {
                $auditorTarget = null;
                foreach ($xpath->query('//w:tc//w:p') as $p) {
                    if (str_contains($p->textContent, '( ' . $auditorName . ' )')) {
                        $auditorTarget = $p;
                        break;
                    }
                }
                if ($auditorTarget) {
                    $imgPara = $this->tteImageParagraph($dom, 'left');
                    if ($this->insertImageIntoParagraph($zip, $dom, $xpath, $imgPara, $ttePath, 200)) {
                        $auditorTarget->parentNode->insertBefore($imgPara, $auditorTarget);
                        // Hapus teks nama manual di bawahnya karena nama sudah tercantum resmi di dalam kotak spesimen TTE
                        if ($auditorTarget->parentNode) {
                            $auditorTarget->parentNode->removeChild($auditorTarget);
                        }
                    }
                }
            }
        }

        $zip->addFromString('word/document.xml', $dom->saveXML());

        // ===================================================================
        // 2. MODIFIKASI word/header*.xml (No. Dokumen, No. Revisi, Tgl Terbit)
        // ===================================================================
        foreach (['word/header1.xml', 'word/header2.xml', 'word/header3.xml'] as $headerFile) {
            $headerXml = $zip->getFromName($headerFile);
            if (!$headerXml) {
                continue;
            }

            $hDom = new DOMDocument();
            $hDom->preserveWhiteSpace = true;
            $hDom->formatOutput       = false;
            @$hDom->loadXML($headerXml);

            $hXpath = new DOMXPath($hDom);
            $hXpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $modified = false;
            $hCells = $hXpath->query('//w:tc');
            foreach ($hCells as $tc) {
                $cellText = trim($tc->textContent ?? '');

                // Ganti No. Dokumen jika ada perubahan
                if ((str_contains($cellText, 'FR-047/KOM.03.05/SANDIKAMI') || str_contains($cellText, 'FR-047'))
                    && !empty($form->no_dokumen)
                    && !str_contains($cellText, 'FORMULIR HARDENING')
                ) {
                    $hp = $hXpath->query('.//w:p', $tc);
                    if ($hp->length > 0) {
                        $this->replaceParagraphText($hXpath, $hDom, $hp->item(0), $form->no_dokumen);
                        $modified = true;
                    }
                }
                // Ganti No. Revisi jika ada
                elseif ($cellText === '0.0' && !empty($form->no_revisi)) {
                    $hp = $hXpath->query('.//w:p', $tc);
                    if ($hp->length > 0) {
                        $this->replaceParagraphText($hXpath, $hDom, $hp->item(0), $form->no_revisi);
                        $modified = true;
                    }
                }
                // Ganti Tanggal Terbit jika ada
                elseif (str_contains($cellText, '24 Oktober 2024') && !empty($form->tanggal_terbit)) {
                    $formattedTglTerbit = Carbon::parse($form->tanggal_terbit)->translatedFormat('d F Y');
                    $hp = $hXpath->query('.//w:p', $tc);
                    if ($hp->length > 0) {
                        $this->replaceParagraphText($hXpath, $hDom, $hp->item(0), $formattedTglTerbit);
                        $modified = true;
                    }
                }
            }

            if ($modified) {
                $zip->addFromString($headerFile, $hDom->saveXML());
            }
        }

        // ===================================================================
        // 3. MODIFIKASI word/footer*.xml (Hapus nomor halaman 'Hal ... dari ... hal')
        // ===================================================================
        $cleanFooterXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr>
      <w:pBdr>
        <w:top w:val="single" w:sz="4" w:space="1" w:color="000000"/>
      </w:pBdr>
      <w:jc w:val="left"/>
    </w:pPr>
    <w:r>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
        <w:i/>
        <w:iCs/>
        <w:sz w:val="18"/>
        <w:szCs w:val="18"/>
      </w:rPr>
      <w:t xml:space="preserve">Klasifikasi: </w:t>
    </w:r>
    <w:r>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
        <w:b/>
        <w:bCs/>
        <w:i/>
        <w:iCs/>
        <w:sz w:val="18"/>
        <w:szCs w:val="18"/>
      </w:rPr>
      <w:t>INTERNAL</w:t>
    </w:r>
  </w:p>
</w:ftr>';

        foreach (['word/footer1.xml', 'word/footer2.xml', 'word/footer3.xml'] as $footerFile) {
            if ($zip->locateName($footerFile) !== false) {
                $zip->addFromString($footerFile, $cleanFooterXml);
            }
        }

        $zip->close();

        return $outputPath;
    }

    /**
     * Format blok tanda tangan auditor persis dokumen resmi & PDF:
     * - Label "Specimen pembuat,"
     * - Spasi tanda tangan
     * - Nama Auditor tebal dalam tanda kurung: ( Nama Auditor )
     * - NIP jika tersedia
     */
    protected function formatAuditorSignatureBlock(DOMXPath $xpath, DOMDocument $dom, DOMElement $p, string $auditorName, string $auditorNip): void
    {
        // Hapus run yang ada
        $runs = $xpath->query('w:r|w:hyperlink', $p);
        foreach ($runs as $r) {
            if ($r->parentNode) {
                $r->parentNode->removeChild($r);
            }
        }

        // Run 1: Specimen pembuat,
        $r1 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        $rPr1 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
        $sz1 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
        $sz1->setAttribute('w:val', '20');
        $rPr1->appendChild($sz1);
        $r1->appendChild($rPr1);

        $t1 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $t1->setAttribute('xml:space', 'preserve');
        $t1->appendChild($dom->createTextNode("Specimen pembuat,"));
        $r1->appendChild($t1);

        // 3 baris kosong spasi tanda tangan
        $br1 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
        $br2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
        $br3 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
        $r1->appendChild($br1);
        $r1->appendChild($br2);
        $r1->appendChild($br3);
        $p->appendChild($r1);

        // Run 2: ( Nama Auditor ) dalam font tebal (bold)
        $r2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        $rPr2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
        $b2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:b');
        $sz2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
        $sz2->setAttribute('w:val', '20');
        $rPr2->appendChild($b2);
        $rPr2->appendChild($sz2);
        $r2->appendChild($rPr2);

        $t2 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $t2->setAttribute('xml:space', 'preserve');
        $t2->appendChild($dom->createTextNode("( " . $auditorName . " )"));
        $r2->appendChild($t2);
        $p->appendChild($r2);

        // Run 3: NIP (jika ada)
        if ($auditorNip) {
            $r3 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $rPr3 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
            $sz3 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
            $sz3->setAttribute('w:val', '18');
            $rPr3->appendChild($sz3);
            $r3->appendChild($rPr3);

            $brNip = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
            $r3->appendChild($brNip);

            $t3 = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $t3->setAttribute('xml:space', 'preserve');
            $t3->appendChild($dom->createTextNode($auditorNip));
            $r3->appendChild($t3);
            $p->appendChild($r3);
        }
    }

    /** Paragraf kosong berisi gambar (dipakai sebagai wadah gambar TTE). */
    protected function tteImageParagraph(DOMDocument $dom, ?string $align = 'left'): DOMElement
    {
        $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
        $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
        if ($align !== null) {
            $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
            $jc->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', $align);
            $pPr->appendChild($jc);
        }
        $spacing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:spacing');
        $spacing->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:before', '40');
        $spacing->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:after', '40');
        $pPr->appendChild($spacing);
        $p->appendChild($pPr);

        return $p;
    }

    /**
     * Jadikan baris dan paragraf tabel lebih padat dan proporsional sesuai skala PDF.
     */
    protected function compactTableRows(DOMXPath $xpath, DOMDocument $dom, DOMElement $table, int $fontSize = 19): void
    {
        // 1. Bersihkan paragraf kosong berlebih di dalam setiap sel tabel (BUG FIX POINT 5: template memiliki 5 baris kosong di poin 5)
        $cells = $xpath->query('.//w:tc', $table);
        foreach ($cells as $cell) {
            $paras = $xpath->query('w:p', $cell);
            if ($paras->length > 1) {
                for ($pi = $paras->length - 1; $pi >= 1; $pi--) {
                    $pNode = $paras->item($pi);
                    if (trim($pNode->textContent ?? '') === '') {
                        $pNode->parentNode->removeChild($pNode);
                    }
                }
            }
        }

        // 2. Format baris dan setiap sel agar padat, proporsional, dan seragam
        $rows = $xpath->query('w:tr', $table);
        foreach ($rows as $row) {
            // Hilangkan trHeight yang memaksakan baris terlalu tinggi agar tinggi baris dinamis dan rapat
            $trPr = $xpath->query('w:trPr', $row)->item(0);
            if ($trPr) {
                $trHeights = $xpath->query('w:trHeight', $trPr);
                foreach ($trHeights as $th) {
                    $th->parentNode->removeChild($th);
                }
            }

            // Format paragraf di dalam setiap sel tabel
            $paras = $xpath->query('.//w:p', $row);
            foreach ($paras as $p) {
                $pPr = $xpath->query('w:pPr', $p)->item(0);
                if (!$pPr) {
                    $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
                    $p->insertBefore($pPr, $p->firstChild);
                }
                $spacing = $xpath->query('w:spacing', $pPr)->item(0);
                if (!$spacing) {
                    $spacing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:spacing');
                    $pPr->appendChild($spacing);
                }
                $spacing->setAttribute('w:before', '6');
                $spacing->setAttribute('w:after', '6');
                $spacing->setAttribute('w:line', '220');
                $spacing->setAttribute('w:lineRule', 'auto');
            }

            // Terapkan ukuran font pada setiap teks di dalam sel
            $runs = $xpath->query('.//w:r', $row);
            foreach ($runs as $r) {
                $rPr = $xpath->query('w:rPr', $r)->item(0);
                if (!$rPr) {
                    $rPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
                    $r->insertBefore($rPr, $r->firstChild);
                }
                $sz = $xpath->query('w:sz', $rPr)->item(0);
                if (!$sz) {
                    $sz = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
                    $rPr->appendChild($sz);
                }
                $sz->setAttribute('w:val', (string)$fontSize);

                $szCs = $xpath->query('w:szCs', $rPr)->item(0);
                if (!$szCs) {
                    $szCs = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:szCs');
                    $rPr->appendChild($szCs);
                }
                $szCs->setAttribute('w:val', (string)$fontSize);
            }
        }
    }

    /**
     * Set spacing sebelum dan sesudah pada paragraf.
     */
    protected function setParagraphSpacing(DOMXPath $xpath, DOMDocument $dom, DOMElement $p, int $before = 0, int $after = 0): void
    {
        $pPr = $xpath->query('w:pPr', $p)->item(0);
        if (!$pPr) {
            $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
            $p->insertBefore($pPr, $p->firstChild);
        }
        $spacing = $xpath->query('w:spacing', $pPr)->item(0);
        if (!$spacing) {
            $spacing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:spacing');
            $pPr->appendChild($spacing);
        }
        $spacing->setAttribute('w:before', (string)$before);
        $spacing->setAttribute('w:after', (string)$after);
        $spacing->setAttribute('w:line', '220');
        $spacing->setAttribute('w:lineRule', 'auto');
    }

    /**
     * Set teks pada sel tabel tertentu (0-indexed).
     */
    protected function setCellText(DOMXPath $xpath, DOMDocument $dom, DOMElement $row, int $cellIndex, string $text): void
    {
        $cells = $xpath->query('w:tc', $row);
        if ($cellIndex >= $cells->length) {
            return;
        }

        $cell       = $cells->item($cellIndex);
        $paragraphs = $xpath->query('w:p', $cell);

        if ($paragraphs->length > 0) {
            $this->replaceParagraphText($xpath, $dom, $paragraphs->item(0), $text);
        }
    }

    /**
     * Ganti isi seluruh w:r / w:t dalam satu paragraf dengan string baru,
     * sambil mempertahankan formatting (rPr) dari run pertama.
     */
    protected function replaceParagraphText(DOMXPath $xpath, DOMDocument $dom, DOMElement $p, string $text): void
    {
        // Ambil run properties dari run pertama agar font & style tetap konsisten
        $existingRPr = null;
        $firstR      = $xpath->query('w:r', $p)->item(0);
        if ($firstR) {
            $rPrNode = $xpath->query('w:rPr', $firstR)->item(0);
            if ($rPrNode) {
                $existingRPr = $rPrNode->cloneNode(true);
            }
        }

        // Hapus semua run yang ada
        $runs = $xpath->query('w:r|w:hyperlink', $p);
        foreach ($runs as $r) {
            if ($r->parentNode) {
                $r->parentNode->removeChild($r);
            }
        }

        // Buat run baru
        $r = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
        if ($existingRPr) {
            $r->appendChild($existingRPr);
        }

        // Handle newline di dalam teks
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $br = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br');
                $r->appendChild($br);
            }
            $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($line));
            $r->appendChild($t);
        }

        $p->appendChild($r);
    }
}
