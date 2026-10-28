<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Revoke (delete) a pending invitation so its link no longer works.
 */
final class RevokeInvitationAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TeamInvitation $invitation, ?User $actor = null): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $this->audit->log('team.invitation_revoked', $invitation, [
                'email' => $invitation->email,
            ], $actor);

            $invitation->delete();
        });
    }
}
