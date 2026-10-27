<?php

namespace App\Http\Requests\Smki\TindakanPerbaikan;

use App\Models\SmkiTindakanPerbaikan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class TindakanPerbaikanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = match ($this->route()->getActionMethod()) {
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
            'submit' => 'submit',
            'verify' => 'verify',
            default => null,
        };

        return $ability !== null && $this->user()->can($ability, $this->route('tindakanPerbaikan') ?? SmkiTindakanPerbaikan::class);
    }

    protected function protectedFields(): array
    {
        return ['id', 'created_by', 'bidang_id', 'verified_by', 'template_version',
            'status', 'verifikasi', 'tanggal_verifikasi', 'catatan_verifikasi',
            'created_at', 'updated_at', 'deleted_at'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->protectedFields() as $field) {
                if (array_key_exists($field, $this->all())) {
                    $validator->errors()->add($field, 'Field ini dikelola oleh sistem atau aksi khusus.');
                }
            }
        });
    }

    protected function activeUserRule(): mixed
    {
        return Rule::exists('users', 'id')->where(fn ($query) => $query
            ->where('is_active', 1)->where('bidang_id', $this->user()->bidang_id));
    }

    protected function formRules(): array
    {
        $record = $this->route('tindakanPerbaikan');
        $itemIdRule = $record
            ? Rule::exists('smki_tindakan_perbaikan_items', 'id')->where('tindakan_perbaikan_id', $record->id)
            : 'prohibited';

        return [
            'nomor_laporan' => ['nullable', 'string', 'max:100', Rule::unique('smki_tindakan_perbaikans', 'nomor_laporan')->ignore($record?->id)],
            'id_unit_kerja' => ['nullable', 'integer', 'exists:smki_unit_kerjas,id_unit_kerja'],
            'area' => ['nullable', 'string', 'max:255'],
            'tanggal_laporan' => ['nullable', 'date_format:Y-m-d'],
            'diajukan_kepada_id' => ['nullable', 'integer', $this->activeUserRule()],
            'diajukan_kepada_nama' => ['nullable', 'string', 'max:255'],
            'dilaporkan_oleh_id' => ['nullable', 'integer', $this->activeUserRule()],
            'dilaporkan_oleh_nama' => ['nullable', 'string', 'max:255'],
            'sumber_codes' => ['nullable', 'array', 'max:6'],
            'sumber_codes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(SmkiTindakanPerbaikan::SOURCES))],
            'sumber_lainnya' => ['nullable', 'string', 'max:5000'],
            'status_temuan' => ['nullable', Rule::in(['Minor'])],
            'deskripsi' => ['nullable', 'string', 'max:10000'],
            'akar_masalah' => ['nullable', 'string', 'max:10000'],
            'id_auditor' => ['nullable', 'integer', 'exists:auditor,id_auditor'],
            'auditor_nama' => ['nullable', 'string', 'max:255'],
            'atasan_id' => ['nullable', 'integer', $this->activeUserRule()],
            'atasan_nama' => ['nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*' => ['required', 'array:id,jenis,uraian,pic_id,pic_nama,batas_waktu,urutan'],
            'items.*.id' => ['nullable', 'integer', 'distinct', $itemIdRule],
            'items.*.jenis' => ['required', Rule::in(['koreksi', 'tindakan_korektif'])],
            'items.*.uraian' => ['nullable', 'string', 'max:10000'],
            'items.*.pic_id' => ['nullable', 'integer', $this->activeUserRule()],
            'items.*.pic_nama' => ['nullable', 'string', 'max:255'],
            'items.*.batas_waktu' => ['nullable', 'date_format:Y-m-d'],
            'items.*.urutan' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
