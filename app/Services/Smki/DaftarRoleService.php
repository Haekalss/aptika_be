<?php

namespace App\Services\Smki;

use App\Models\Bidang;
use App\Models\Pegawai;
use App\Models\SmkiDaftarRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DaftarRoleService
{
    public const DEFAULT_NOTE = 'Role dapat ditambahkan sesuai dengan kondisi masing-masing Unit Kerja.';

    public const FIELDS = [
        'documentNumber', 'revision', 'effectiveDate', 'unit', 'roles', 'note',
        'city', 'documentDate', 'preparedById', 'preparedBy', 'preparedByNip',
        'approvedById', 'approvedBy', 'approvedByNip',
    ];

    public function findByClientId(?string $clientId, User $user): ?SmkiDaftarRole
    {
        if (! $clientId) {
            return null;
        }

        return SmkiDaftarRole::query()
            ->where('bidang_id', $user->bidang_id)
            ->where('client_id', $clientId)
            ->first();
    }

    public function save(?SmkiDaftarRole $form, array $data, User $user): SmkiDaftarRole
    {
        $status = $data['status'] ?? 'Draf';
        $bidang = $this->resolveBidang($user);
        $prepared = $this->resolveEmployee($data['preparedById'] ?? null, 'preparedById');
        $approved = $this->resolveEmployee($data['approvedById'] ?? null, 'approvedById');
        $payload = $this->payload($data, $prepared, $approved);

        if ($status === 'Tersimpan') {
            $this->validateComplete($payload);
        }

        return DB::transaction(function () use ($form, $data, $status, $bidang, $payload, $user) {
            $record = $form
                ? SmkiDaftarRole::query()->lockForUpdate()->findOrFail($form->id)
                : new SmkiDaftarRole;

            if (! $form) {
                $record->fill([
                    'bidang_id' => $bidang->id,
                    'client_id' => $data['clientId'] ?? null,
                    'recorder_id' => $user->id,
                    'recorder_name' => $user->name,
                ]);
            }

            $record->fill([
                'unit_name' => $payload['unit'] ?: $bidang->name,
                'status' => $status,
                'payload' => $payload,
            ]);
            $record->save();

            if (! $record->nomor) {
                $record->nomor = sprintf('FRM-033-%s-%06d', now()->year, $record->id);
                $record->save();
            }

            return $record;
        });
    }

    public function delete(SmkiDaftarRole $form): void
    {
        DB::transaction(function () use ($form) {
            SmkiDaftarRole::query()->lockForUpdate()->findOrFail($form->id)->delete();
        });
    }

    private function resolveBidang(User $user): Bidang
    {
        $bidang = Bidang::find($user->bidang_id);
        if (! $bidang) {
            throw ValidationException::withMessages(['bidangId' => 'Bidang pengguna belum ditetapkan.']);
        }
        return $bidang;
    }

    private function resolveEmployee(mixed $id, string $field): ?Pegawai
    {
        if ($id === null || $id === '') {
            return null;
        }
        $employee = Pegawai::find($id);
        if (! $employee) {
            throw ValidationException::withMessages([$field => 'Pegawai tidak tersedia pada master data.']);
        }
        return $employee;
    }

    private function payload(array $data, ?Pegawai $prepared, ?Pegawai $approved): array
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            $payload[$field] = $data[$field] ?? match ($field) {
                'roles' => [],
                'note' => self::DEFAULT_NOTE,
                default => '',
            };
        }
        $payload['roles'] = array_values(array_map(static fn (array $role): array => [
            'id' => (string) ($role['id'] ?? ''),
            'role' => trim((string) ($role['role'] ?? '')),
            'access' => trim((string) ($role['access'] ?? '')),
        ], $payload['roles']));
        $payload['preparedById'] = $prepared ? (string) $prepared->id : '';
        $payload['preparedBy'] = $prepared?->nama ?? '';
        $payload['preparedByNip'] = $prepared?->nip ?? '';
        $payload['approvedById'] = $approved ? (string) $approved->id : '';
        $payload['approvedBy'] = $approved?->nama ?? '';
        $payload['approvedByNip'] = $approved?->nip ?? '';
        return $payload;
    }

    private function validateComplete(array $payload): void
    {
        $errors = [];
        foreach (['documentNumber', 'revision', 'effectiveDate', 'unit', 'city', 'documentDate'] as $field) {
            if (! trim((string) ($payload[$field] ?? ''))) {
                $errors[$field] = 'Bagian ini wajib diisi sebelum menyimpan.';
            }
        }
        if (! $payload['preparedById']) $errors['preparedById'] = 'Pilih pembuat dari master pegawai.';
        if (! $payload['approvedById']) $errors['approvedById'] = 'Pilih pihak yang mengetahui dari master pegawai.';

        $roles = $payload['roles'] ?? [];
        if (! $roles) {
            $errors['roles'] = 'Tambahkan minimal satu role.';
        } else {
            foreach ($roles as $index => $role) {
                if (! trim((string) ($role['role'] ?? ''))) $errors["roles.$index.role"] = 'Nama role wajib diisi.';
                if (! trim((string) ($role['access'] ?? ''))) $errors["roles.$index.access"] = 'Akses pada sistem wajib diisi.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
