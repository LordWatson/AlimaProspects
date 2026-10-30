<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadsWorkspace\Models\LeadNote;

/**
 * Ownership double-check for notes (ADR 0006).
 *
 * - **view** — any member of the owning team. A note is the team's record, not a private one.
 * - **delete** — the member who wrote it, or an Owner/Admin. A teammate can't delete someone else's
 *   note, because a shared record that anyone can quietly edit is not a record; an Owner or Admin
 *   can, because a team needs a way to remove something that shouldn't have been written down.
 *
 * There is no **update**: a note is amended by adding another one, so the thread stays honest about
 * what was known when.
 */
final class LeadNotePolicy
{
    public function view(User $user, LeadNote $note): bool
    {
        return $user->belongsToTeam($note->team);
    }

    public function delete(User $user, LeadNote $note): bool
    {
        if (! $user->belongsToTeam($note->team)) {
            return false;
        }

        if ($note->user_id !== null && (int) $note->user_id === (int) $user->getKey()) {
            return true;
        }

        $role = $user->roleOn($note->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
