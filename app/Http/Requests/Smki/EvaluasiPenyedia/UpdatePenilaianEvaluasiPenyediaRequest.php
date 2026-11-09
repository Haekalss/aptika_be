<?php

namespace App\Http\Requests\Smki\EvaluasiPenyedia;

use App\Models\SmkiEvaluasiKriteria;

class UpdatePenilaianEvaluasiPenyediaRequest extends EvaluasiPenyediaRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    protected function allowedFields(): array
    {
        return ['penilaian', 'version'];
    }

    public function rules(): array
    {
        $criteriaCount = SmkiEvaluasiKriteria::query()->count();

        return [
            'penilaian' => ['required', 'array', 'size:' . $criteriaCount],
            'penilaian.*' => ['required', 'array:kriteria_id,skala_id'],
            'penilaian.*.kriteria_id' => [
                'required', 'integer', 'distinct', 'exists:smki_evaluasi_kriterias,id',
            ],
            'penilaian.*.skala_id' => ['required', 'integer', 'exists:smki_skala_nilais,id'],
            'version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'penilaian.required' => 'Seluruh 4 kriteria wajib dinilai.',
            'penilaian.size' => 'Seluruh 4 kriteria wajib dinilai sebelum perhitungan.',
            'penilaian.*.kriteria_id.required' => 'Kriteria wajib diisi.',
            'penilaian.*.skala_id.required' => 'Skala wajib dipilih.',
        ];
    }
}
