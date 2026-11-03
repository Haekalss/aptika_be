<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

use App\Models\SmkiEvaluasiPenyedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

abstract class EvaluasiPenyediaRequest extends FormRequest
{
    /** Ability required by this action. */
    abstract protected function ability(): string;

    /** Request keys accepted by this action. */
    abstract protected function allowedFields(): array;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $ability = $this->ability();
        if ($ability === 'create') {
            Gate::authorize($ability, SmkiEvaluasiPenyedia::class);
            return true;
        }

        $routeRecord = $this->route('evaluasiPenyedia')
            ?? $this->route('evaluasi-penyedia')
            ?? $this->route('id');

        if ($routeRecord instanceof SmkiEvaluasiPenyedia) {
            $record = $routeRecord;
        } else {
            $record = SmkiEvaluasiPenyedia::query()->findOrFail($routeRecord);
        }

        Gate::authorize($ability, $record);
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->allowedFields();
            foreach (array_keys($this->all()) as $field) {
                if (!in_array($field, $allowed, true)) {
                    $validator->errors()->add($field, 'Field ini tidak dapat diubah melalui aksi ini.');
                }
            }
        });

        $this->afterValidation($validator);
    }

    protected function afterValidation(Validator $validator): void
    {
        // Override in a request when it needs cross-field validation.
    }

    protected function identityFields(): array
    {
        return [
            'penyedia_id',
            'penyedia_nama',
            'contact_person',
            'email',
            'no_kontrak',
            'nomor_dokumen',
            'nomor_revisi',
            'tanggal_berlaku',
            'sifat_pekerjaan',
            'sifat_pekerjaan_lainnya',
            'tanggal_evaluasi',
            'tempat',
            'version',
        ];
    }
}
