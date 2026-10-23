<?php

namespace App\Services;

use App\Models\Pegawai;
use GdImage;

/**
 * Membuat spesimen Tanda Tangan Elektronik (TTE) dari data master pegawai.
 *
 * Spesimen mengikuti bentuk blok TTE resmi (lihat resources/templates/tte_kabid_aptika.png):
 * kotak border hitam rounded, teks "Ditandatangani secara elektronik oleh:",
 * jabatan, unit kerja, nama + gelar, dan NIP.
 *
 * Gambar di-cache di storage/app/tte/ dengan nama berbasis hash isi, sehingga
 * satu pegawai = satu file, dan otomatis dibuat ulang bila datanya berubah.
 */
class TteSpecimenService
{
    /** Ukuran kanvas (px). Skala 2x dari tte_kabid_aptika.png agar tajam saat dicetak. */
    private const WIDTH = 962;
    private const HEIGHT = 370;

    private const MARGIN = 10;
    private const RADIUS = 18;
    private const PAD_X = 34;
    private const PAD_Y = 26;

    private const COLOR_TEXT = [0, 0, 0];
    private const COLOR_BORDER = [0, 0, 0];
    private const COLOR_BG = [255, 255, 255];

    /**
     * Bangkitkan (atau ambil dari cache) gambar TTE untuk seorang pegawai.
     *
     * @param  Pegawai|array  $pegawai  Model/array berisi nama, nip, jabatan, pangkat.
     * @param  string|null    $unit     Nama unit/dinas. Default: "DINAS KOMUNIKASI DAN INFORMATIKA".
     * @return string|null    Path absolut file PNG, atau null bila GD tidak tersedia.
     */
    public function generate($pegawai, ?string $unit = null): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $data = $this->normalize($pegawai, $unit);
        $hash = substr(sha1(json_encode($data)), 0, 16);

        $dir = storage_path('app/tte');
        $path = $dir . DIRECTORY_SEPARATOR . 'tte_' . $hash . '.png';

        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $image = $this->render($data);
        if (!$image) {
            return null;
        }

        imagepng($image, $path);
        imagedestroy($image);

        return is_file($path) ? $path : null;
    }

    /**
     * Render tanpa cache — berguna untuk preview di browser.
     */
    public function renderToBinary($pegawai, ?string $unit = null): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $image = $this->render($this->normalize($pegawai, $unit));
        if (!$image) {
            return null;
        }

        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        return $binary ?: null;
    }

    /** Rapikan & lengkapi data pegawai sebelum digambar. */
    private function normalize($pegawai, ?string $unit): array
    {
        $get = static function (string $key, $default = '') use ($pegawai) {
            if (is_array($pegawai)) {
                return $pegawai[$key] ?? $default;
            }

            return $pegawai->{$key} ?? $default;
        };

        $nama = trim((string) $get('nama'));
        $nip = trim((string) $get('nip'));
        $jabatan = trim((string) $get('jabatan'));
        $pangkat = trim((string) $get('pangkat'));

        // "Pembina / IVa" -> "Pembina" + "IVa"
        $golongan = '';
        if (str_contains($pangkat, '/')) {
            [$pangkatUtama, $golongan] = array_map('trim', explode('/', $pangkat, 2));
        } else {
            $pangkatUtama = $pangkat;
        }

        return [
            'nama' => $nama,
            'nip' => $nip,
            'jabatan' => $jabatan !== '' ? mb_strtoupper($jabatan) : 'KEPALA BIDANG APLIKASI INFORMATIKA',
            'unit' => mb_strtoupper(trim((string) ($unit ?? 'DINAS KOMUNIKASI DAN INFORMATIKA'))),
            'pangkat' => $pangkatUtama,
            'golongan' => $golongan,
        ];
    }

    /** Gambar blok TTE ke kanvas GD. */
    private function render(array $d): ?GdImage
    {
        $fontRegular = $this->fontPath('regular');
        $fontBold = $this->fontPath('bold');

        if ($fontRegular === null) {
            return null;
        }
        $fontBold = $fontBold ?? $fontRegular;

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$img) {
            return null;
        }

        imagesavealpha($img, true);
        $bg = imagecolorallocate($img, ...self::COLOR_BG);
        imagefill($img, 0, 0, $bg);

        $text = imagecolorallocate($img, ...self::COLOR_TEXT);
        $border = imagecolorallocate($img, ...self::COLOR_BORDER);

        // --- Border rounded ---
        $x1 = self::MARGIN;
        $y1 = self::MARGIN;
        $x2 = self::WIDTH - self::MARGIN;
        $y2 = self::HEIGHT - self::MARGIN;
        $r = self::RADIUS;

        imagesetthickness($img, 4);

        // sisi lurus
        imageline($img, $x1 + $r, $y1, $x2 - $r, $y1, $border);
        imageline($img, $x1 + $r, $y2, $x2 - $r, $y2, $border);
        imageline($img, $x1, $y1 + $r, $x1, $y2 - $r, $border);
        imageline($img, $x2, $y1 + $r, $x2, $y2 - $r, $border);

        // sudut membulat
        imagearc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $border);
        imagearc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $border);
        imagearc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $border);
        imagearc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $border);

        imagesetthickness($img, 1);

        // --- Teks (rata kiri, menjorok dari border) ---
        $x = $x1 + self::PAD_X;
        $y = $y1 + self::PAD_Y;

        $lines = [
            ['text' => 'Ditandatangani secara elektronik oleh:', 'size' => 13, 'bold' => false, 'gap' => 44],
            ['text' => $d['jabatan'], 'size' => 15, 'bold' => true, 'gap' => 36],
            ['text' => $d['unit'] . ',', 'size' => 15, 'bold' => true, 'gap' => 78],
            ['text' => $d['nama'], 'size' => 17, 'bold' => true, 'gap' => 32],
            ['text' => $d['nip'] !== '' ? 'NIP. ' . $d['nip'] : '', 'size' => 13, 'bold' => false, 'gap' => 0],
        ];

        foreach ($lines as $line) {
            if ($line['text'] === '') {
                continue;
            }
            $font = $line['bold'] ? $fontBold : $fontRegular;
            imagettftext($img, $line['size'], 0, $x, $y, $text, $font, $line['text']);
            $y += $line['gap'];
        }

        return $img;
    }

    /** Cari font TTF yang tersedia (bundled dulu, baru font sistem). */
    private function fontPath(string $weight): ?string
    {
        $bundled = resource_path('fonts/LiberationSans-' . ($weight === 'bold' ? 'Bold' : 'Regular') . '.ttf');

        $candidates = $weight === 'bold'
            ? [$bundled, 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
            : [$bundled, 'C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
