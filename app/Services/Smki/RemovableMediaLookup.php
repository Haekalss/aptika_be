<?php

namespace App\Services\Smki;

use App\Models\AsetTiMerek;
use App\Models\AsetTiNama;
use App\Models\SmkiRemovableMediaReport;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RemovableMediaLookup
{
    public function allowedPics(int $bidangId, ?SmkiRemovableMediaReport $report = null): Builder
    {
        return DB::table('aset_ti_penanggung_jawabs')->where(function (Builder $query) use ($bidangId, $report) {
            $query->whereIn('id', DB::table('daftar_aset_tis')->select('penanggung_jawab_id')
                ->where('bidang_id', $bidangId)->whereNull('deleted_at'));
            if ($report) {
                $query->orWhereIn('id', $report->items()->select('pic_id')->toBase());
            }
        });
    }

    public function forUser(User $user, ?SmkiRemovableMediaReport $report = null): array
    {
        $bidangId = $report?->bidang_id ?? $user->bidang_id;

        return [
            'unit' => ['id' => $bidangId, 'name' => $report?->unit_kerja_nama ?? $user->bidang->name],
            'names' => AsetTiNama::orderBy('nama_aset')->get(['id', 'nama_aset'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->nama_aset]),
            'brands' => AsetTiMerek::orderBy('nama_merek')->get(['id', 'nama_merek'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->nama_merek]),
            'pics' => $this->allowedPics($bidangId, $report)->orderBy('nama_pj')->get(['id', 'nama_pj'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->nama_pj]),
            'users' => User::where('bidang_id', $bidangId)->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'position']),
            'conditions' => SmkiRemovableMediaReport::CONDITIONS,
            'template' => SmkiRemovableMediaReport::TEMPLATE,
        ];
    }
}
