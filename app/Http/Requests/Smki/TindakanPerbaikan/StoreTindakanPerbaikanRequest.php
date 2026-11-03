<?php

namespace App\Http\Requests\Smki\TindakanPerbaikan;

class StoreTindakanPerbaikanRequest extends TindakanPerbaikanRequest
{
    protected function protectedFields(): array
    {
        return [...parent::protectedFields(), 'version'];
    }

    public function rules(): array
    {
        return $this->formRules();
    }
}
