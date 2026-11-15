<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan/ubah Jenis Konfigurasi (master FR-048).
 * Nama unik (case-insensitive via collation utf8mb4_unicode_ci).
 */
class MasterJenisRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Keamanan ditegakkan di route (middleware role:admin).
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'nama' => ['required', 'string', 'max:100', Rule::unique('smki_jenis_konfigurasis', 'nama')->ignore($id)],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.required' => 'Nama Jenis Konfigurasi wajib diisi.',
            'nama.max' => 'Nama Jenis Konfigurasi maksimal 100 karakter.',
            'nama.unique' => 'Nama Jenis Konfigurasi sudah dipakai.',
            'isActive.boolean' => 'Status aktif harus bernilai boolean.',
        ];
    }
}
