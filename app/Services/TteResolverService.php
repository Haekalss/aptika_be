<?php

namespace App\Services;

use App\Models\Pegawai;
use App\Models\User;
use Throwable;

/**
 * Menentukan sumber spesimen TTE untuk sebuah dokumen.
 *
 * Sumber data: master pegawai (tabel pegawai) dengan fallback ke tabel user / nama penanda tangan.
 * Dipakai bersama oleh endpoint API dan ekspor DOCX agar hasilnya konsisten.
 */
class TteResolverService
{
    public function __construct(private TteSpecimenService $renderer)
    {
    }

    /** Cari pegawai berdasarkan NIP/nama untuk dijadikan penanda tangan. */
    public function find(string $nip, string $nama): ?Pegawai
    {
        $nip = trim($nip);
        $nama = trim($nama);

        if ($nip === '' && $nama === '') {
            return null;
        }

        try {
            $pegawai = Pegawai::query()
                ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
                ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
                ->first();

            // Bila NIP tidak cocok, coba lewat nama sebagai cadangan.
            if (!$pegawai && $nip !== '' && $nama !== '') {
                $pegawai = Pegawai::query()->where('nama', 'like', "%{$nama}%")->first();
            }

            return $pegawai;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Path file PNG spesimen, atau null bila tidak ada / gagal render. */
    public function imagePath(string $nip, string $nama): ?string
    {
        $nip = trim($nip);
        $nama = trim($nama);

        if ($nip === '' && $nama === '') {
            return null;
        }

        // Bersihkan nama dari imbuhan status seperti " (Draft / Menunggu Validasi)"
        $searchNama = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $nama));
        if ($searchNama === '') {
            $searchNama = $nama;
        }

        $pegawai = $this->find($nip, $searchNama);
        if ($pegawai !== null) {
            return $this->renderer->generate($pegawai, null);
        }

        // Fallback: Jika tidak ditemukan di master pegawai, coba cari di tabel users atau buat spesimen dari nama
        if ($searchNama !== '') {
            $user = null;
            try {
                $user = User::where('name', 'like', "%{$searchNama}%")->first();
            } catch (Throwable $e) {
                $user = null;
            }

            $jabatan = $user?->position ?? 'Pengelola Sistem Informasi / SMKI';
            $payload = [
                'nama' => $user ? $user->name : $searchNama,
                'nip' => $nip ?: ($user?->nip ?? '-'),
                'jabatan' => $jabatan,
                'pangkat' => 'Penata Muda / IIIa',
            ];

            return $this->renderer->generate($payload, null);
        }

        return null;
    }
}
