<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Switch a user's active team. Membership is enforced by {@see User::switchTeam()} — a non-member
 * switch is rejected (returns false) and nothing is written.
 */
final class SwitchTeamAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, Team $team): bool
    {
        return DB::transaction(function () use ($user, $team): bool {
            if (! $user->switchTeam($team)) {
                return false;
            }

            $this->teamContext->set($team);
            $this->audit->log('team.switched', $team, [], $user);

            return true;
        });
    }
}
