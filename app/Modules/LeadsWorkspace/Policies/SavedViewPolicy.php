<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadsWorkspace\Models\SavedView;

/**
 * Ownership double-check for saved leads views (ADR 0006).
 *
 * - **create** — any member except Read-only: a view is a stored preference, and a member who may
 *   not change anything still shouldn't be writing rows.
 * - **view** — the member who saved it, or any team member when it was shared.
 * - **delete** — the member who saved it. A shared view is shared to *use*, not to edit, so a
 *   teammate can't delete someone else's; an Owner or Admin can, because a team needs a way to
 *   tidy up after a member who has left the view behind.
 *
 * The {@see SavedView} global scope hides other teams' rows, but a global scope is not an
 * authorisation check — this is.
 */
final class SavedViewPolicy
{
    public function create(User $user, SavedView $view): bool
    {
        $role = $user->roleOn($view->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }

    public function view(User $user, SavedView $view): bool
    {
        if (! $user->belongsToTeam($view->team)) {
            return false;
        }

        return $view->is_shared || (int) $view->user_id === (int) $user->getKey();
    }

    public function delete(User $user, SavedView $view): bool
    {
        if (! $user->belongsToTeam($view->team)) {
            return false;
        }

        if ((int) $view->user_id === (int) $user->getKey()) {
            return true;
        }

        $role = $user->roleOn($view->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
