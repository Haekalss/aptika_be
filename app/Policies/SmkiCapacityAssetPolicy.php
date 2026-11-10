<?php

namespace App\Policies;

use App\Models\SmkiCapacityAsset;
use App\Models\User;

class SmkiCapacityAssetPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->bidang_id && in_array($user->role, ['admin', 'user'], true);
    }

    public function view(User $user, SmkiCapacityAsset $asset): bool
    {
        return $this->create($user) && (
            $user->isAdminAptika() || (int) $user->bidang_id === (int) $asset->bidang_id
        );
    }

    public function update(User $user, SmkiCapacityAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    public function delete(User $user, SmkiCapacityAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    public function export(User $user, SmkiCapacityAsset $asset): bool
    {
        return $this->view($user, $asset);
    }
}
