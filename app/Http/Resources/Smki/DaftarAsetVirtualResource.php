<?php

namespace App\Http\Resources\Smki;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DaftarAsetVirtualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bidang_id' => $this->bidang_id,
            'unit_kerja_nama' => $this->unit_kerja_nama,
            'created_by' => $this->created_by,
            'created_by_nama' => $this->created_by_nama,
            'no_dokumen' => $this->no_dokumen,
            'no_revisi' => $this->no_revisi,
            'tanggal_berlaku' => $this->tanggal_berlaku,
            'kode_aset' => $this->kode_aset,
            'nama_aset' => $this->nama_aset,
            'lokasi_aset' => $this->lokasi_aset,
            'status' => $this->status,
            'instances_count' => $this->whenCounted('instances'),
            'rows' => $this->whenLoaded('instances', fn () => $this->instances->map(fn ($instance) => [
                'id' => $instance->id,
                'urutan' => $instance->urutan,
                'kode_aset' => $instance->kode_aset,
                'nama_instance' => $instance->nama_instance,
                'deskripsi' => $instance->deskripsi,
                'tipe_virtualisasi' => $instance->tipe_virtualisasi,
                'alamat' => $instance->alamat,
                'hostname' => $instance->hostname,
                'spesifikasi' => $instance->spesifikasi,
                'pemilik' => $instance->pemilik,
                'klasifikasi' => $instance->klasifikasi,
            ])),
            'permissions' => [
                'can_update' => Gate::allows('update', $this->resource),
                'can_delete' => Gate::allows('delete', $this->resource),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
