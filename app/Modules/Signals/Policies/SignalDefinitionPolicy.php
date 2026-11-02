<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\SignalDefinition;

/**
 * Ownership double-check for signal definitions (ADR 0006, ADR 0018).
 *
 * Mirrors {@see SignalWatchPolicy}: any team member — including Read-only — may see the team's
 * signal types and the preset library; Owners, Admins and Members may create, edit and remove them.
 * A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 *
 * **A platform preset is never updatable or deletable**, and that is answered here rather than with
 * an `if` in a controller. A preset belongs to the product, not to a tenant; a team clones one and
 * tunes their copy (ADR 0018 §3). Putting it in the policy means the web screen, the API and
 * anything added later all get the same answer, and `viewAny` still lets everyone read the library.
 */
final class SignalDefinitionPolicy
{
    public function viewAny(User $user, SignalDefinition $definition): bool
    {
        return $this->isMember($user, $definition);
    }

    public function view(User $user, SignalDefinition $definition): bool
    {
        // A preset has no team, so there is nothing to check ownership against — and nothing
        // tenant-specific in it to protect. Every member of every team may read the library.
        return $definition->isPreset() || $this->isMember($user, $definition);
    }

    public function create(User $user, SignalDefinition $definition): bool
    {
        return $this->canManage($user, $definition);
    }

    public function update(User $user, SignalDefinition $definition): bool
    {
        return ! $definition->isPreset() && $this->canManage($user, $definition);
    }

    public function delete(User $user, SignalDefinition $definition): bool
    {
        return ! $definition->isPreset() && $this->canManage($user, $definition);
    }

    /**
     * Copy a preset into the team's own types — a create, authorised as one.
     */
    public function clone(User $user, SignalDefinition $definition): bool
    {
        return $this->canManage($user, $definition);
    }

    private function isMember(User $user, SignalDefinition $definition): bool
    {
        $team = $definition->team;

        return $team !== null && $user->belongsToTeam($team);
    }

    private function canManage(User $user, SignalDefinition $definition): bool
    {
        $team = $definition->team;

        if ($team === null) {
            return false;
        }

        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
