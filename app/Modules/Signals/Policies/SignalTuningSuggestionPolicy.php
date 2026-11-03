<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\SignalTuningSuggestion;

/**
 * Ownership double-check for tuning suggestions (ADR 0006, S12).
 *
 * Mirrors {@see SignalDefinitionPolicy}: any team member may **see** the suggestions, because
 * knowing what the product thinks of your feed is not a privileged fact; Owners, Admins and
 * Members may answer them, because accepting one edits a definition or a watch and that is the
 * same permission editing either by hand needs.
 *
 * That split is the point of having a policy here at all rather than reusing the definition's.
 * An `ignore_source` suggestion edits **watches** and a threshold one edits a **definition**, so
 * authorising "accept" against whichever object the payload happens to name would make the
 * permission depend on the suggestion's contents. One answer for the act of answering, and the
 * Action is what knows which rows it then writes.
 */
final class SignalTuningSuggestionPolicy
{
    public function viewAny(User $user, SignalTuningSuggestion $suggestion): bool
    {
        return $this->isMember($user, $suggestion);
    }

    public function view(User $user, SignalTuningSuggestion $suggestion): bool
    {
        return $this->isMember($user, $suggestion);
    }

    /** Accepting and declining are both "answering", and both need the manage role. */
    public function update(User $user, SignalTuningSuggestion $suggestion): bool
    {
        return $this->canManage($user, $suggestion);
    }

    private function isMember(User $user, SignalTuningSuggestion $suggestion): bool
    {
        $team = $suggestion->team;

        return $team !== null && $user->belongsToTeam($team);
    }

    private function canManage(User $user, SignalTuningSuggestion $suggestion): bool
    {
        $team = $suggestion->team;

        if ($team === null) {
            return false;
        }

        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
