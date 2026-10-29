<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private team channel: long-running jobs (background check, lead sourcing) broadcast progress
// here. Authorised only for members of the team (tenancy — a non-member can't subscribe).
Broadcast::channel('teams.{teamId}', function (User $user, string $teamId): bool {
    $team = Team::query()->find((int) $teamId);

    return $team instanceof Team && $user->belongsToTeam($team);
});
