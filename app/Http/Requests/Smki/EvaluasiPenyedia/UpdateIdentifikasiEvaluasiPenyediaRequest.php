<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

class UpdateIdentifikasiEvaluasiPenyediaRequest extends StoreEvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'update';
    }
}
