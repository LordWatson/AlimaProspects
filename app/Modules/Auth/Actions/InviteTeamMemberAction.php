<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\InviteMemberData;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Mail\TeamInvitationMail;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Auth\Services\SeatCounter;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invite someone (by email) to a team with a role. Idempotent per (team, email): re-inviting a
 * pending address refreshes the token/expiry rather than creating a duplicate. The email is sent
 * after the transaction commits (never inside it — CLAUDE.md external-calls rule).
 *
 * **Seats are checked here** (Phase 16), before anything is written and before an email goes out,
 * because this is the moment a team finds out it has run out of room — not the moment the person
 * they invited tries to sign in. The count comes from {@see SeatCounter}; the limit behind it is
 * the plan's, through a contract this Action never names.
 */
final class InviteTeamMemberAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SeatCounter $seats,
    ) {}

    public function handle(Team $team, InviteMemberData $data, ?User $inviter = null): TeamInvitation
    {
        $email = Str::of($data->email)->trim()->lower()->value();

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null && $existingUser->belongsToTeam($team)) {
            throw InvitationException::alreadyMember();
        }

        return DB::transaction(function () use ($team, $email, $data, $inviter): TeamInvitation {
            // Inside the transaction, behind a lock on the team row: two invitations sent at the
            // same moment would otherwise both see the last free seat and both take it. The lock is
            // on the team because that is what the seats belong to — nothing else contends for it,
            // and the alternative is a count that is true for exactly as long as it takes to read.
            //
            // Excluding this address, so re-sending an invitation to somebody who already holds one
            // isn't refused for occupying the seat they are already occupying.
            $team->newQueryWithoutScopes()->lockForUpdate()->find($team->getKey());

            $this->seats->ensureSeatAvailable($team, excluding: $email);

            /** @var TeamInvitation $invitation */
            $invitation = TeamInvitation::query()->updateOrCreate(
                ['team_id' => $team->getKey(), 'email' => $email],
                [
                    'role' => $data->role->value,
                    'invited_by' => $inviter?->getKey(),
                    'token' => Str::random(64),
                    'accepted_at' => null,
                    'expires_at' => now()->addDays(7),
                ],
            );

            $this->audit->log('team.member_invited', $invitation, [
                'email' => $email,
                'role' => $data->role->value,
            ], $inviter);

            DB::afterCommit(static function () use ($invitation): void {
                Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));
            });

            return $invitation;
        });
    }
}
