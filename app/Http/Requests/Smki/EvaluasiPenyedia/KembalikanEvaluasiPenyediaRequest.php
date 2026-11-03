<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

class KembalikanEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'approve';
    }

    protected function allowedFields(): array
    {
        return ['catatan', 'version'];
    }

    public function rules(): array
    {
        return [
            'catatan' => ['required', 'string', 'max:10000'],
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'catatan.required' => 'Catatan tinjauan wajib diisi saat mengembalikan evaluasi.',
        ];
    }
}
