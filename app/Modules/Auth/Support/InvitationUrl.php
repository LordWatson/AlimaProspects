<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Models\TeamInvitation;
use Illuminate\Support\Facades\URL;

/**
 * Builds the tamper-proof, time-limited acceptance link for an invitation. The link is a
 * temporary signed URL keyed on the invitation's secret token and expiring with the invitation.
 */
final class InvitationUrl
{
    public static function for(TeamInvitation $invitation): string
    {
        return URL::temporarySignedRoute(
            'invitations.show',
            $invitation->expires_at ?? now()->addDays(7),
            ['token' => $invitation->token],
        );
    }
}
