<?php

namespace App\Policies;

use App\Models\SmkiTindakanPerbaikan;
use App\Models\User;

class SmkiTindakanPerbaikanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->bidang_id && in_array($user->role, ['admin', 'user'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->viewAny($user)
            && (int) $user->bidang_id === (int) $record->bidang_id
            && ($user->role === 'admin' || (int) $record->created_by === (int) $user->id);
    }

    public function update(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->view($user, $record) && $record->verifikasi !== 'Close';
    }

    public function delete(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->update($user, $record) && $record->status === 'draft';
    }

    public function submit(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->update($user, $record) && $record->status === 'draft';
    }

    public function verify(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->view($user, $record) && $user->role === 'admin'
            && $record->status === 'submitted' && $record->verifikasi !== 'Close';
    }

    public function export(User $user, SmkiTindakanPerbaikan $record): bool
    {
        return $this->view($user, $record);
    }
}
