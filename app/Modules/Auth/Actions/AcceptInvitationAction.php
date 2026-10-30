<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Services\SeatCounter;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accept an invitation: validates it is still pending and addressed to the signed-in user, then
 * adds them to the team with the invited role and marks the invitation accepted.
 *
 * **Seats are re-checked here as well as at invitation** (Phase 16), and the duplication is the
 * point: an invitation is valid for a week, and in that week the team may have downgraded, filled
 * the seat with somebody else, or had a plan lapse. Checking only at invitation would let a team
 * end up with more members than it pays for, which is the one outcome a seat limit exists to
 * prevent. The invitation itself survives — it is refused, not consumed, so it still works once a
 * seat is freed.
 */
final class AcceptInvitationAction
{
    public function __construct(
        private readonly TeamMembership $membership,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly SeatCounter $seats,
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
            // Inside the transaction and behind a lock on the team row, so two people accepting at
            // the same moment cannot both take the last seat.
            //
            // This invitation is one of the occupants, so it is excluded from the count — otherwise
            // the last seat could never be taken up by the person it was held for.
            $team->newQueryWithoutScopes()->lockForUpdate()->find($team->getKey());

            $this->seats->ensureSeatAvailable($team, excluding: $invitation->email);

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
