<?php

namespace App\Policies;

use App\Models\SmkiBackupRestoreReport;
use App\Models\User;

class SmkiBackupRestoreReportPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->bidang_id && (bool) $user->is_active && in_array($user->role, ['user', 'admin'], true);
    }

    public function view(User $user, SmkiBackupRestoreReport $report): bool
    {
        return $this->create($user) && (int) $user->bidang_id === (int) $report->bidang_id && ! $report->trashed();
    }

    public function update(User $user, SmkiBackupRestoreReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, SmkiBackupRestoreReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function export(User $user, SmkiBackupRestoreReport $report): bool
    {
        return $this->view($user, $report);
    }
}
