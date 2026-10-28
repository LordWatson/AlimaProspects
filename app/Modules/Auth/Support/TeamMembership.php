<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single place that writes team membership. A member's role is stored twice, by design:
 *   1. on the `team_user` pivot — the authoritative membership role, read by policies
 *      (fast, no registrar state needed);
 *   2. as a spatie team-scoped role — the permission framework used for finer-grained
 *      gating in later phases.
 *
 * Every join / role-change / removal goes through here so the two never drift. Spatie role
 * records are global (team_id = null) and reused across teams; the assignment row in
 * `model_has_roles` carries the active team id.
 */
final class TeamMembership
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Add a user to a team (or update their role if already a member), keeping pivot and
     * spatie role in sync. Call inside a transaction.
     */
    public function add(User $user, Team $team, TeamRole $role): void
    {
        $user->teams()->syncWithoutDetaching([
            $team->getKey() => ['role' => $role->value],
        ]);

        $this->syncSpatieRole($user, $team, $role);
    }

    /**
     * Change an existing member's role.
     */
    public function updateRole(User $user, Team $team, TeamRole $role): void
    {
        $user->teams()->updateExistingPivot($team->getKey(), ['role' => $role->value]);

        $this->syncSpatieRole($user, $team, $role);
    }

    /**
     * Remove a user from a team entirely (pivot + spatie role on that team).
     */
    public function remove(User $user, Team $team): void
    {
        $user->teams()->detach($team->getKey());

        $this->withTeam($team->getKey(), function () use ($user): void {
            $user->syncRoles([]);
        });
    }

    private function syncSpatieRole(User $user, Team $team, TeamRole $role): void
    {
        $roleModel = $this->ensureRole($role);

        $this->withTeam($team->getKey(), function () use ($user, $roleModel): void {
            // syncRoles replaces only the roles scoped to the active team.
            $user->syncRoles([$roleModel]);
        });
    }

    /**
     * Find or create the global (team_id = null) spatie role for the guard, idempotently.
     */
    private function ensureRole(TeamRole $role): Role
    {
        return $this->withTeam(null, static fn (): Role => RoleModel::findOrCreate($role->value, 'web'));
    }

    /**
     * Run a callback with a specific permissions team id active, restoring the previous value.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTeam(?int $teamId, callable $callback): mixed
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($teamId);

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }
}
