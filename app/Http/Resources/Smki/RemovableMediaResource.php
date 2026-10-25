<?php

namespace App\Http\Resources\Smki;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class RemovableMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => sprintf('FR025-%06d', $this->id),
            'bidang_id' => $this->bidang_id,
            'unit_kerja_nama' => $this->unit_kerja_nama,
            'created_by_nama' => $this->created_by_nama,
            'nomor_dokumen' => $this->nomor_dokumen,
            'nomor_revisi' => $this->nomor_revisi,
            'tanggal_berlaku' => $this->tanggal_berlaku?->format('Y-m-d'),
            'tanggal_dokumen' => $this->tanggal_dokumen?->format('Y-m-d'),
            'tempat' => $this->tempat,
            'pengurus_barang_user_id' => $this->pengurus_barang_user_id,
            'pengurus_barang_nama' => $this->pengurus_barang_nama,
            'kepala_unit_user_id' => $this->kepala_unit_user_id,
            'kepala_unit_nama' => $this->kepala_unit_nama,
            'status' => $this->status,
            'version' => $this->version,
            'finalized_at' => $this->finalized_at?->toISOString(),
            'template_version' => $this->template_version,
            'template' => [
                'code' => $this->nomor_dokumen,
                'revision' => $this->nomor_revisi,
                'effective_date' => $this->tanggal_berlaku?->format('Y-m-d'),
            ],
            'items_count' => $this->whenCounted('items'),
            'total_jumlah' => $this->whenHas('items_sum_jumlah', fn () => (int) $this->items_sum_jumlah),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => $item->only([
                'id', 'urutan', 'nama_barang_id', 'nama_barang', 'merek_id', 'merek', 'jumlah', 'tahun_pengadaan', 'pic_id', 'pic_nama', 'kondisi', 'keterangan',
            ]))),
            'permissions' => [
                'can_update' => Gate::allows('update', $this->resource),
                'can_delete' => Gate::allows('delete', $this->resource),
                'can_export' => Gate::allows('export', $this->resource),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
