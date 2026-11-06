<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiCapacityAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveCapacityAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $routeAsset = $this->route('asset');
        $record = $routeAsset instanceof SmkiCapacityAsset
            ? $routeAsset
            : ($routeAsset ? SmkiCapacityAsset::query()->visibleTo($this->user())->find($routeAsset) : null);

        return $record
            ? Gate::allows('update', $record)
            : Gate::allows('create', SmkiCapacityAsset::class);    }

    public function rules(): array
    {
        $routeAsset = $this->route('asset');
        $record = $routeAsset instanceof SmkiCapacityAsset
            ? $routeAsset
            : ($routeAsset ? SmkiCapacityAsset::query()->visibleTo($this->user())->find($routeAsset) : null);
        $bidangId = $record?->bidang_id ?? $this->user()->bidang_id;

        return [
            'category' => ['required', Rule::in(array_keys(SmkiCapacityAsset::CATEGORIES))],
            'asset_name' => [
                'required', 'string', 'max:180',
                Rule::unique('smki_capacity_assets', 'asset_name')
                    ->where(fn ($query) => $query->where('bidang_id', $bidangId)->where('category', $this->input('category')))
                    ->ignore($record?->id),
            ],
            'ip_address' => ['nullable', 'string', 'max:45', 'ip'],
            'bidang_id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori aset wajib dipilih.',
            'category.in' => 'Kategori aset harus Server atau Network.',
            'asset_name.required' => 'Nama perangkat / Aset ID wajib diisi.',
            'asset_name.unique' => 'Nama perangkat sudah terdaftar pada kategori yang sama di unit kerja ini.',
            'ip_address.ip' => 'Alamat IP harus menggunakan format IPv4 atau IPv6 yang valid.',
        ];
    }
}
