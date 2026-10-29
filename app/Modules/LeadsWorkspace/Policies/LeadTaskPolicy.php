<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadsWorkspace\Models\LeadTask;

/**
 * Ownership double-check for tasks (ADR 0006).
 *
 * - **view** — any member of the owning team.
 * - **complete** — any member except Read-only. Deliberately not "the assignee only": a colleague
 *   who actually made the call should be able to tick it off, and a task nobody but one absent
 *   person can close is a task that stays open.
 * - **delete** — whoever created it, the member it is assigned to, or an Owner/Admin.
 */
final class LeadTaskPolicy
{
    public function view(User $user, LeadTask $task): bool
    {
        return $user->belongsToTeam($task->team);
    }

    public function complete(User $user, LeadTask $task): bool
    {
        $role = $user->roleOn($task->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }

    public function delete(User $user, LeadTask $task): bool
    {
        if (! $user->belongsToTeam($task->team)) {
            return false;
        }

        $id = (int) $user->getKey();

        if ((int) $task->created_by === $id || (int) $task->assigned_to === $id) {
            return true;
        }

        $role = $user->roleOn($task->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
