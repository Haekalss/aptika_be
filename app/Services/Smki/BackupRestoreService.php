<?php

namespace App\Services\Smki;

use App\Models\DaftarAsetTi;
use App\Models\Pegawai;
use App\Models\SmkiBackupRestoreReport;
use App\Models\SmkiDaftarAsetVirtual;
use App\Models\SmkiDaftarAsetVirtualInstance;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BackupRestoreService
{
    public function save(?SmkiBackupRestoreReport $report, array $data, User $user): SmkiBackupRestoreReport
    {
        Gate::forUser($user)->authorize($report ? 'update' : 'create', $report ?? SmkiBackupRestoreReport::class);

        return DB::transaction(function () use ($report, $data, $user) {
            $current = $report ? SmkiBackupRestoreReport::visibleTo($user)->lockForUpdate()->findOrFail($report->id) : new SmkiBackupRestoreReport;
            if ($report) {
                $this->assertVersion($current, $data['version']);
                $this->assertVersion($current, $report->version);
            } else {
                $current->fill(['bidang_id' => $user->bidang_id, 'created_by' => $user->id, 'unit_kerja_nama' => $user->bidang->name]);
            }
            foreach (['pelaksana', 'penanggung_jawab'] as $role) {
                $id = $data[$role.'_pegawai_id'] ?? null;
                if (! $current->exists || (int) $current->{$role.'_pegawai_id'} !== (int) $id) {
                    $pegawai = $id ? Pegawai::findOrFail($id) : null;
                    $current->{$role.'_pegawai_id'} = $id;
                    foreach (['nama', 'nip', 'jabatan', 'pangkat'] as $field) {
                        $current->{$role.'_'.$field} = $pegawai?->{$field};
                    }
                }
            }
            $current->fill(Arr::only($data, ['tahun', 'nomor_dokumen', 'nomor_revisi', 'tanggal_terbit']));
            $current->status = $current->status === 'final' || ($data['finalize'] ?? false) ? 'final' : 'draft';
            if ($current->status === 'final') {
                foreach (['pelaksana', 'penanggung_jawab'] as $role) {
                    foreach (['nama', 'nip', 'jabatan', 'pangkat'] as $field) {
                        if (trim((string) $current->{$role.'_'.$field}) === '') {
                            throw ValidationException::withMessages([$role.'_pegawai_id' => 'Lengkapi nama, NIP, jabatan dan pangkat pegawai sebelum Final.']);
                        }
                    }
                }
            }
            $current->finalized_at = $current->status === 'final' ? ($current->finalized_at ?? now()) : null;
            $current->updated_by = $user->id;
            $current->version = $current->exists ? $current->version + 1 : 1;
            $current->save();

            $physical = DaftarAsetTi::where('bidang_id', $user->bidang_id)->whereIn('id', array_filter(array_column($data['assets'], 'aset_ti_id')))->get()->keyBy('id');
            $virtualParents = SmkiDaftarAsetVirtual::visibleTo($user)->where('bidang_id', $user->bidang_id)->select('id');
            $virtual = SmkiDaftarAsetVirtualInstance::whereIn('daftar_aset_virtual_id', $virtualParents)->whereIn('id', array_filter(array_column($data['assets'], 'virtual_instance_id')))->get()->keyBy('id');
            $oldAssets = $current->assets()->with('entries.attachments')->get()->keyBy('id');
            $kept = [];
            foreach ($data['assets'] as $index => $input) {
                $asset = isset($input['id']) ? $oldAssets->get($input['id']) : $oldAssets->firstWhere('client_key', $input['client_key']);
                if (isset($input['id']) && ! $asset) {
                    $this->invalid("assets.$index.id");
                }
                $asset ??= $current->assets()->make();
                if ($asset->exists && $asset->client_key !== $input['client_key']) {
                    $this->invalid("assets.$index.client_key");
                }
                $physicalId = $input['aset_ti_id'] ?? null;
                $virtualId = $input['virtual_instance_id'] ?? null;
                if ($physicalId && $virtualId) {
                    $this->invalid("assets.$index.aset_ti_id");
                }
                $sameSource = $asset->exists && (int) $asset->aset_ti_id === (int) $physicalId && (int) $asset->virtual_instance_id === (int) $virtualId;
                $values = Arr::only($input, ['client_key', 'kode_aset', 'nama_aset']);
                if ($sameSource && ($physicalId || $virtualId)) {
                    $values['kode_aset'] = $asset->kode_aset;
                    $values['nama_aset'] = $asset->nama_aset;
                } elseif ($physicalId) {
                    $source = $physical->get($physicalId);
                    if (! $source) {
                        $this->invalid("assets.$index.aset_ti_id");
                    }
                    $values['kode_aset'] = $source->kode;
                    $values['nama_aset'] = $source->nama_aset;
                } elseif ($virtualId) {
                    $source = $virtual->get($virtualId);
                    if (! $source) {
                        $this->invalid("assets.$index.virtual_instance_id");
                    }
                    $values['kode_aset'] = $source->kode_aset;
                    $values['nama_aset'] = $source->nama_instance;
                }
                $asset->fill($values + ['aset_ti_id' => $physicalId, 'virtual_instance_id' => $virtualId, 'urutan' => $index + 1]);
                if ($current->status === 'final' && trim((string) $asset->nama_aset) === '') {
                    throw ValidationException::withMessages(["assets.$index.nama_aset" => 'Nama aset pada sumber data wajib lengkap sebelum Final.']);
                }
                $asset->save();
                $kept[] = $asset->id;
                $entries = $asset->relationLoaded('entries') ? $asset->entries->keyBy('id') : collect();
                $keptEntries = [];
                foreach ($input['entries'] as $entryIndex => $entryInput) {
                    $entry = isset($entryInput['id']) ? $entries->get($entryInput['id']) : $entries->first(fn ($item) => $item->month === (int) $entryInput['month'] && $item->operation === $entryInput['operation']);
                    if (isset($entryInput['id']) && (! $entry || $entry->month !== (int) $entryInput['month'] || $entry->operation !== $entryInput['operation'])) {
                        $this->invalid("assets.$index.entries.$entryIndex.id");
                    }
                    $hasData = filled($entryInput['result'] ?? null) || filled($entryInput['performed_on'] ?? null) || filled($entryInput['notes'] ?? null);
                    $hasEvidence = $entry?->attachments->isNotEmpty() ?? false;
                    if ($current->status === 'final' && ($hasData || $hasEvidence) && (! filled($entryInput['result'] ?? null) || ! filled($entryInput['performed_on'] ?? null))) {
                        throw ValidationException::withMessages(["assets.$index.entries.$entryIndex.performed_on" => 'Lengkapi hasil dan tanggal kegiatan sebelum Final.']);
                    }
                    if (! $hasData && ! $hasEvidence) {
                        continue;
                    }
                    $entry ??= $asset->entries()->make();
                    $entry->fill(Arr::only($entryInput, ['month', 'operation', 'result', 'performed_on', 'notes']));
                    $entry->save();
                    $keptEntries[] = $entry->id;
                }
                foreach ($entries->except($keptEntries) as $removed) {
                    BackupRestoreAttachmentService::cleanupAfterCommit($removed->attachments->pluck('path')->all());
                    $removed->delete();
                }
            }
            foreach ($oldAssets->except($kept) as $removed) {
                BackupRestoreAttachmentService::cleanupAfterCommit($removed->entries->flatMap(fn ($entry) => $entry->attachments->pluck('path'))->all());
                $removed->delete();
            }

            return $current->load('assets.entries.attachments');
        });
    }

    public function delete(SmkiBackupRestoreReport $report, int $version, User $user): void
    {
        Gate::forUser($user)->authorize('delete', $report);
        DB::transaction(function () use ($report, $version, $user) {
            $current = SmkiBackupRestoreReport::visibleTo($user)->lockForUpdate()->findOrFail($report->id);
            $this->assertVersion($current, $version);
            $current->delete();
        });
    }

    private function assertVersion(SmkiBackupRestoreReport $report, int $version): void
    {
        abort_if($report->version !== $version, 409, 'Dokumen sudah berubah. Muat ulang sebelum menyimpan.');
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Referensi tidak tersedia atau bukan milik laporan ini.']);
    }
}
