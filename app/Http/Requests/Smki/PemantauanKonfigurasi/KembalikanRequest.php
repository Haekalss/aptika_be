<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;

/** UC-05 — Kembalikan untuk Revisi (Admin). */
class KembalikanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catatan' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'catatan.required' => 'Catatan tinjauan wajib diisi saat mengembalikan pemantauan.',
            'catatan.min' => 'Catatan tinjauan minimal 3 karakter.',
            'catatan.max' => 'Catatan tinjauan maksimal 5000 karakter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['catatan' => trim((string) $this->input('catatan', ''))]);
    }
}
