<?php

namespace App\Http\Resources\Smki;

use App\Models\SmkiEvaluasiPenyedia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmkiEvaluasiPenyediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SmkiEvaluasiPenyedia $record */
        $record = $this->resource;
        $record->loadMissing([
            'penyedia',
            'creator',
            'admin',
            'rekomendasi',
            'penilaian.kriteria',
            'penilaian.skala',
        ]);

        $user = $request->user();
        $editableStatus = in_array($record->status, ['draft', 'revisi'], true);
        $isCreator = $user && (int) $record->created_by === (int) $user->id;
        $isAssignedAdmin = $user
            && $user->role === 'admin'
            && (int) $record->admin_id === (int) $user->id;

        return [
            'id' => (int) $record->id,
            'created_by' => $record->created_by === null ? null : (int) $record->created_by,
            'penyedia_id' => (int) $record->penyedia_id,
            'penyedia' => $record->penyedia ? [
                'id' => (int) $record->penyedia->id,
                'nama_penyedia' => $record->penyedia->nama_penyedia,
                'contact_person' => $record->penyedia->contact_person,
                'email' => $record->penyedia->email,
            ] : null,
            'admin_id' => $record->admin_id === null ? null : (int) $record->admin_id,
            'admin' => $this->userRef($record->admin),
            'rekomendasi_id' => $record->rekomendasi_id === null ? null : (int) $record->rekomendasi_id,
            'rekomendasi' => $record->rekomendasi ? [
                'id' => (int) $record->rekomendasi->id,
                'kategori' => $record->rekomendasi->kategori,
                'nilai_min' => (int) $record->rekomendasi->nilai_min,
                'nilai_max' => (int) $record->rekomendasi->nilai_max,
                'keterangan' => $record->rekomendasi->keterangan,
            ] : null,
            'no_kontrak' => $record->no_kontrak,
            'nomor_dokumen' => $record->nomor_dokumen,
            'nomor_revisi' => $record->nomor_revisi,
            'tanggal_berlaku' => $record->tanggal_berlaku,
            'sifat_pekerjaan' => $record->sifat_pekerjaan,
            'sifat_pekerjaan_lainnya' => $record->sifat_pekerjaan_lainnya,
            'total_nilai' => $record->total_nilai === null ? null : (int) $record->total_nilai,
            'status' => $record->status,
            'catatan_revisi' => $record->catatan_revisi,
            'siklus' => (int) $record->siklus,
            'tempat' => $record->tempat,
            'tanggal_evaluasi' => $record->tanggal_evaluasi?->format('Y-m-d'),
            'ttd_evaluator_nama' => $record->ttd_evaluator_nama,
            'ttd_evaluator_nip' => $record->ttd_evaluator_nip,
            'ttd_evaluator_jabatan' => $record->ttd_evaluator_jabatan,
            'tanggal_ttd_evaluator' => $record->tanggal_ttd_evaluator?->toISOString(),
            'ttd_admin_nama' => $record->ttd_admin_nama,
            'ttd_admin_nip' => $record->ttd_admin_nip,
            'ttd_admin_jabatan' => $record->ttd_admin_jabatan,
            'tanggal_ttd_admin' => $record->tanggal_ttd_admin?->toISOString(),
            'penilaian' => $record->penilaian->map(fn ($item) => [
                'id' => (int) $item->id,
                'kriteria_id' => (int) $item->kriteria_id,
                'skala_id' => (int) $item->skala_id,
                'skor' => (int) $item->skor,
                'kriteria' => $item->kriteria ? [
                    'id' => (int) $item->kriteria->id,
                    'urutan' => (int) $item->kriteria->urutan,
                    'nama_kriteria' => $item->kriteria->nama_kriteria,
                ] : null,
                'skala' => $item->skala ? [
                    'id' => (int) $item->skala->id,
                    'label' => $item->skala->label,
                    'skor' => (int) $item->skala->skor,
                ] : null,
            ])->values(),
            'permissions' => [
                'can_update' => (bool) $isCreator && $editableStatus,
                'can_delete' => (bool) $isCreator && $record->status === 'draft',
                'can_ajukan' => (bool) $isCreator && $editableStatus,
                'can_approve' => (bool) $isAssignedAdmin && $record->status === 'diajukan',
                'can_export' => in_array($record->status, ['diajukan', 'selesai'], true),
            ],
            'version' => (int) $record->version,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function userRef(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'position' => $user->position,
        ];
    }
}
