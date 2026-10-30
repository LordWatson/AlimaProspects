<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\SignalWatch;

/**
 * Ownership double-check for signal watches (ADR 0006). Any team member — including Read-only — may
 * view the team's watches; Owners, Admins and Members may create, update, delete and run them.
 * Read-only may not. A non-member always fails. Role comes from the `team_user` pivot via
 * {@see User::roleOn()}.
 */
final class SignalWatchPolicy
{
    public function view(User $user, SignalWatch $watch): bool
    {
        return $user->belongsToTeam($watch->team);
    }

    public function create(User $user, SignalWatch $watch): bool
    {
        return $this->canManage($user, $watch);
    }

    public function update(User $user, SignalWatch $watch): bool
    {
        return $this->canManage($user, $watch);
    }

    public function delete(User $user, SignalWatch $watch): bool
    {
        return $this->canManage($user, $watch);
    }

    /**
     * Trigger a run now — same write roles as managing the watch.
     */
    public function run(User $user, SignalWatch $watch): bool
    {
        return $this->canManage($user, $watch);
    }

    private function canManage(User $user, SignalWatch $watch): bool
    {
        $role = $user->roleOn($watch->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
