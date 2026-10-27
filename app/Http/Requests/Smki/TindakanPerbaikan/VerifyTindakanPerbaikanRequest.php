<?php

namespace App\Http\Requests\Smki\TindakanPerbaikan;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VerifyTindakanPerbaikanRequest extends TindakanPerbaikanRequest
{
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'verifikasi' => ['required', Rule::in(['Open', 'Close'])],
            'tanggal_verifikasi' => ['required', 'date_format:Y-m-d'],
            'catatan_verifikasi' => ['required', 'string', 'max:10000'],
            'id_auditor' => ['nullable', 'integer', 'exists:auditor,id_auditor'],
            'auditor_nama' => ['nullable', 'string', 'max:255'],
            'atasan_id' => ['nullable', 'integer', $this->activeUserRule()],
            'atasan_nama' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Field ini tidak dapat diubah melalui aksi verifikasi.');
            }
        });
    }
}
