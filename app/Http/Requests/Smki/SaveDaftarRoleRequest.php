<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiDaftarRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveDaftarRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('daftar_role');
        if (! $id) {
            return Gate::allows('create', SmkiDaftarRole::class);
        }

        $form = SmkiDaftarRole::query()->find($id);

        return $form ? Gate::allows('update', $form) : true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        foreach (['effectiveDate', 'documentDate'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $input[$key] = null;
            }
        }
        $this->replace($input);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['Draf', 'Tersimpan'])],
            'clientId' => ['nullable', 'uuid'],
            'documentNumber' => ['nullable', 'string', 'max:255'],
            'revision' => ['nullable', 'string', 'max:255'],
            'effectiveDate' => ['nullable', 'date_format:Y-m-d'],
            'unit' => ['nullable', 'string', 'max:255'],
            'roles' => ['nullable', 'array', 'max:100'],
            'roles.*' => ['array:id,role,access'],
            'roles.*.id' => ['required', 'string', 'max:255'],
            'roles.*.role' => ['nullable', 'string', 'max:255'],
            'roles.*.access' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:255'],
            'documentDate' => ['nullable', 'date_format:Y-m-d'],
            'preparedById' => ['nullable', 'integer', 'min:1'],
            'approvedById' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
