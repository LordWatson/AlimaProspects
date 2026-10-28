<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Mail\TeamInvitationMail;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Re-send a pending invitation, refreshing its expiry (the token is preserved so earlier links
 * still resolve until they expire). The mail is sent after commit.
 */
final class ResendInvitationAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TeamInvitation $invitation, ?User $actor = null): TeamInvitation
    {
        return DB::transaction(function () use ($invitation, $actor): TeamInvitation {
            $invitation->forceFill(['expires_at' => now()->addDays(7)])->save();

            $this->audit->log('team.invitation_resent', $invitation, [
                'email' => $invitation->email,
            ], $actor);

            DB::afterCommit(static function () use ($invitation): void {
                Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));
            });

            return $invitation;
        });
    }
}
