<?php

namespace App\Services;

use App\Models\BeritaAcara;
use Exception;
use ZipArchive;

class SmkiBaMediaDocxService
{
    /**
     * Nama hari dalam bahasa Indonesia.
     */
    protected static array $hariIndo = [
        'Sunday'    => 'Minggu',
        'Monday'    => 'Senin',
        'Tuesday'   => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday'  => 'Kamis',
        'Friday'    => 'Jumat',
        'Saturday'  => 'Sabtu',
    ];

    /**
     * Nama bulan dalam bahasa Indonesia.
     */
    protected static array $bulanIndo = [
        1  => 'Januari',
        2  => 'Februari',
        3  => 'Maret',
        4  => 'April',
        5  => 'Mei',
        6  => 'Juni',
        7  => 'Juli',
        8  => 'Agustus',
        9  => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Format tanggal Indonesia kalimat resmi FR-014:
     * "Pada hari ini [Hari], tanggal [Tanggal], bulan [Bulan], tahun [Tahun] telah dilakukan kegiatan Penghancuran/Disposal terhadap media sebagai berikut:"
     */
    public static function formatTanggalKalimat(string $dateStr): string
    {
        $timestamp = strtotime($dateStr) ?: time();
        $englishDay = date('l', $timestamp);
        $hari = self::$hariIndo[$englishDay] ?? $englishDay;
        $tanggal = date('j', $timestamp);
        $bulanNum = (int) date('n', $timestamp);
        $bulan = self::$bulanIndo[$bulanNum] ?? date('F', $timestamp);
        $tahun = date('Y', $timestamp);

        return "Pada hari ini {$hari}, tanggal {$tanggal}, bulan {$bulan}, tahun {$tahun} telah dilakukan kegiatan Penghancuran/Disposal terhadap media sebagai berikut:";
    }

    /**
     * Format tanggal standar Indonesia: "15 September 2025"
     */
    public static function formatTanggalIndo(string $dateStr): string
    {
        $timestamp = strtotime($dateStr) ?: time();
        $tanggal = date('j', $timestamp);
        $bulanNum = (int) date('n', $timestamp);
        $bulan = self::$bulanIndo[$bulanNum] ?? date('F', $timestamp);
        $tahun = date('Y', $timestamp);

        return "{$tanggal} {$bulan} {$tahun}";
    }

    /**
     * Hasilkan file DOCX resmi Formulir Berita Acara Penghancuran Media (FR014-SMKI).
     *
     * @param BeritaAcara $ba
     * @param array $options Opsi kustomisasi header:
     *   - 'no_dokumen'    : string (default: nomor_dokumen dari record atau 'FR014-SMKI')
     *   - 'no_revisi'     : string (default: '1.0')
     *   - 'tanggal_berlaku': string (default: tanggal pelaksanaan diformat atau '15 September 2025')
     * @return string Path file sementara (temporary docx)
     */
    public function generateDocx(BeritaAcara $ba, array $options = []): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $outputPath = $tempDir . '/FR014_BA_Media_' . ($ba->id_ba ?? uniqid()) . '_' . time() . '.docx';

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Gagal membuat file arsip DOCX sementara.");
        }

        // 1. [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', $this->getContentTypesXml());

        // 2. _rels/.rels
        $zip->addFromString('_rels/.rels', $this->getRootRelsXml());

        // 3. word/_rels/document.xml.rels
        $hasLogo = false;
        $logoPath = resource_path('templates/logo-jabar.png');
        if (!file_exists($logoPath)) {
            $logoPath = base_path('public/logo-jabar.png');
        }
        if (file_exists($logoPath)) {
            $hasLogo = true;
            $zip->addFile($logoPath, 'word/media/image1.png');
        }

        // Spesimen TTE penanda tangan (opsional, dilewati bila tidak ada).
        $tteImages = $this->resolveTteImages($ba);
        foreach ($tteImages as $mediaName => $binary) {
            $zip->addFromString('word/media/' . $mediaName, $binary);
        }
        $zip->addFromString('word/_rels/document.xml.rels', $this->getDocumentRelsXml($hasLogo, array_keys($tteImages)));

        // 4. word/styles.xml
        $zip->addFromString('word/styles.xml', $this->getStylesXml());

        // 5. word/document.xml
        $documentXml = $this->buildDocumentXml($ba, $options, $hasLogo, $tteImages);
        $zip->addFromString('word/document.xml', $documentXml);

        $zip->close();

        return $outputPath;
    }

    /**
     * XML untuk [Content_Types].xml
     */
    protected function getContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
    }

    /**
     * XML untuk _rels/.rels
     */
    protected function getRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    }

    /**
     * XML untuk word/_rels/document.xml.rels
     */
    protected function getDocumentRelsXml(bool $hasLogo, array $tteMedia = []): string
    {
        $imageRel = $hasLogo
            ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            : '';

        $tteRels = '';
        $n = 3;
        foreach ($tteMedia as $mediaName) {
            $tteRels .= '<Relationship Id="rIdTte' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . htmlspecialchars($mediaName, ENT_XML1, 'UTF-8') . '"/>';
            $n++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  ' . $imageRel . '
  ' . $tteRels . '
</Relationships>';
    }

    /**
     * XML untuk word/styles.xml
     */
    protected function getStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
        <w:sz w:val="22"/>
        <w:szCs w:val="22"/>
        <w:lang w:val="id-ID"/>
      </w:rPr>
    </w:rPrDefault>
    <w:pPrDefault>
      <w:pPr>
        <w:spacing w:line="260" w:lineRule="auto" w:after="120"/>
      </w:pPr>
    </w:pPrDefault>
  </w:docDefaults>
</w:styles>';
    }

    /**
     * Resolve gambar spesimen TTE kedua penanda tangan.
     * @return array<string,string> mediaName => binary PNG
     */
    protected function resolveTteImages(BeritaAcara $ba): array
    {
        $out = [];
        $pairs = [
            'tte_pelaksana.png' => [(string) ($ba->nip_pelaksana ?? ''), (string) $ba->nama_pelaksana_display],
            'tte_diketahui.png' => [(string) ($ba->nip_diketahui ?? ''), (string) $ba->nama_diketahui_display],
        ];
        foreach ($pairs as $mediaName => [$nip, $nama]) {
            try {
                $path = app(\App\Services\TteResolverService::class)->imagePath($nip, $nama);
            } catch (\Throwable $e) {
                $path = null;
            }
            if ($path && is_file($path)) {
                $binary = @file_get_contents($path);
                if ($binary !== false && $binary !== '') {
                    $out[$mediaName] = $binary;
                }
            }
        }

        return $out;
    }

    /**
     * Paragraf gambar TTE (inline, rata tengah) untuk sel tanda tangan,
     * atau paragraf spasi bila tidak ada gambar (ruang TTD manual).
     */
    /**
     * Blok gambar TTE atau, bila tidak ada gambar, blok nama + NIP teks.
     * Pola FR-008: nama/NIP teks tidak ditulis bila gambar berhasil disisipkan.
     */
    protected function tteOrNameBlock(array $tteImages, string $mediaName, string $nama, string $nip): string
    {
        if (isset($tteImages[$mediaName])) {
            return $this->tteDrawingXml($tteImages, $mediaName);
        }

        $out = '<w:p><w:pPr><w:spacing w:before="900" w:after="0"/></w:pPr></w:p>';
        $out .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:u w:val="single"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr><w:t>'
            . htmlspecialchars($nama, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
        if ($nip !== '') {
            $out .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>'
                . '<w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>NIP. '
                . htmlspecialchars($nip, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
        }

        return $out;
    }

    protected function tteDrawingXml(array $tteImages, string $mediaName): string
    {
        if (!isset($tteImages[$mediaName])) {
            return '<w:p><w:pPr><w:spacing w:before="900" w:after="0"/></w:pPr></w:p>';
        }

        $relId = 'rIdTte' . (3 + array_search($mediaName, array_keys($tteImages), true));
        $uid = 10 + (int) array_search($mediaName, array_keys($tteImages), true);
        $size = @getimagesizefromstring($tteImages[$mediaName]);
        $wPx = 270;
        $hPx = ($size && $size[0] > 0) ? (int) round($wPx * $size[1] / $size[0]) : 70;
        $cx = $wPx * 9525;
        $cy = max(1, $hPx) * 9525;

        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="60"/></w:pPr><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $uid . '" name="' . htmlspecialchars($mediaName, ENT_XML1, 'UTF-8') . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . (100 + $uid) . '" name="tte.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></w:p>';
    }

    /**
     * Rakit XML utama untuk word/document.xml
     */
    protected function buildDocumentXml(BeritaAcara $ba, array $options, bool $hasLogo, array $tteImages = []): string
    {
        $noDokumen = $options['no_dokumen'] ?? ($ba->nomor_dokumen ?: 'FR014-SMKI');
        $noRevisi = $options['no_revisi'] ?? '1.0';
        $tanggalBerlaku = $options['tanggal_berlaku'] ?? ($ba->tanggal_pelaksanaan ? self::formatTanggalIndo((string)$ba->tanggal_pelaksanaan) : '15 September 2025');

        $kalimatPembuka = self::formatTanggalKalimat((string)($ba->tanggal_pelaksanaan ?: date('Y-m-d')));
        $alasanText = $ba->alasan_penghancuran ?: 'Media/perangkat tersebut dilakukan penghancuran atau disposal karena mengalami kerusakan, sudah tidak digunakan, dan sebagian data di dalamnya sudah tidak diperlukan. Kegiatan penghancuran dilakukan untuk mencegah penggunaan kembali perangkat serta mengurangi risiko akses terhadap informasi yang tersimpan pada media.';

        $namaPelaksana = $ba->nama_pelaksana_display;
        $nipPelaksana = trim((string) ($ba->nip_pelaksana ?? ''));
        $namaDiketahui = $ba->nama_diketahui_display;
        $nipDiketahui = trim((string) ($ba->nip_diketahui ?? ''));

        // Pastikan relasi detailMedia termuat
        $mediaList = $ba->detailMedia ?? collect();

        // Bangun Baris Tabel Media
        $mediaRowsXml = '';
        if ($mediaList->count() === 0) {
            $mediaRowsXml .= '
            <w:tr>
              <w:tc>
                <w:tcPr><w:tcW w:w="600" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>1.</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="2600" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>-</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="3200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>-</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="1200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>-</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="2200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>-</w:t></w:r></w:p>
              </w:tc>
            </w:tr>';
        } else {
            $idx = 1;
            foreach ($mediaList as $item) {
                $noUrut = $item->no_urut ?: $idx;
                $nama = htmlspecialchars($item->nama_perangkat ?? '-', ENT_XML1, 'UTF-8');
                $spesifikasiSerial = htmlspecialchars($item->spesifikasi_serial_display ?? '-', ENT_XML1, 'UTF-8');
                $jumlahSatuan = htmlspecialchars(($item->jumlah ?? 1) . ' ' . ($item->satuan ?? 'Unit'), ENT_XML1, 'UTF-8');
                $keterangan = htmlspecialchars($item->keterangan ?? '-', ENT_XML1, 'UTF-8');

                $mediaRowsXml .= '
            <w:tr>
              <w:trPr><w:cantSplit/></w:trPr>
              <w:tc>
                <w:tcPr><w:tcW w:w="600" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>' . $noUrut . '.</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="2600" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>' . $nama . '</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="3200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>' . $spesifikasiSerial . '</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="1200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>' . $jumlahSatuan . '</w:t></w:r></w:p>
              </w:tc>
              <w:tc>
                <w:tcPr><w:tcW w:w="2200" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
                <w:p><w:r><w:t>' . $keterangan . '</w:t></w:r></w:p>
              </w:tc>
            </w:tr>';
                $idx++;
            }
        }

        // XML Gambar Logo
        $logoDrawingXml = '';
        if ($hasLogo) {
            $logoDrawingXml = '
                <w:drawing>
                  <wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">
                    <wp:extent cx="750000" cy="750000"/>
                    <wp:effectExtent l="0" t="0" r="0" b="0"/>
                    <wp:docPr id="1" name="Logo Jabar"/>
                    <wp:cNvGraphicFramePr>
                      <a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>
                    </wp:cNvGraphicFramePr>
                    <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                      <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                        <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                          <pic:nvPicPr>
                            <pic:cNvPr id="0" name="Logo"/>
                            <pic:cNvPicPr/>
                          </pic:nvPicPr>
                          <pic:blipFill>
                            <a:blip r:embed="rId2" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>
                            <a:stretch><a:fillRect/></a:stretch>
                          </pic:blipFill>
                          <pic:spPr>
                            <a:xfrm><a:off x="0" y="0"/><a:ext cx="750000" cy="750000"/></a:xfrm>
                            <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                          </pic:spPr>
                        </pic:pic>
                      </a:graphicData>
                    </a:graphic>
                  </wp:inline>
                </w:drawing>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
            xmlns:v="urn:schemas-microsoft-com:vml"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:w10="urn:schemas-microsoft-com:office:word"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
            xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
  <w:body>

    <!-- ==================== HEADER TABLE (FR-014 STANDARD) ==================== -->
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="9800" w:type="dxa"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:left w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:bottom w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:right w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>
          <w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        </w:tblBorders>
        <w:tblCellMar>
          <w:top w:w="100" w:type="dxa"/>
          <w:left w:w="140" w:type="dxa"/>
          <w:bottom w:w="100" w:type="dxa"/>
          <w:right w:w="140" w:type="dxa"/>
        </w:tblCellMar>
      </w:tblPr>

      <!-- Row 1: Logo, Title, No Dokumen -->
      <w:tr>
        <!-- Cell 1: Logo (Merged vertically across 3 rows) -->
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1800" w:type="dxa"/>
            <w:vMerge w:val="restart"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="40"/></w:pPr>
            ' . $logoDrawingXml . '
          </w:p>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="14"/><w:szCs w:val="14"/></w:rPr><w:t>DISKOMINFO</w:t></w:r>
          </w:p>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="13"/><w:szCs w:val="13"/></w:rPr><w:t>PROVINSI JAWA BARAT</w:t></w:r>
          </w:p>
        </w:tc>

        <!-- Cell 2: Document Title (Merged vertically across 3 rows) -->
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="5200" w:type="dxa"/>
            <w:vMerge w:val="restart"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="40"/></w:pPr>
            <w:r>
              <w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr>
              <w:t>Formulir Berita Acara Penghancuran</w:t>
            </w:r>
          </w:p>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r>
              <w:rPr><w:b/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr>
              <w:t>Media</w:t>
            </w:r>
          </w:p>
        </w:tc>

        <!-- Cell 3: Label No. Dokumen -->
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1400" w:type="dxa"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p>
            <w:pPr><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>No. Dokumen</w:t></w:r>
          </w:p>
        </w:tc>

        <!-- Cell 4: Value No. Dokumen -->
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1400" w:type="dxa"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p>
            <w:pPr><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>' . htmlspecialchars($noDokumen, ENT_XML1, 'UTF-8') . '</w:t></w:r>
          </w:p>
        </w:tc>
      </w:tr>

      <!-- Row 2: No. Revisi -->
      <w:tr>
        <!-- Cell 1: Logo vMerge -->
        <w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>
        <!-- Cell 2: Title vMerge -->
        <w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>
        <!-- Cell 3: Label No. Revisi -->
        <w:tc>
          <w:tcPr><w:tcW w:w="1400" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
          <w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>No. Revisi</w:t></w:r></w:p>
        </w:tc>
        <!-- Cell 4: Value No. Revisi -->
        <w:tc>
          <w:tcPr><w:tcW w:w="1400" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
          <w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>' . htmlspecialchars($noRevisi, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>

      <!-- Row 3: Tanggal Berlaku -->
      <w:tr>
        <!-- Cell 1: Logo vMerge -->
        <w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>
        <!-- Cell 2: Title vMerge -->
        <w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>
        <!-- Cell 3: Label Tanggal Berlaku -->
        <w:tc>
          <w:tcPr><w:tcW w:w="1400" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
          <w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>Tanggal Berlaku</w:t></w:r></w:p>
        </w:tc>
        <!-- Cell 4: Value Tanggal Berlaku -->
        <w:tc>
          <w:tcPr><w:tcW w:w="1400" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
          <w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t>' . htmlspecialchars($tanggalBerlaku, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
    </w:tbl>

    <!-- SPACING -->
    <w:p><w:pPr><w:spacing w:before="240" w:after="160"/></w:pPr></w:p>

    <!-- OPENING STATEMENT -->
    <w:p>
      <w:pPr>
        <w:spacing w:line="320" w:lineRule="auto" w:after="200"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r>
        <w:rPr><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>
        <w:t>' . htmlspecialchars($kalimatPembuka, ENT_XML1, 'UTF-8') . '</w:t>
      </w:r>
    </w:p>

    <!-- ==================== MEDIA ITEMS TABLE ==================== -->
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="9800" w:type="dxa"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:left w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:bottom w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:right w:val="single" w:sz="6" w:space="0" w:color="000000"/>
          <w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>
          <w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        </w:tblBorders>
        <w:tblCellMar>
          <w:top w:w="120" w:type="dxa"/>
          <w:left w:w="140" w:type="dxa"/>
          <w:bottom w:w="120" w:type="dxa"/>
          <w:right w:w="140" w:type="dxa"/>
        </w:tblCellMar>
      </w:tblPr>

      <!-- TABLE HEADER -->
      <w:tr>
        <w:trPr><w:tblHeader/></w:trPr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="600" w:type="dxa"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>No</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="2600" w:type="dxa"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Nama Perangkat</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="3200" w:type="dxa"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Spesifikasi/ Serial No.</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1200" w:type="dxa"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Jumlah</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="2200" w:type="dxa"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
            <w:vAlign w:val="center"/>
          </w:tcPr>
          <w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Keterangan</w:t></w:r></w:p>
        </w:tc>
      </w:tr>

      <!-- DYNAMIC ROWS -->
      ' . $mediaRowsXml . '
    </w:tbl>

    <!-- SPACING -->
    <w:p><w:pPr><w:spacing w:before="240" w:after="80"/></w:pPr></w:p>

    <!-- ALASAN PENGHANCURAN HEADER -->
    <w:p>
      <w:pPr><w:spacing w:after="80"/></w:pPr>
      <w:r>
        <w:rPr><w:b/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>
        <w:t>Alasan Penghancuran :</w:t>
      </w:r>
    </w:p>

    <!-- ALASAN PENGHANCURAN TEXT -->
    <w:p>
      <w:pPr>
        <w:spacing w:line="320" w:lineRule="auto" w:after="360"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r>
        <w:rPr><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr>
        <w:t>' . htmlspecialchars($alasanText, ENT_XML1, 'UTF-8') . '</w:t>
      </w:r>
    </w:p>

    <!-- ==================== DUAL SIGNATURE BLOCKS ==================== -->
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="9800" w:type="dxa"/>
        <w:tblBorders>
          <w:top w:val="none"/>
          <w:left w:val="none"/>
          <w:bottom w:val="none"/>
          <w:right w:val="none"/>
          <w:insideH w:val="none"/>
          <w:insideV w:val="none"/>
        </w:tblBorders>
      </w:tblPr>
      <w:tr>
        <!-- Pelaksana Block -->
        <w:tc>
          <w:tcPr><w:tcW w:w="4900" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr><w:t>Pelaksana,</w:t></w:r>
          </w:p>' . $this->tteOrNameBlock($tteImages, 'tte_pelaksana.png', $namaPelaksana, $nipPelaksana) . '
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Unit Pelaksana Teknis</w:t></w:r>
          </w:p>
        </w:tc>

        <!-- Yang Mengetahui Block -->
        <w:tc>
          <w:tcPr><w:tcW w:w="4900" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:b/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr><w:t>Yang Mengetahui,</w:t></w:r>
          </w:p>' . $this->tteOrNameBlock($tteImages, 'tte_diketahui.png', $namaDiketahui, $nipDiketahui) . '
          <w:p>
            <w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>
            <w:r><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr><w:t>Pengawas / Penanggung Jawab</w:t></w:r>
          </w:p>
        </w:tc>
      </w:tr>
    </w:tbl>

    <!-- SECTION PROPERTIES (A4 MARGINS) -->
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>
    </w:sectPr>

  </w:body>
</w:document>';

        return $xml;
    }
}
