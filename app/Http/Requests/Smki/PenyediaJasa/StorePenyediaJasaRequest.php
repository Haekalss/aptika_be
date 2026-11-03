<?php

namespace App\Http\Requests\Smki\PenyediaJasa;

use Illuminate\Foundation\Http\FormRequest;

class StorePenyediaJasaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    public function rules(): array
    {
        return [
            'nama_penyedia' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_penyedia.required' => 'Nama penyedia wajib diisi.',
        ];
    }
}
