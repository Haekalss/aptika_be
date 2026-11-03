<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

class DeleteEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'delete';
    }

    protected function allowedFields(): array
    {
        return ['version'];
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }
}
