<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

use Illuminate\Validation\Rule;

class AjukanEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'ajukan';
    }

    protected function allowedFields(): array
    {
        return ['admin_id', 'version'];
    }

    public function rules(): array
    {
        return [
            'admin_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 'admin')->where('is_active', 1)),
            ],
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'admin_id.required' => 'Atasan (Mengetahui) wajib dipilih.',
            'admin_id.exists' => 'Atasan harus merupakan akun Admin yang aktif.',
        ];
    }
}
