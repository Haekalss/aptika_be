<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** UC-01 — Identifikasi Pemantauan (UC-01). */
class IdentifikasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'noFormulir' => ['required', 'string', 'max:120'],
            'noRevisi' => ['required', 'string', 'max:30'],
            'tanggalBerlaku' => ['required', 'date'],
            'tanggalPemantauan' => ['required', 'date'],
            'periode' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'jenisKonfigurasiId' => ['required', 'integer', Rule::exists('smki_jenis_konfigurasis', 'id')->where('is_active', 1)],
            'objekIds' => ['required', 'array', 'min:1'],
            'objekIds.*' => ['integer', Rule::exists('smki_objeks', 'id')->where('is_active', 1)],
        ];
    }

    public function messages(): array
    {
        return [
            'noFormulir.required' => 'No. Dokumen wajib diisi.',
            'noRevisi.required' => 'No. Revisi wajib diisi.',
            'tanggalBerlaku.required' => 'Tanggal Berlaku wajib diisi.',
            'tanggalPemantauan.required' => 'Tanggal Pemantauan wajib diisi.',
            'periode.required' => 'Periode wajib dipilih.',
            'periode.in' => 'Periode harus 1 sampai 4 (triwulan).',
            'jenisKonfigurasiId.required' => 'Jenis Konfigurasi wajib dipilih.',
            'jenisKonfigurasiId.exists' => 'Jenis Konfigurasi tidak ditemukan atau sudah dinonaktifkan.',
            'objekIds.required' => 'Pilih minimal 1 objek.',
            'objekIds.min' => 'Pilih minimal 1 objek.',
            'objekIds.*.exists' => 'Objek tidak ditemukan atau sudah dinonaktifkan.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $jenisId = $this->integer('jenisKonfigurasiId');
            $ids = array_filter(array_map('intval', (array) $this->input('objekIds', [])));
            if ($jenisId > 0 && $ids) {
                $cocok = \Illuminate\Support\Facades\DB::table('smki_objeks')
                    ->whereIn('id', $ids)
                    ->where('jenis_konfigurasi_id', $jenisId)
                    ->count();
                if ($cocok !== count($ids)) {
                    $validator->errors()->add('objekIds', 'Objek yang dipilih tidak sesuai dengan Jenis Konfigurasi.');
                }
            }
        });
    }
}
