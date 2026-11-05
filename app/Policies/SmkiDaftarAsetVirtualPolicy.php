<?php

namespace App\Policies;

use App\Models\SmkiDaftarAsetVirtual;
use App\Models\User;

class SmkiDaftarAsetVirtualPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->bidang_id && in_array($user->role, ['admin', 'user'], true);
    }

    public function view(User $user, SmkiDaftarAsetVirtual $form): bool
    {
        return $this->create($user) && ($user->isAdminAptika() || (
            (int) $user->bidang_id === (int) $form->bidang_id &&
            ($user->role === 'admin' || (int) $user->id === (int) $form->created_by)
        ));
    }

    public function update(User $user, SmkiDaftarAsetVirtual $form): bool
    {
        return $this->view($user, $form);
    }

    public function delete(User $user, SmkiDaftarAsetVirtual $form): bool
    {
        return $this->update($user, $form);
    }
}
