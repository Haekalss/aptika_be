<?php

namespace App\Http\Requests\Smki\TindakanPerbaikan;

class UpdateTindakanPerbaikanRequest extends TindakanPerbaikanRequest
{
    public function rules(): array
    {
        return [...$this->formRules(), 'version' => ['required', 'integer', 'min:1']];
    }
}
