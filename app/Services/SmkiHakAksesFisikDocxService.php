<?php

namespace App\Services;

use App\Models\SmkiHakAksesFisik;
use App\Services\Concerns\FillsDocxTemplate;
use App\Services\TteResolverService;
use DOMDocument;
use DOMXPath;
use Exception;
use ZipArchive;

class SmkiHakAksesFisikDocxService
{
    use FillsDocxTemplate;

    public function __construct(
        private ?TteResolverService $tteResolver = null
    ) {
    }

    public static function getTemplatePath(): string
    {
        $possiblePaths = [
            resource_path('templates/FR-019 Formulir Hak Akses Fisik.docx'),
            base_path('resources/templates/FR-019 Formulir Hak Akses Fisik.docx'),
            'd:/DISKOMINFO/FR-019 Formulir Hak Akses Fisik.docx',
            'D:/DISKOMINFO/aptika_be/resources/templates/FR-019 Formulir Hak Akses Fisik.docx',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-019 tidak ditemukan di resources/templates.');
    }

    /**
     * Generate file DOCX terisi berdasarkan model SmkiHakAksesFisik.
     *
     * @param SmkiHakAksesFisik $form
     * @return string Path file sementara (temporary docx file)
     */
    public function generateDocx(SmkiHakAksesFisik $form): string
    {
        $templatePath = self::getTemplatePath();

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $safeName   = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $form->nama_pemohon ?: 'Pemohon');
        $outputPath = $tempDir . '/FR-019_' . $safeName . '_' . uniqid() . '.docx';

        if (!copy($templatePath, $outputPath)) {
            throw new Exception('Gagal menyalin file template FR-019.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new Exception('Gagal membuka file DOCX FR-019 untuk diedit.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if (!$xml) {
            $zip->close();
            throw new Exception('Format dokumen tidak valid: word/document.xml tidak ditemukan.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;
        @$dom->loadXML($xml);

        $xp = $this->docxXPath($dom);

        // Helper string checkboxes
        $ruanganList = is_array($form->ruangan_akses) ? $form->ruangan_akses : [];
        $keperluanList = is_array($form->keperluan_alasan) ? $form->keperluan_alasan : [];

        $isPendaftaran = strtolower($form->jenis_permintaan ?? '') === 'pendaftaran' || empty($form->jenis_permintaan);
        $isPenutupan   = strtolower($form->jenis_permintaan ?? '') === 'penutupan';

        $cb = fn(bool $checked) => $checked ? '☑ ' : '□ ';

        // Kebutuhan penggantian teks di paragraf
        $paras = $xp->query('//w:p');
        $pMap = [];
        foreach ($paras as $idx => $p) {
            $runs = $xp->query('w:r/w:t', $p);
            $txt = '';
            foreach ($runs as $r) {
                $txt .= $r->textContent;
            }
            $pMap[$idx] = [
                'node' => $p,
                'text' => trim($txt),
            ];
        }

        // Cari paragraf berdasarkan teks untuk penggantian tepat
        foreach ($pMap as $idx => $item) {
            $p    = $item['node'];
            $text = $item['text'];

            // 1. Header No Dokumen
            if (str_contains($text, 'FR-019//KOM.03.05/ SANDIKAMI') || str_contains($text, 'FR-019//KOM.03.05')) {
                $this->setParagraphText($dom, $xp, $p, $form->header_no_dokumen ?: 'FR-019//KOM.03.05/ SANDIKAMI');
            } elseif ($text === '1.1') {
                $this->setParagraphText($dom, $xp, $p, $form->header_no_revisi ?: '1.1');
            } elseif (str_contains($text, '07 Juli 2022')) {
                $tglBerlaku = $form->header_tanggal_berlaku ? $this->tanggalIndo($form->header_tanggal_berlaku) : '07 Juli 2022';
                $this->setParagraphText($dom, $xp, $p, $tglBerlaku);
            }

            // 2. Baris Data Pemohon
            // Nama
            elseif ($text === 'Nama') {
                // Paragraf nilai ada di $idx + 2
                if (isset($pMap[$idx + 2])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $form->nama_pemohon ?: '-');
                }
            }
            // Jabatan
            elseif ($text === 'Jabatan') {
                if (isset($pMap[$idx + 2])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $form->jabatan ?: '-');
                }
            }
            // Tanggal
            elseif ($text === 'Tanggal') {
                if (isset($pMap[$idx + 2])) {
                    $tglPengajuan = $form->tanggal_pengajuan ? $this->tanggalIndo($form->tanggal_pengajuan) : $this->tanggalIndo(now());
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $tglPengajuan);
                }
            }
            // Unit Kerja
            elseif (str_contains($text, 'Unit Kerja/Penyedia')) {
                if (isset($pMap[$idx + 2])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $form->unit_kerja ?: '-');
                }
            }
            // No HP
            elseif ($text === 'No. HP') {
                if (isset($pMap[$idx + 2])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $form->no_handphone ?: '-');
                }
            }
            // Email
            elseif ($text === 'Email') {
                if (isset($pMap[$idx + 2])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 2]['node'], $form->email ?: '-');
                }
            }

            // 3. Jenis Permintaan
            elseif (str_contains($text, 'Jenis Permintaan') && str_contains($text, 'Pendaftaran')) {
                $strJenis = "Jenis Permintaan :   " . $cb($isPendaftaran) . "Pendaftaran   " . $cb($isPenutupan) . "Penutupan";
                $this->setParagraphText($dom, $xp, $p, $strJenis);
            }

            // 4. Jenis Akses / Ruangan
            elseif (str_contains($text, 'Data Center') && str_contains($text, 'Ruang NOC')) {
                $hasDC     = in_array('Data Center', $ruanganList, true);
                $hasNOC    = in_array('Ruang NOC', $ruanganList, true);
                $hasUPS    = in_array('UPS', $ruanganList, true);
                $hasGensetG= in_array('Akses Genset Gedung', $ruanganList, true);

                $strRuang1 = $cb($hasDC) . "Data Center   " . $cb($hasNOC) . "Ruang NOC   " . $cb($hasUPS) . "UPS   " . $cb($hasGensetG) . "Akses Genset Gedung";
                $this->setParagraphText($dom, $xp, $p, $strRuang1);
            }
            elseif (str_contains($text, 'Pintu Utama') && str_contains($text, 'Ruang SOC')) {
                $hasPintu   = in_array('Pintu Utama', $ruanganList, true);
                $hasSOC     = in_array('Ruang SOC', $ruanganList, true);
                $hasGensetDC= in_array('Akses Genset DC', $ruanganList, true);

                $strRuang2 = $cb($hasPintu) . "Pintu Utama   " . $cb($hasSOC) . "Ruang SOC   " . $cb($hasGensetDC) . "Akses Genset DC";
                $this->setParagraphText($dom, $xp, $p, $strRuang2);
            }

            // 5. Keperluan & Alasan
            elseif (str_contains($text, 'Instansi') && str_contains($text, 'Monitoring')) {
                $hasInstansi   = in_array('Instansi', $keperluanList, true);
                $hasMonitoring = in_array('Monitoring', $keperluanList, true);
                $hasPerawatan  = in_array('Perawatan', $keperluanList, true);

                $strKep1 = $cb($hasInstansi) . "Instansi   " . $cb($hasMonitoring) . "Monitoring   " . $cb($hasPerawatan) . "Perawatan";
                $this->setParagraphText($dom, $xp, $p, $strKep1);
            }
            elseif (str_contains($text, 'Lainnya : Pindah Tugas')) {
                $hasLainnya = in_array('Lainnya', $keperluanList, true) || !empty($form->keperluan_lainnya);
                $ketLainnya = $form->keperluan_lainnya ?: 'Pindah Tugas /Mutasi OPD lain';
                $strKep2    = $cb($hasLainnya) . "Lainnya : " . ($hasLainnya ? $ketLainnya : "Pindah Tugas /Mutasi OPD lain");
                $this->setParagraphText($dom, $xp, $p, $strKep2);
            }

            // 6. Sifat Akses
            elseif (str_contains($text, 'Permanen') && str_contains($text, 'Sementara')) {
                $isPermanen  = strtolower($form->sifat_akses ?? '') === 'permanen' || empty($form->sifat_akses);
                $isSementara = strtolower($form->sifat_akses ?? '') === 'sementara';
                $strSifat    = $cb($isPermanen) . "Permanen   " . $cb($isSementara) . "Sementara";
                $this->setParagraphText($dom, $xp, $p, $strSifat);
            }

            // 7. Waktu Akses
            elseif (str_contains($text, '7 x 24 jam') && str_contains($text, '08.00')) {
                $waktu = $form->waktu_akses ?: '7 x 24 jam';
                $is7x24 = str_contains($waktu, '7 x 24') || str_contains($waktu, '24 jam');
                $isJamKerja = str_contains($waktu, '08.00') || str_contains(strtolower($waktu), 'kerja');
                $isWaktuLain = !$is7x24 && !$isJamKerja;

                $strWaktu = $cb($is7x24) . "7 x 24 jam   " . $cb($isJamKerja) . "08.00 – 17.00   " . $cb($isWaktuLain) . "Lainnya : " . ($isWaktuLain ? ($form->waktu_akses_lainnya ?: $waktu) : "………………………..");
                $this->setParagraphText($dom, $xp, $p, $strWaktu);
            }
            elseif (str_contains($text, 'Hal Akses ini berlaku sampai tanggal') || str_contains($text, 'berlaku sampai tanggal')) {
                $tglSampai = $form->berlaku_sampai ? $this->tanggalIndo($form->berlaku_sampai) : '……………………………….';
                $strSampai = $cb(!empty($form->berlaku_sampai)) . "Hal Akses ini berlaku sampai tanggal : " . $tglSampai;
                $this->setParagraphText($dom, $xp, $p, $strSampai);
            }

            // 8. Tanda Tangan (Nama dalam tanda kurung)
            elseif ($text === 'Pemohon,') {
                if (isset($pMap[$idx + 7])) {
                    $namaPemohon = $form->pemohon_nama ?: $form->nama_pemohon;
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 7]['node'], "( " . ($namaPemohon ?: '……………………………') . " )", 'center');
                    // Pemohon menggunakan tanda tangan basah (slot dibiarkan kosong)
                }
            }
            elseif ($text === 'Atasan Pemohon,') {
                $namaAtasan = $form->atasan_nama;
                $tteOk = isset($pMap[$idx + 3])
                    ? $this->tryInsertTte($zip, $dom, $xp, $pMap[$idx + 3]['node'], $form->atasan_nip, $namaAtasan, 'Atasan Langsung Pemohon')
                    : false;
                if (isset($pMap[$idx + 7])) {
                    // Bila spesimen tersisip, baris nama dikosongkan (hanya spesimen).
                    // Bila tanpa spesimen, nama tetap ditampilkan.
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 7]['node'], $tteOk ? '' : "( " . ($namaAtasan ?: '……………………………') . " )", 'center');
                }
            }
            elseif ($text === 'Penanggung Jawab Perangkat,') {
                $namaPJ = $form->penanggung_jawab_nama;
                $tteOk = isset($pMap[$idx + 3])
                    ? $this->tryInsertTte($zip, $dom, $xp, $pMap[$idx + 3]['node'], $form->penanggung_jawab_nip, $namaPJ, 'Penanggung Jawab Perangkat')
                    : false;
                if (isset($pMap[$idx + 7])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 7]['node'], $tteOk ? '' : "( " . ($namaPJ ?: '……………………………') . " )", 'center');
                }
            }
            elseif ($text === 'Agen,') {
                $namaAgen = $form->agen_nama;
                $tteOk = isset($pMap[$idx + 3])
                    ? $this->tryInsertTte($zip, $dom, $xp, $pMap[$idx + 3]['node'], $form->agen_nip, $namaAgen, 'Agen Pelaksana')
                    : false;
                if (isset($pMap[$idx + 7])) {
                    $this->setParagraphText($dom, $xp, $pMap[$idx + 7]['node'], $tteOk ? '' : "( " . ($namaAgen ?: '……………………………') . " )", 'center');
                }
            }
        }

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $outputPath;
    }

    /**
     * Sisipkan gambar spesimen TTE dinamis resmi sesuai NIP dan Nama pejabat/pemohon.
     *
     * @return bool true bila gambar berhasil disisipkan (dipakai untuk
     * mengosongkan baris nama di luar spesimen), false bila tidak.
     */
    private function tryInsertTte(ZipArchive $zip, DOMDocument $dom, DOMXPath $xp, $paragraphNode, ?string $nip, ?string $nama, ?string $fallbackJabatan = null): bool
    {
        $nip = trim((string) $nip);
        $nama = trim((string) $nama);

        if ($nip === '' && $nama === '') {
            return false;
        }

        try {
            $resolver = app(\App\Services\TteResolverService::class);
            $renderer = app(\App\Services\TteSpecimenService::class);

            // 1. Cek apakah ada di master pegawai
            $pegawai = $resolver->find($nip, $nama);
            if ($pegawai !== null) {
                $imgPath = $renderer->generate($pegawai, null, false);
            } else {
                // 2. Jika bukan pegawai (misal vendor luar / user baru), buat spesimen dinamis dengan data namanya
                $cleanNama = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $nama));
                $payload = [
                    'nama' => $cleanNama ?: $nama,
                    'nip' => $nip !== '' ? $nip : '-',
                    'jabatan' => $fallbackJabatan ?: 'Pemohon / Pelaksana Akses',
                    'pangkat' => '',
                ];
                $imgPath = $renderer->generate($payload, null, false);
            }

            if ($imgPath && file_exists($imgPath)) {
                // FR-019: kosongkan slot tanda tangan lebih dulu sehingga
                // di dalam kotak hanya ada gambar spesimen saja.
                while ($paragraphNode->hasChildNodes()) {
                    $paragraphNode->removeChild($paragraphNode->firstChild);
                }

                // Paragraf gambar rata tengah (pPr baru, karena yang lama ikut dibuang).
                $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
                $paragraphNode->appendChild($pPr);
                $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
                $jc->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', 'center');
                $pPr->appendChild($jc);

                // Sisipkan gambar spesimen
                return $this->insertImageIntoParagraph($zip, $dom, $xp, $paragraphNode, $imgPath, 160);
            }
        } catch (\Throwable $e) {
            // Ignore error agar proses docx tetap berjalan lancar
        }

        return false;
    }
}
