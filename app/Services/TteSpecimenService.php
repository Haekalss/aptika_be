<?php

namespace App\Services;

use App\Models\Pegawai;
use GdImage;

/**
 * Membuat spesimen Tanda Tangan Elektronik (TTE) dari data master pegawai.
 *
 * Bentuk mengikuti template resmi:
 *
 *   Penata Muda/IIIa              <- pangkat/golongan, DI LUAR kotak
 *   +--------------------------+
 *   | Ditandatangani secara elektronik
 *   | oleh:
 *   | Pranata Komputer Terampil
 *   |                           <- 1 baris kosong
 *   | Ipul Syaifulloh, A.Md.
 *   | 199508242025211001        <- NIP tanpa prefix "NIP."
 *   +--------------------------+
 *
 * Semua teks regular (tidak tebal), tidak ada baris nama dinas.
 *
 * Gambar di-cache di storage/app/tte/ dengan nama berbasis hash isi, sehingga
 * satu pegawai = satu file, dan otomatis dibuat ulang bila datanya berubah.
 */
class TteSpecimenService
{
    /** Versi tata letak — dinaikkan bila bentuk spesimen berubah (memaksa cache baru). */
    private const LAYOUT_VERSION = 3;

    /** Ukuran kanvas (px), 3x dari template agar tajam saat dicetak. */
    private const WIDTH = 720;

    /** Kotak TTE (tinggi dihitung otomatis mengikuti isi). */
    private const BOX_X1 = 18;
    private const BOX_Y1 = 58;
    private const BOX_W = 684;

    private const RADIUS = 34;
    private const PAD_X = 60;

    /** Baseline awal isi kotak (relatif terhadap tepi atas kotak). */
    private const FIRST_LINE_Y = 63;
    /** Jarak antar baris pembuka. */
    private const PITCH_KALIMAT = 39;
    /** Jarak baris pembuka ke jabatan. */
    private const PITCH_KE_JABATAN = 45;
    /** Jarak antar baris jabatan (bila dibungkus). */
    private const PITCH_JABATAN = 40;
    /** Jarak jabatan terakhir ke nama. */
    private const JARAK_KE_NAMA = 108;
    /** Jarak nama ke NIP. */
    private const JARAK_KE_NIP = 33;
    /** Ruang kosong di bawah NIP. */
    private const PAD_BAWAH = 54;

    private const FONT_SIZE = 28;

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

        // "Pembina / IVa" -> "Pembina/IVa" (tanpa spasi di sekitar garis miring)
        $pangkatLine = preg_replace('#\s*/\s*#', '/', $pangkat) ?? $pangkat;

        return [
            'v' => self::LAYOUT_VERSION,
            'nama' => $nama,
            'nip' => $nip,
            'jabatan' => $jabatan,
            'pangkat_line' => $pangkatLine,
        ];
    }

    /** Gambar blok TTE ke kanvas GD sesuai template. */
    private function render(array $d): ?GdImage
    {
        $font = $this->fontPath('regular');
        if ($font === null) {
            return null;
        }

        $availW = self::BOX_W - self::PAD_X * 2;

        // --- Susun baris lebih dulu (jabatan bisa terbungkus) ---
        $lines = [];
        $y = self::FIRST_LINE_Y;

        $lines[] = ['text' => 'Ditandatangani secara elektronik', 'y' => $y];
        $y += self::PITCH_KALIMAT;

        $lines[] = ['text' => 'oleh:', 'y' => $y];
        $y += self::PITCH_KE_JABATAN;

        $jabatanLines = $this->wrapText($d['jabatan'], $font, self::FONT_SIZE, $availW);
        foreach ($jabatanLines as $i => $jl) {
            $lines[] = ['text' => $jl, 'y' => $y];
            if ($i < count($jabatanLines) - 1) {
                $y += self::PITCH_JABATAN;
            }
        }

        // Jabatan kosong -> tetap beri jarak satu baris
        if ($jabatanLines === []) {
            $y += self::PITCH_JABATAN;
        }

        $namaY = $y + self::JARAK_KE_NAMA;
        $nipY = $namaY + self::JARAK_KE_NIP;

        if ($d['nama'] !== '') {
            $lines[] = ['text' => $d['nama'], 'y' => $namaY];
        }
        if ($d['nip'] !== '') {
            $lines[] = ['text' => $d['nip'], 'y' => $nipY];
        }

        $boxH = $nipY + self::PAD_BAWAH;
        $canvasH = self::BOX_Y1 + $boxH + 18;

        // --- Kanvas ---
        $img = imagecreatetruecolor(self::WIDTH, $canvasH);
        if (!$img) {
            return null;
        }

        imagesavealpha($img, true);
        $bg = imagecolorallocate($img, ...self::COLOR_BG);
        imagefill($img, 0, 0, $bg);

        $text = imagecolorallocate($img, ...self::COLOR_TEXT);
        $border = imagecolorallocate($img, ...self::COLOR_BORDER);

        $x = self::BOX_X1 + self::PAD_X;

        // --- Pangkat/golongan di ATAS kotak ---
        if ($d['pangkat_line'] !== '') {
            imagettftext($img, self::FONT_SIZE, 0, $x, 30, $text, $font, $d['pangkat_line']);
        }

        // --- Kotak rounded ---
        $x1 = self::BOX_X1;
        $y1 = self::BOX_Y1;
        $x2 = $x1 + self::BOX_W;
        $y2 = $y1 + $boxH;
        $r = self::RADIUS;

        imagesetthickness($img, 3);

        imageline($img, $x1 + $r, $y1, $x2 - $r, $y1, $border);
        imageline($img, $x1 + $r, $y2, $x2 - $r, $y2, $border);
        imageline($img, $x1, $y1 + $r, $x1, $y2 - $r, $border);
        imageline($img, $x2, $y1 + $r, $x2, $y2 - $r, $border);

        imagearc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $border);
        imagearc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $border);
        imagearc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $border);
        imagearc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $border);

        imagesetthickness($img, 1);

        // --- Isi kotak ---
        foreach ($lines as $line) {
            $value = trim((string) $line['text']);
            if ($value === '') {
                continue;
            }
            imagettftext($img, self::FONT_SIZE, 0, $x, $y1 + $line['y'], $text, $font, $value);
        }

        return $img;
    }

    /**
     * Bungkus teks menjadi beberapa baris agar tidak melewati lebar kotak.
     *
     * @return string[]
     */
    private function wrapText(string $text, string $font, int $size, int $maxW): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $cur = '';

        foreach ($words as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $try);
            $w = abs($box[2] - $box[0]);

            if ($w <= $maxW || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }

        if ($cur !== '') {
            $lines[] = $cur;
        }

        // Bila hasilnya dua baris, pilih titik potong yang paling seimbang
        // (mis. "Kepala Bidang Aplikasi" / "dan Informatika" lebih baik
        // daripada "Kepala Bidang Aplikasi dan" / "Informatika").
        if (count($lines) === 2 && count($words) > 2) {
            // Kata yang tidak layak mengawali baris baru.
            $janganDiAwal = ['dan', 'atau', 'serta', 'di', 'ke', 'dari', 'pada', 'untuk', 'yang', 'dengan', 'oleh'];

            $best = null;
            $bestScore = PHP_INT_MAX;

            for ($i = 1; $i < count($words); $i++) {
                $a = implode(' ', array_slice($words, 0, $i));
                $b = implode(' ', array_slice($words, $i));

                $ba = imagettfbbox($size, 0, $font, $a);
                $bb = imagettfbbox($size, 0, $font, $b);
                $wa = abs($ba[2] - $ba[0]);
                $wb = abs($bb[2] - $bb[0]);

                if ($wa > $maxW || $wb > $maxW) {
                    continue;
                }

                $score = abs($wa - $wb);

                // Hindari baris kedua diawali kata sambung.
                $kataPertama = strtolower($words[$i]);
                if (in_array($kataPertama, $janganDiAwal, true)) {
                    $score += 200;
                }

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = [$a, $b];
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return $lines;
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
