<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\Signal;

/**
 * Ownership double-check for signals (ADR 0006). Any team member — including Read-only — may view
 * the team's signals; Owners, Admins and Members may dismiss, restore and convert them. Read-only
 * may not. A non-member always fails. Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class SignalPolicy
{
    public function view(User $user, Signal $signal): bool
    {
        return $user->belongsToTeam($signal->team);
    }

    public function dismiss(User $user, Signal $signal): bool
    {
        return $this->canAction($user, $signal);
    }

    public function restore(User $user, Signal $signal): bool
    {
        return $this->canAction($user, $signal);
    }

    public function convert(User $user, Signal $signal): bool
    {
        return $this->canAction($user, $signal);
    }

    private function canAction(User $user, Signal $signal): bool
    {
        $role = $user->roleOn($signal->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
