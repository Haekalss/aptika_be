<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan/ubah Objek (master FR-048).
 * Nama unik; wajib terhubung ke 1 Jenis Konfigurasi yang aktif.
 */
class MasterObjekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'jenisKonfigurasiId' => ['required', 'integer', Rule::exists('smki_jenis_konfigurasis', 'id')->where('is_active', 1)],
            'nama' => ['required', 'string', 'max:100', Rule::unique('smki_objeks', 'nama')->ignore($id)],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenisKonfigurasiId.required' => 'Jenis Konfigurasi wajib dipilih.',
            'jenisKonfigurasiId.exists' => 'Jenis Konfigurasi tidak ditemukan atau sudah dinonaktifkan.',
            'nama.required' => 'Nama Objek wajib diisi.',
            'nama.max' => 'Nama Objek maksimal 100 karakter.',
            'nama.unique' => 'Nama Objek sudah dipakai.',
            'isActive.boolean' => 'Status aktif harus bernilai boolean.',
        ];
    }
}
