<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Exceptions\TeamMembershipException;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Remove a member from a team. Refuses to remove the last remaining Owner. If the removed team
 * was the member's active team, their active team is moved to another they belong to (or null).
 */
final class RemoveTeamMemberAction
{
    public function __construct(
        private readonly TeamMembership $membership,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $member, ?User $actor = null): void
    {
        if (! $member->belongsToTeam($team)) {
            throw TeamMembershipException::notAMember();
        }

        if ($member->roleOn($team) === TeamRole::Owner && $this->ownerCount($team) <= 1) {
            throw TeamMembershipException::lastOwner();
        }

        DB::transaction(function () use ($team, $member, $actor): void {
            $this->membership->remove($member, $team);

            if ($member->currentTeamId() === (int) $team->getKey()) {
                $fallback = $member->teams()->whereKeyNot($team->getKey())->first();
                $member->forceFill(['current_team_id' => $fallback?->getKey()])->save();
            }

            $this->teamContext->run($team, fn () => $this->audit->log(
                'team.member_removed',
                $team,
                ['user_id' => $member->getKey()],
                $actor,
            ));
        });
    }

    private function ownerCount(Team $team): int
    {
        return $team->users()->wherePivot('role', TeamRole::Owner->value)->count();
    }
}
