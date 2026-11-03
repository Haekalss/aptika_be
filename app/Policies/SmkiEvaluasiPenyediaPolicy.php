<?php

namespace App\Policies;

use App\Models\SmkiEvaluasiPenyedia;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SmkiEvaluasiPenyediaPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active
            && in_array($user->role, ['admin', 'user'], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SmkiEvaluasiPenyedia $record): Response
    {
        if (!$this->viewAny($user)) {
            return Response::denyAsNotFound();
        }

        if ($user->role === 'admin' || (int) $record->created_by === (int) $user->id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    /**
     * Editing and deletion belong to the creator, not the selected Evaluator.
     * Records the user cannot see are denied as 404 (no existence leak).
     */
    public function update(User $user, SmkiEvaluasiPenyedia $record): Response|bool
    {
        $view = $this->view($user, $record);
        if (!$view->allowed()) {
            return $view;
        }

        return (int) $record->created_by === (int) $user->id;
    }

    /**
     * Pembuat laporan yang mengajukan. Evaluator adalah perwakilan vendor
     * (tanpa akun); snapshot TTE-nya dicatat dari master penyedia.
     */
    public function ajukan(User $user, SmkiEvaluasiPenyedia $record): Response|bool
    {
        return $this->update($user, $record);
    }

    public function approve(User $user, SmkiEvaluasiPenyedia $record): Response|bool
    {
        $view = $this->view($user, $record);
        if (!$view->allowed()) {
            return $view;
        }

        return $user->role === 'admin'
            && (int) $record->admin_id === (int) $user->id;
    }

    public function delete(User $user, SmkiEvaluasiPenyedia $record): Response|bool
    {
        return $this->update($user, $record);
    }
}
