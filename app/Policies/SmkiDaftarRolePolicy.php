<?php

namespace App\Policies;

use App\Models\SmkiDaftarRole;
use App\Models\User;

class SmkiDaftarRolePolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->bidang_id && in_array($user->role, ['admin', 'user'], true);
    }

    public function view(User $user, SmkiDaftarRole $form): bool
    {
        return $this->create($user) && ($user->isAdminAptika()
            || (int) $user->bidang_id === (int) $form->bidang_id);
    }

    public function update(User $user, SmkiDaftarRole $form): bool
    {
        return $this->view($user, $form);
    }

    public function delete(User $user, SmkiDaftarRole $form): bool
    {
        return $this->update($user, $form);
    }
}
