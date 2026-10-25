<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiRemovableMediaReport;
use App\Services\Smki\RemovableMediaLookup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveRemovableMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('removable_media');

        return $report ? Gate::allows('update', $report) : Gate::allows('create', SmkiRemovableMediaReport::class);
    }

    public function rules(): array
    {
        $report = $this->route('removable_media');
        $final = $report?->status === 'final' || $this->boolean('finalize');
        $required = $final ? 'required' : 'nullable';
        $today = now('Asia/Jakarta');
        $bidangId = $report?->bidang_id ?? $this->user()->bidang_id;
        $person = Rule::exists('users', 'id')->where('bidang_id', $bidangId)->where('is_active', 1);
        $pics = app(RemovableMediaLookup::class)->allowedPics($bidangId, $report)->select('id');

        return [
            'version' => [$report ? 'required' : 'prohibited', 'integer', 'min:1'],
            'finalize' => ['sometimes', 'boolean'],
            'nomor_dokumen' => ['required', 'string', 'max:255'],
            'nomor_revisi' => ['required', 'string', 'max:50'],
            'tanggal_berlaku' => ['required', 'date_format:Y-m-d'],
            'tanggal_dokumen' => ['present', $required, 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'tempat' => ['present', $required, 'string', 'max:100'],
            'pengurus_barang_user_id' => ['present', 'nullable', 'integer', clone $person],
            'pengurus_barang_nama' => ['present', $required, 'string', 'max:255'],
            'kepala_unit_user_id' => ['present', 'nullable', 'integer', clone $person],
            'kepala_unit_nama' => ['present', $required, 'string', 'max:255'],
            'items' => ['present', 'array', 'list', $final ? 'min:1' : 'min:0', 'max:100'],
            'items.*' => ['array:nama_barang_id,nama_barang,merek_id,merek,jumlah,tahun_pengadaan,pic_id,pic_nama,kondisi,keterangan'],
            'items.*.nama_barang_id' => ['nullable', 'integer', Rule::exists('aset_ti_namas', 'id')],
            'items.*.nama_barang' => [$required, 'string', 'max:255'],
            'items.*.merek_id' => ['nullable', 'integer', Rule::exists('aset_ti_mereks', 'id')],
            'items.*.merek' => [$required, 'string', 'max:255'],
            'items.*.jumlah' => [$required, 'integer', 'between:1,1000000'],
            'items.*.tahun_pengadaan' => [$required, 'integer', 'between:1900,'.$today->year],
            'items.*.pic_id' => ['nullable', 'integer', Rule::in($pics->pluck('id')->all())],
            'items.*.pic_nama' => [$required, 'string', 'max:255'],
            'items.*.kondisi' => [$required, Rule::in(array_keys(SmkiRemovableMediaReport::CONDITIONS))],
            'items.*.keterangan' => ['nullable', 'string', 'max:3000'],
            'bidang_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'status' => ['prohibited'],
            'unit_kerja_nama' => ['prohibited'],
            'finalized_at' => ['prohibited'],
            'template_version' => ['prohibited'],
        ];
    }
}
