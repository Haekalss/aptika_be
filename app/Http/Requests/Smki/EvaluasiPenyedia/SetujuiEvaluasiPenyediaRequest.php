<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

class SetujuiEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'approve';
    }

    protected function allowedFields(): array
    {
        return ['version'];
    }

    public function rules(): array
    {
        return ['version' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }
}
