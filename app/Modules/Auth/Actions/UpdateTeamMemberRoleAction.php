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
 * Change a member's role on a team. Refuses to demote the last remaining Owner so a team can
 * never be left ownerless.
 */
final class UpdateTeamMemberRoleAction
{
    public function __construct(
        private readonly TeamMembership $membership,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Team $team, User $member, TeamRole $role, ?User $actor = null): void
    {
        if (! $member->belongsToTeam($team)) {
            throw TeamMembershipException::notAMember();
        }

        $currentRole = $member->roleOn($team);

        if ($currentRole === TeamRole::Owner && $role !== TeamRole::Owner && $this->ownerCount($team) <= 1) {
            throw TeamMembershipException::lastOwner();
        }

        DB::transaction(function () use ($team, $member, $role, $actor): void {
            $this->membership->updateRole($member, $team, $role);

            $this->teamContext->run($team, fn () => $this->audit->log(
                'team.role_changed',
                $team,
                ['user_id' => $member->getKey(), 'role' => $role->value],
                $actor,
            ));
        });
    }

    private function ownerCount(Team $team): int
    {
        return $team->users()->wherePivot('role', TeamRole::Owner->value)->count();
    }
}
