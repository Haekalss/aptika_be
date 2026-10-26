<?php

namespace App\Services;

use App\Models\Pegawai;

/**
 * Menentukan sumber spesimen TTE untuk sebuah dokumen.
 *
 * Sumber data tunggal: master pegawai (tabel pegawai).
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

        $pegawai = Pegawai::query()
            ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
            ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
            ->first();

        // Bila NIP tidak cocok, coba lewat nama sebagai cadangan.
        if (!$pegawai && $nip !== '' && $nama !== '') {
            $pegawai = Pegawai::query()->where('nama', 'like', "%{$nama}%")->first();
        }

        return $pegawai;
    }

    /** Path file PNG spesimen, atau null bila tidak ada / gagal render. */
    public function imagePath(string $nip, string $nama): ?string
    {
        $pegawai = $this->find($nip, $nama);
        if ($pegawai === null) {
            return null;
        }

        return $this->renderer->generate($pegawai, null);
    }
}