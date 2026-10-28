<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accept an invitation: validates it is still pending and addressed to the signed-in user, then
 * adds them to the team with the invited role and marks the invitation accepted.
 */
final class AcceptInvitationAction
{
    public function __construct(
        private readonly TeamMembership $membership,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(TeamInvitation $invitation, User $user): Team
    {
        if ($invitation->isAccepted()) {
            throw InvitationException::alreadyAccepted();
        }

        if ($invitation->isExpired()) {
            throw InvitationException::expired();
        }

        if (! Str::of($user->email)->lower()->exactly(Str::of($invitation->email)->lower()->value())) {
            throw InvitationException::emailMismatch();
        }

        /** @var Team $team */
        $team = $invitation->team()->first();

        if ($user->belongsToTeam($team)) {
            throw InvitationException::alreadyMember();
        }

        return DB::transaction(function () use ($invitation, $user, $team): Team {
            $this->membership->add($user, $team, $invitation->roleEnum());

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->teamContext->run($team, fn () => $this->audit->log(
                'team.invitation_accepted',
                $invitation,
                ['email' => $invitation->email, 'role' => $invitation->role],
                $user,
            ));

            return $team;
        });
    }
}
