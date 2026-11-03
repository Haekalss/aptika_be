<?php

namespace App\Http\Resources\Smki;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmkiTindakanPerbaikanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->attributesToArray();
        unset($data['deleted_at']);
        $data['sumber_codes'] = $data['sumber_codes'] ?? [];
        foreach (['area', 'diajukan_kepada_nama', 'dilaporkan_oleh_nama', 'sumber_lainnya',
            'deskripsi', 'akar_masalah', 'auditor_nama', 'atasan_nama'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $data['items'] = $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
            'id' => $item->id,
            'jenis' => $item->jenis,
            'urutan' => $item->urutan,
            'uraian' => $item->uraian ?? '',
            'pic_id' => $item->pic_id,
            'pic_nama' => $item->pic_nama ?? '',
            'batas_waktu' => $item->batas_waktu?->format('Y-m-d'),
        ]));
        $data['verifications'] = $this->whenLoaded('verifications', fn () => $this->verifications->map(fn ($entry) => [
            'id' => $entry->id,
            'verifikasi' => $entry->verifikasi,
            'tanggal_verifikasi' => $entry->tanggal_verifikasi->format('Y-m-d'),
            'catatan_verifikasi' => $entry->catatan_verifikasi,
            'auditor_nama' => $entry->auditor_nama,
            'atasan_nama' => $entry->atasan_nama,
            'verified_by' => $entry->verified_by,
            'created_at' => $entry->created_at->toISOString(),
            'content_version' => $entry->content_version,
        ]));
        $data['permissions'] = [
            'can_update' => $request->user()->can('update', $this->resource),
            'can_delete' => $request->user()->can('delete', $this->resource),
            'can_verify' => $request->user()->can('verify', $this->resource),
            'can_export' => $request->user()->can('export', $this->resource),
        ];

        return $data;
    }
}
