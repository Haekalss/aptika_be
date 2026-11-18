<?php

namespace App\Http\Resources\Smki;

use App\Models\SmkiDaftarRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DaftarRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SmkiDaftarRole $record */
        $record = $this->resource;
        $payload = $record->payload ?? [];

        $roles = array_map(static fn (array $role): array => [
            'id' => (string) ($role['id'] ?? ''),
            'role' => (string) ($role['role'] ?? ''),
            'access' => (string) ($role['access'] ?? ''),
        ], $payload['roles'] ?? []);

        return array_merge($payload, [
            'id' => (string) $record->id,
            'clientId' => $record->client_id,
            'number' => $record->nomor,
            'status' => $record->status,
            'roles' => array_values($roles),
            'recorderId' => $record->recorder_id,
            'recorderName' => $record->recorder_name,
            'createdAt' => $record->created_at?->toIso8601String() ?? '',
            'updatedAt' => $record->updated_at?->toIso8601String() ?? '',
            'permissions' => [
                'can_update' => Gate::allows('update', $record),
                'can_delete' => Gate::allows('delete', $record),
            ],
        ]);
    }
}
