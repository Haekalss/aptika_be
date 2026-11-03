<?php

namespace App\Services\Smki;

use App\Models\SmkiDaftarAsetVirtual;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DaftarAsetVirtualService
{
    private const INSTANCE_FIELDS = [
        'kode_aset',
        'nama_instance',
        'deskripsi',
        'tipe_virtualisasi',
        'alamat',
        'hostname',
        'spesifikasi',
        'pemilik',
        'klasifikasi',
    ];

    public function save(?SmkiDaftarAsetVirtual $form, array $data, User $user): SmkiDaftarAsetVirtual
    {
        return DB::transaction(function () use ($form, $data, $user) {
            $current = $form
                ? SmkiDaftarAsetVirtual::query()->lockForUpdate()->findOrFail($form->id)
                : new SmkiDaftarAsetVirtual;

            if (! $form) {
                $current->fill([
                    'bidang_id' => $user->bidang_id,
                    'created_by' => $user->id,
                    'unit_kerja_nama' => $user->bidang->name,
                    'created_by_nama' => $user->name,
                ]);
            }

            $current->fill(Arr::only($data, [
                'no_dokumen',
                'no_revisi',
                'tanggal_berlaku',
                'kode_aset',
                'nama_aset',
                'lokasi_aset',
            ]));
            $current->status = $data['status'] ?? $current->status ?? 'Draf';
            $current->save();

            if (array_key_exists('rows', $data)) {
                $rows = collect($data['rows'])
                    ->filter(fn (array $row) => collect(Arr::only($row, self::INSTANCE_FIELDS))
                        ->contains(fn ($value) => is_string($value) && trim($value) !== ''))
                    ->values()
                    ->map(function (array $row, int $index) use ($data) {
                        $attributes = Arr::only($row, self::INSTANCE_FIELDS);
                        $attributes['urutan'] = $index + 1;
                        $attributes['kode_aset'] = ($attributes['kode_aset'] ?? null) ?: $this->sequentialAssetCode(
                            $data['kode_aset'] ?? '',
                            $index,
                        );

                        return $attributes;
                    });

                $current->instances()->delete();
                $current->instances()->createMany($rows->all());
            }

            return $current->load('instances');
        });
    }

    public function delete(SmkiDaftarAsetVirtual $form): void
    {
        DB::transaction(function () use ($form) {
            $current = SmkiDaftarAsetVirtual::query()->lockForUpdate()->findOrFail($form->id);
            $current->delete();
        });
    }

    private function sequentialAssetCode(string $baseCode, int $index): ?string
    {
        if (! preg_match('/^(\d+)(\/.*)$/', trim($baseCode), $matches)) {
            return null;
        }

        return str_pad((string) ((int) $matches[1] + $index), strlen($matches[1]), '0', STR_PAD_LEFT).$matches[2];
    }
}
