<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ganti baris Nilai Standar untuk satu Objek (master FR-048).
 * Baris lama objek tersebut dinonaktifkan; baris baru di-insert.
 * Nama Konfigurasi unik (case-insensitive) di dalam payload.
 */
class NilaiStandarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['present', 'array'],
            'rows.*.namaKonfigurasi' => ['required', 'string', 'max:150'],
            'rows.*.nilaiStandar' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.present' => 'Payload rows wajib dikirim (boleh kosong).',
            'rows.array' => 'Payload rows harus berupa array.',
            'rows.*.namaKonfigurasi.required' => 'Nama Konfigurasi wajib diisi.',
            'rows.*.namaKonfigurasi.max' => 'Nama Konfigurasi maksimal 150 karakter.',
            'rows.*.nilaiStandar.required' => 'Nilai Standar wajib diisi.',
            'rows.*.nilaiStandar.max' => 'Nilai Standar maksimal 255 karakter.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $rows = (array) $this->input('rows', []);
            $seen = [];
            foreach ($rows as $i => $row) {
                $key = strtolower(trim((string) data_get($row, 'namaKonfigurasi', '')));
                if ($key === '') {
                    continue;
                }
                if (isset($seen[$key])) {
                    $validator->errors()->add("rows.$i.namaKonfigurasi", 'Nama Konfigurasi duplikat di dalam daftar.');
                }
                $seen[$key] = true;
            }
        });
    }
}
