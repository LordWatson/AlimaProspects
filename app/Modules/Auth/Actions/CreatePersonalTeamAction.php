<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Str;

/**
 * Create the personal team a user gets on registration, named after them, and make it their
 * active team. Thin wrapper over {@see CreateTeamAction} so the registration flow has one call.
 */
final class CreatePersonalTeamAction
{
    public function __construct(private readonly CreateTeamAction $createTeam) {}

    public function handle(User $user): Team
    {
        $first = Str::of($user->name)->trim()->explode(' ')->first() ?: $user->name;

        return $this->createTeam->handle(
            $user,
            new CreateTeamData(name: "{$first}'s Team"),
            switchTo: true,
        );
    }
}
