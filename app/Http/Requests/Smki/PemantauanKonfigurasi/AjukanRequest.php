<?php

namespace App\Http\Requests\Smki\PemantauanKonfigurasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** UC-04 — Tandatangani & Ajukan. */
class AjukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adminId' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'admin')->where('is_active', 1)],
            'catatanTindakLanjut' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'adminId.required' => 'Admin wajib dipilih.',
            'adminId.exists' => 'Admin tidak valid atau sudah dinonaktifkan.',
            'catatanTindakLanjut.max' => 'Catatan Tindak Lanjut maksimal 5000 karakter.',
        ];
    }
}
