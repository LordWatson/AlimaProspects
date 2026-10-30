<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Notifications\Notifications\TeamNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification as BaseNotification;

/**
 * Laravel's `database` channel, with the one thing a multi-tenant product needs: **`team_id` on the
 * row** (Phase 12, ADR 0006).
 *
 * Without it the bell would mix a member's three teams together, and no amount of care at the read
 * end could unmix them. It is set here rather than by a global scope or a model hook because the row
 * is created by the framework, outside our team context, so the {@see BelongsToTeam}
 * trait would never fire and its scope would then hide every row it had failed to stamp.
 *
 * Bound over the default channel in `NotificationsServiceProvider`, so a notification from
 * elsewhere in the framework — a password reset — still writes a row, just without a team.
 */
final class TeamDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, BaseNotification $notification): array
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            // Null for anything that isn't ours (Fortify's, Laravel's own): those notifications
            // genuinely have no team, and the bell skips a row whose type it doesn't recognise.
            'team_id' => $notification instanceof TeamNotification
                ? $notification->team->getKey()
                : null,
        ];
    }
}
