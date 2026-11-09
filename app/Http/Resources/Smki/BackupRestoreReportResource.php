<?php

namespace App\Http\Resources\Smki;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupRestoreReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if ($this->resource->relationLoaded('assets')) {
            $data['assets'] = $this->assets->map(fn ($asset) => [
                'id' => $asset->id, 'client_key' => $asset->client_key, 'urutan' => $asset->urutan,
                'aset_ti_id' => $asset->aset_ti_id, 'virtual_instance_id' => $asset->virtual_instance_id,
                'nama_aset' => $asset->nama_aset, 'kode_aset' => $asset->kode_aset,
                'entries' => $asset->entries->map(fn ($entry) => [
                    'id' => $entry->id, 'month' => $entry->month, 'operation' => $entry->operation,
                    'result' => $entry->result, 'performed_on' => $entry->performed_on?->format('Y-m-d'), 'notes' => $entry->notes,
                    'attachments' => $entry->attachments->map(fn ($file) => $file->only(['id', 'original_name', 'mime', 'size']))->values(),
                ])->values(),
            ])->values();
        }
        $data['permissions'] = ['can_update' => $request->user()->can('update', $this->resource), 'can_delete' => $request->user()->can('delete', $this->resource), 'can_export' => $request->user()->can('export', $this->resource)];

        return $data;
    }
}
