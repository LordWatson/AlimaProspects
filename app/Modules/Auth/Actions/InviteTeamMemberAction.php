<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\InviteMemberData;
use App\Modules\Auth\Exceptions\InvitationException;
use App\Modules\Auth\Mail\TeamInvitationMail;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invite someone (by email) to a team with a role. Idempotent per (team, email): re-inviting a
 * pending address refreshes the token/expiry rather than creating a duplicate. The email is sent
 * after the transaction commits (never inside it — CLAUDE.md external-calls rule).
 */
final class InviteTeamMemberAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Team $team, InviteMemberData $data, ?User $inviter = null): TeamInvitation
    {
        $email = Str::of($data->email)->trim()->lower()->value();

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null && $existingUser->belongsToTeam($team)) {
            throw InvitationException::alreadyMember();
        }

        return DB::transaction(function () use ($team, $email, $data, $inviter): TeamInvitation {
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
