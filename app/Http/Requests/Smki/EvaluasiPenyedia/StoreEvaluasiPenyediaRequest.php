<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

use App\Models\SmkiEvaluasiPenyedia;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'create';
    }

    protected function allowedFields(): array
    {
        return $this->identityFields();
    }

    public function rules(): array
    {
        return $this->identityRules();
    }

    protected function identityRules(): array
    {
        return [
            'penyedia_id' => ['nullable', 'integer', 'exists:smki_penyedia_jasas,id'],
            'penyedia_nama' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'no_kontrak' => ['required', 'string', 'max:100'],
            'nomor_dokumen' => ['required', 'string', 'max:100'],
            'nomor_revisi' => ['required', 'string', 'max:50'],
            'tanggal_berlaku' => ['required', 'string', 'max:100'],
            'sifat_pekerjaan' => ['required', Rule::in(array_keys(SmkiEvaluasiPenyedia::SIFAT))],
            'sifat_pekerjaan_lainnya' => [
                'nullable', 'string', 'max:255', 'required_if:sifat_pekerjaan,lainnya',
            ],
            'tanggal_evaluasi' => ['required', 'date_format:Y-m-d'],
            'tempat' => ['nullable', 'string', 'max:150'],
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function afterValidation(Validator $validator): void
    {
        $hasProviderId = filled($this->input('penyedia_id'));
        $hasProviderName = filled($this->input('penyedia_nama'));

        if (!$hasProviderId && !$hasProviderName) {
            $validator->errors()->add('penyedia_id', 'Penyedia jasa wajib dipilih atau diisi.');
        }
    }

    public function messages(): array
    {
        return [
            'contact_person.required' => 'Contact person wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'no_kontrak.required' => 'Nomor kontrak wajib diisi.',
            'nomor_dokumen.required' => 'Nomor dokumen wajib diisi.',
            'nomor_revisi.required' => 'Nomor revisi wajib diisi.',
            'tanggal_berlaku.required' => 'Tanggal berlaku wajib diisi.',
            'sifat_pekerjaan_lainnya.required_if' => 'Uraian pekerjaan lainnya wajib diisi.',
        ];
    }
}
