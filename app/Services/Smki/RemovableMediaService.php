<?php

namespace App\Services\Smki;

use App\Models\AsetTiMerek;
use App\Models\AsetTiNama;
use App\Models\AsetTiPenanggungJawab;
use App\Models\SmkiRemovableMediaReport;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RemovableMediaService
{
    public function save(?SmkiRemovableMediaReport $report, array $data, User $user): SmkiRemovableMediaReport
    {
        return DB::transaction(function () use ($report, $data, $user) {
            $current = $report ? SmkiRemovableMediaReport::whereKey($report->id)->lockForUpdate()->firstOrFail() : new SmkiRemovableMediaReport;
            if ($report) {
                $this->assertVersion($current, $data['version']);
                // Validation used the bound record's status; reject any intervening save.
                $this->assertVersion($current, $report->version);
            } else {
                $current->fill([
                    'bidang_id' => $user->bidang_id, 'unit_kerja_nama' => $user->bidang->name,
                    'created_by' => $user->id, 'created_by_nama' => $user->name,
                ]);
            }

            $current->fill(Arr::only($data, ['nomor_dokumen', 'nomor_revisi', 'tanggal_berlaku', 'tanggal_dokumen', 'tempat', 'pengurus_barang_user_id', 'pengurus_barang_nama', 'kepala_unit_user_id', 'kepala_unit_nama']));
            foreach (['pengurus_barang', 'kepala_unit'] as $person) {
                if ($current->{$person.'_user_id'}) {
                    $current->{$person.'_nama'} = User::findOrFail($current->{$person.'_user_id'})->name;
                }
            }
            $current->status = $current->status === 'final' || ($data['finalize'] ?? false) ? 'final' : 'draft';
            $current->finalized_at = $current->status === 'final' ? ($current->finalized_at ?? now()) : null;
            $current->version = $report ? $current->version + 1 : 1;
            $current->save();

            // Resolve masters in batches; snapshot values keep archived documents stable.
            $names = AsetTiNama::whereIn('id', array_filter(array_column($data['items'], 'nama_barang_id')))->pluck('nama_aset', 'id');
            $brands = AsetTiMerek::whereIn('id', array_filter(array_column($data['items'], 'merek_id')))->pluck('nama_merek', 'id');
            $pics = AsetTiPenanggungJawab::whereIn('id', array_filter(array_column($data['items'], 'pic_id')))->pluck('nama_pj', 'id');
            $items = collect($data['items'])->values()->map(function (array $item, int $index) use ($names, $brands, $pics) {
                $item['urutan'] = $index + 1;
                $item['nama_barang'] = $names[$item['nama_barang_id'] ?? null] ?? ($item['nama_barang'] ?? null);
                $item['merek'] = $brands[$item['merek_id'] ?? null] ?? ($item['merek'] ?? null);
                $item['pic_nama'] = $pics[$item['pic_id'] ?? null] ?? ($item['pic_nama'] ?? null);

                return $item;
            });
            $current->items()->delete();
            $current->items()->createMany($items->all());

            return $current->load('items');
        });
    }

    public function delete(SmkiRemovableMediaReport $report, int $version): void
    {
        DB::transaction(function () use ($report, $version) {
            $current = SmkiRemovableMediaReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($current, $version);
            $current->delete();
        });
    }

    private function assertVersion(SmkiRemovableMediaReport $report, int $version): void
    {
        abort_if($report->version !== $version, 409, 'Dokumen sudah berubah. Muat ulang sebelum menyimpan agar perubahan lain tidak tertimpa.');
    }
}
