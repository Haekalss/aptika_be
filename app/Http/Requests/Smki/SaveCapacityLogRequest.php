<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiCapacityAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveCapacityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->asset();

        return $asset
            ? Gate::allows('update', $asset)
            : Gate::allows('create', SmkiCapacityAsset::class);
    }

    public function rules(): array
    {
        $asset = $this->asset();
        $serverRules = [
            'current_storage_gb' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'current_memory_pct' => ['required', 'numeric', 'between:0,100'],
            'current_cpu_pct' => ['required', 'numeric', 'between:0,100'],
            'projection_storage_gb' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'projection_memory_pct' => ['required', 'numeric', 'between:0,100'],
            'projection_cpu_pct' => ['required', 'numeric', 'between:0,100'],
        ];
        $networkRules = [
            'bandwidth_capacity_mbps' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'bandwidth_max_usage_mbps' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'sla_agreement_pct' => ['required', 'numeric', 'between:0,100'],
            'sla_achievement_pct' => ['required', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        $metricRules = $asset?->category === 'network' ? $networkRules : $serverRules;

        return array_merge([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ], $metricRules, [
            'asset_id' => ['prohibited'],
            'bidang_id' => ['prohibited'],
        ]);
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Tahun wajib diisi.',
            'month.required' => 'Bulan wajib diisi.',
            'current_storage_gb.required' => 'Nilai Current Storage wajib diisi.',
            'current_memory_pct.required' => 'Nilai Current Memory wajib diisi.',
            'current_cpu_pct.required' => 'Nilai Current CPU Usage wajib diisi.',
            'projection_storage_gb.required' => 'Nilai Projection Storage wajib diisi.',
            'projection_memory_pct.required' => 'Nilai Projection Memory wajib diisi.',
            'projection_cpu_pct.required' => 'Nilai Projection CPU Usage wajib diisi.',
            'bandwidth_capacity_mbps.required' => 'Kapasitas bandwidth wajib diisi.',
            'bandwidth_capacity_mbps.gt' => 'Kapasitas bandwidth harus lebih dari 0.',
            'bandwidth_max_usage_mbps.required' => 'Max pemakaian bandwidth wajib diisi.',
            'sla_agreement_pct.required' => 'Perjanjian SLA wajib diisi.',
            'sla_achievement_pct.required' => 'Capaian SLA wajib diisi.',
        ];
    }

    /** @return array<string, string> */
    public function validationData(): array
    {
        return array_merge($this->all(), [
            'month' => is_numeric($this->input('month')) ? (int) $this->input('month') : $this->input('month'),
            'year' => is_numeric($this->input('year')) ? (int) $this->input('year') : $this->input('year'),
        ]);
    }

    private function asset(): ?SmkiCapacityAsset
    {
        $routeAsset = $this->route('asset');

        if ($routeAsset instanceof SmkiCapacityAsset) {
            return $routeAsset;
        }

        return $routeAsset
            ? SmkiCapacityAsset::query()->visibleTo($this->user())->find($routeAsset)
            : null;
    }
}
