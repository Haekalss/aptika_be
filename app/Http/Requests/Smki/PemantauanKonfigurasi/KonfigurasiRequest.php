<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;

/** UC-02 — Daftar Konfigurasi: seluruh baris dikirim dalam satu payload. */
class KonfigurasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array'],
            'items.*.objekId' => ['required', 'integer'],
            'items.*.namaKonfigurasi' => ['required', 'string', 'max:150'],
            'items.*.nilaiAktual' => ['required', 'string', 'max:255'],
            // nilaiStandar boleh kosong untuk baris manual tanpa standar
            'items.*.nilaiStandar' => ['nullable', 'string', 'max:255'],
            'items.*.nilaiStandarId' => ['nullable', 'integer'],
            'items.*.kesesuaian' => ['nullable', 'string', 'in:sesuai,tidak_sesuai'],
            'items.*.isStandar' => ['boolean'],
            'items.*.keterangan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.present' => 'Payload items wajib dikirim.',
            'items.array' => 'Payload items harus berupa array.',
            'items.*.objekId.required' => 'Baris konfigurasi membutuhkan objekId.',
            'items.*.namaKonfigurasi.required' => 'Semua baris wajib memiliki Nama Konfigurasi.',
            'items.*.nilaiAktual.required' => 'Semua baris wajib memiliki Nilai aktual.',
            'items.*.nilaiAktual.max' => 'Nilai aktual maksimal 255 karakter.',
            'items.*.kesesuaian.in' => 'Kesesuaian harus sesuai atau tidak_sesuai.',
        ];
    }
}
