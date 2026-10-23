<?php

namespace App\Services;

use App\Models\Pegawai;
use App\Models\TteSpesimen;

/**
 * Menentukan sumber spesimen TTE untuk sebuah dokumen.
 *
 * Urutan: master spesimen TTE (aktif) → fallback master pegawai.
 * Dipakai bersama oleh endpoint API dan ekspor DOCX agar hasilnya konsisten.
 */
class TteResolverService
{
    public function __construct(private TteSpecimenService $renderer)
    {
    }

    /** Cari data sumber (model) berdasarkan NIP/nama. */
    public function find(string $nip, string $nama): ?array
    {
        $nip = trim($nip);
        $nama = trim($nama);

        if ($nip === '' && $nama === '') {
            return null;
        }

        $master = TteSpesimen::query()
            ->where('is_active', true)
            ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
            ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
            ->first();

        if (!$master && $nip !== '' && $nama !== '') {
            $master = TteSpesimen::query()
                ->where('is_active', true)
                ->where('nama', 'like', "%{$nama}%")
                ->first();
        }

        if ($master) {
            return ['source' => 'master', 'model' => $master];
        }

        $pegawai = Pegawai::query()
            ->when($nip !== '', fn ($q) => $q->where('nip', $nip))
            ->when($nip === '' && $nama !== '', fn ($q) => $q->where('nama', 'like', "%{$nama}%"))
            ->first();

        if (!$pegawai && $nip !== '' && $nama !== '') {
            $pegawai = Pegawai::query()->where('nama', 'like', "%{$nama}%")->first();
        }

        if ($pegawai) {
            return ['source' => 'pegawai', 'model' => $pegawai];
        }

        return null;
    }

    /** Path file PNG spesimen, atau null bila tidak ada / gagal render. */
    public function imagePath(string $nip, string $nama): ?string
    {
        $found = $this->find($nip, $nama);
        if ($found === null) {
            return null;
        }

        $model = $found['model'];
        $unit = $found['source'] === 'master' ? ($model->unit ?? null) : null;

        return $this->renderer->generate($model, $unit);
    }
}
