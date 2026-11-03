<?php

namespace App\Http\Requests\Smki\TindakanPerbaikan;

use Illuminate\Validation\Validator;

class SubmitTindakanPerbaikanRequest extends TindakanPerbaikanRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['version']) as $field) {
                $validator->errors()->add($field, 'Aksi ini hanya menerima version; simpan perubahan form terlebih dahulu.');
            }
        });
    }
}
