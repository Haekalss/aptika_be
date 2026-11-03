<?php

namespace App\Policies;

use App\Models\SmkiRemovableMediaReport;
use App\Models\User;

class SmkiRemovableMediaReportPolicy
{
    public function create(User $user): bool
    {
        return (bool) $user->bidang_id && in_array($user->role, ['admin', 'user'], true);
    }

    public function view(User $user, SmkiRemovableMediaReport $report): bool
    {
        return $this->create($user) && ($user->isAdminAptika() || (
            (int) $user->bidang_id === (int) $report->bidang_id &&
            ($user->role === 'admin' || (int) $user->id === (int) $report->created_by)
        ));
    }

    public function update(User $user, SmkiRemovableMediaReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, SmkiRemovableMediaReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function export(User $user, SmkiRemovableMediaReport $report): bool
    {
        return $this->view($user, $report);
    }
}
