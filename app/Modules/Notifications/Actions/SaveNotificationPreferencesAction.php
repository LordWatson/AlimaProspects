<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Notifications\Data\NotificationPreferencesData;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationSetting;
use Illuminate\Support\Facades\DB;

/**
 * Saves a member's notification choices for one team (Phase 12).
 *
 * The screen sends **every** configurable type, not only the ones that changed, and this writes a
 * row for each. That is the point: once a member has visited the screen they have an opinion about
 * all of it, and leaving some types without a row would mean a later change to a type's defaults
 * silently overriding what they chose to leave alone.
 *
 * A type a member may not receive at all (`credit_low` for a plain member) is still stored if they
 * send it — the preference is harmless, and `NotificationPreferences` checks the role first anyway.
 * Rejecting it would mean losing a member's settings the day they are promoted.
 */
final class SaveNotificationPreferencesAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, Team $team, NotificationPreferencesData $data): void
    {
        DB::transaction(function () use ($user, $team, $data): void {
            foreach (NotificationType::configurable() as $type) {
                $channels = $data->channelsFor($type);

                NotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'team_id' => $team->getKey(),
                        'type' => $type->value,
                    ],
                    [
                        'in_app' => in_array(NotificationChannel::InApp, $channels, strict: true),
                        'mail' => in_array(NotificationChannel::Mail, $channels, strict: true),
                        'push' => in_array(NotificationChannel::Push, $channels, strict: true),
                    ],
                );
            }

            NotificationSetting::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'team_id' => $team->getKey()],
                [
                    'digest' => $data->digest,
                    'digest_hour' => $data->digestHour,
                    'muted_until' => $data->mutedUntil,
                ],
            );

            // Counts and enum values only — a member's notification settings are not sensitive,
            // but the audit log's rule is the same everywhere (CLAUDE.md logging rules).
            $this->audit->log('notifications.preferences_saved', null, [
                'team_id' => $team->getKey(),
                'digest' => $data->digest->value,
                'digest_hour' => $data->digestHour,
                'muted' => $data->mutedUntil !== null,
            ], $user);
        });
    }
}
