<?php

namespace App\Services\Smki;

use App\Models\Auditor;
use App\Models\SmkiTindakanPerbaikan;
use App\Models\SmkiUnitKerja;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TindakanPerbaikanService
{
    public function create(array $data, User $user): SmkiTindakanPerbaikan
    {
        return $this->transaction(function () use ($data, $user) {
            Gate::forUser($user)->authorize('create', SmkiTindakanPerbaikan::class);
            if (! isset($data['dilaporkan_oleh_id']) && empty($data['dilaporkan_oleh_nama'])) {
                $data['dilaporkan_oleh_id'] = $user->id;
            }
            $data = $this->normalise($data, $user);
            $items = $data['items'] ?? [];
            unset($data['items']);
            $record = SmkiTindakanPerbaikan::create([
                ...$data, 'created_by' => $user->id, 'bidang_id' => $user->bidang_id,
                'status' => 'draft', 'version' => 1, 'template_version' => '3.0',
            ]);
            $this->syncItems($record, $items);

            return $record->refresh()->load(['items', 'verifications']);
        });
    }

    public function update(SmkiTindakanPerbaikan $record, array $data, User $user): SmkiTindakanPerbaikan
    {
        return $this->transaction(function () use ($record, $data, $user) {
            $record = $this->lock($record, $user, 'update', $data['version']);
            $itemsProvided = array_key_exists('items', $data);
            $data = $this->normalise([...$record->getAttributes(), ...$data], $user);
            $items = $data['items'] ?? [];
            unset($data['items'], $data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);
            // Changing reviewed content requires a new submission and verification.
            $record->fill([
                ...$data, 'status' => 'draft', 'verifikasi' => null, 'tanggal_verifikasi' => null,
                'catatan_verifikasi' => null, 'verified_by' => null, 'version' => $record->version + 1,
            ])->save();
            if ($itemsProvided) {
                $this->syncItems($record, $items);
            }

            return $record->load(['items', 'verifications']);
        });
    }

    public function submit(SmkiTindakanPerbaikan $record, int $version, User $user): SmkiTindakanPerbaikan
    {
        return $this->transaction(function () use ($record, $version, $user) {
            $record = $this->lock($record, $user, 'submit', $version)->load('items');
            $this->validateSubmission($record);
            $record->update(['status' => 'submitted', 'version' => $record->version + 1]);

            return $record->load('verifications');
        });
    }

    public function verify(SmkiTindakanPerbaikan $record, array $data, User $user): SmkiTindakanPerbaikan
    {
        return $this->transaction(function () use ($record, $data, $user) {
            $record = $this->lock($record, $user, 'verify', $data['version'])->load('items');
            $this->validateSubmission($record);
            $data = $this->normalise([...$record->getAttributes(), ...$data], $user);
            $rules = ['atasan_nama' => ['required', 'string']];
            if (array_intersect($record->sumber_codes ?? [], ['audit_internal', 'audit_eksternal'])) {
                $rules['auditor_nama'] = ['required', 'string'];
            }
            if ($data['verifikasi'] === 'Close') {
                $rules['akar_masalah'] = ['required', 'string'];
                $data['items'] = $record->items->toArray();
                $rules['items'] = ['required', 'array', 'min:1'];
            }
            Validator::make($data, $rules)->validate();
            $record->verifications()->create([
                'verifikasi' => $data['verifikasi'],
                'tanggal_verifikasi' => $data['tanggal_verifikasi'],
                'catatan_verifikasi' => $data['catatan_verifikasi'],
                'auditor_nama' => $data['auditor_nama'] ?? null,
                'atasan_nama' => $data['atasan_nama'],
                'verified_by' => $user->id,
                'content_version' => $record->version,
                'created_at' => now(),
            ]);
            $record->update([
                'verifikasi' => $data['verifikasi'],
                'tanggal_verifikasi' => $data['tanggal_verifikasi'],
                'catatan_verifikasi' => $data['catatan_verifikasi'],
                'id_auditor' => $data['id_auditor'] ?? null,
                'auditor_nama' => $data['auditor_nama'] ?? null,
                'atasan_id' => $data['atasan_id'] ?? null,
                'atasan_nama' => $data['atasan_nama'] ?? null,
                'verified_by' => $user->id,
                'version' => $record->version + 1,
            ]);

            return $record->load('verifications');
        });
    }

    public function delete(SmkiTindakanPerbaikan $record, int $version, User $user): void
    {
        $this->transaction(function () use ($record, $version, $user) {
            $this->lock($record, $user, 'delete', $version)->delete();
        });
    }

    private function lock(SmkiTindakanPerbaikan $record, User $user, string $ability, int $version): SmkiTindakanPerbaikan
    {
        $locked = SmkiTindakanPerbaikan::whereKey($record->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($user)->authorize($ability, $locked);
        abort_if($locked->version !== $version, 409, 'Data telah berubah. Muat ulang sebelum melanjutkan.');

        return $locked;
    }

    private function normalise(array $data, User $user): array
    {
        $userIds = collect(['diajukan_kepada_id', 'dilaporkan_oleh_id', 'atasan_id'])
            ->map(fn ($field) => $data[$field] ?? null)
            ->merge(collect($data['items'] ?? [])->pluck('pic_id'))->filter()->unique();
        $names = User::whereIn('id', $userIds)->where('is_active', 1)->where('bidang_id', $user->bidang_id)
            ->pluck('name', 'id')->all();
        if (! empty($data['id_unit_kerja'])) {
            $data['area'] = SmkiUnitKerja::findOrFail($data['id_unit_kerja'])->nama_unit_kerja;
        }
        foreach (['diajukan_kepada', 'dilaporkan_oleh', 'atasan'] as $identity) {
            if (! empty($data[$identity.'_id'])) {
                $data[$identity.'_nama'] = $this->userName($data[$identity.'_id'], $names, $identity.'_id');
            }
        }
        if (! empty($data['id_auditor'])) {
            $data['auditor_nama'] = Auditor::findOrFail($data['id_auditor'])->nama_auditor;
        }
        if (array_key_exists('sumber_codes', $data) && is_string($data['sumber_codes'])) {
            $data['sumber_codes'] = json_decode($data['sumber_codes'], true) ?? [];
        }
        if (array_key_exists('items', $data)) {
            foreach ($data['items'] as $index => &$item) {
                if (! empty($item['pic_id'])) {
                    $item['pic_nama'] = $this->userName($item['pic_id'], $names, "items.$index.pic_id");
                }
                $item['urutan'] = $item['urutan'] ?? $index + 1;
            }
            unset($item);
            $data['items'] = array_values(array_filter($data['items'], fn ($item) => filled($item['uraian'] ?? null) || filled($item['pic_nama'] ?? null) || filled($item['batas_waktu'] ?? null)));
        }

        return $data;
    }

    private function userName(int $id, array $names, string $field): string
    {
        if (! array_key_exists($id, $names)) {
            throw ValidationException::withMessages([$field => 'Pilih pengguna aktif dari bidang yang sama.']);
        }

        return $names[$id];
    }

    private function syncItems(SmkiTindakanPerbaikan $record, array $items): void
    {
        $keptIds = [];
        $existing = $record->items()->get()->keyBy('id');
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            unset($item['id']);
            if ($id) {
                $row = $existing->get($id);
                if (! $row) {
                    throw ValidationException::withMessages(['items' => 'Baris tindakan bukan milik laporan ini.']);
                }
                $row->update($item);
            } else {
                $row = $record->items()->create($item);
            }
            $keptIds[] = $row->id;
        }
        $record->items()->whereNotIn('id', $keptIds)->delete();
    }

    private function validateSubmission(SmkiTindakanPerbaikan $record): void
    {
        $data = $record->toArray();
        $rules = [
            'nomor_laporan' => ['required', 'string'],
            'area' => ['required', 'string'],
            'tanggal_laporan' => ['required', 'date'],
            'diajukan_kepada_nama' => ['required', 'string'],
            'dilaporkan_oleh_nama' => ['required', 'string'],
            'sumber_codes' => ['required', 'array', 'min:1'],
            'sumber_codes.*' => [Rule::in(array_keys(SmkiTindakanPerbaikan::SOURCES))],
            'status_temuan' => ['required', Rule::in(['Minor'])],
            'deskripsi' => ['required', 'string'],
            'items.*.uraian' => ['required', 'string'],
            'items.*.pic_nama' => ['required', 'string'],
            'items.*.batas_waktu' => ['required', 'date'],
        ];
        if (in_array('lainnya', $record->sumber_codes ?? [], true)) {
            $rules['sumber_lainnya'] = ['required', 'string'];
        }
        Validator::make($data, $rules)->validate();
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['nomor_laporan' => 'Nomor laporan sudah digunakan.']);
        }
    }
}
