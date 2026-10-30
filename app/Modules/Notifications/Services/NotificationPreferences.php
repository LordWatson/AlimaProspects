<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationSetting;

/**
 * Decides which channels a notification may use for one member in one team (Phase 12).
 *
 * The resolution, in order, and why each step is where it is:
 *
 * 1. **Is the member still in the team, and entitled to this type?** An admin-only type
 *    (`credit_low`) does not reach a plain member however their preferences read. Checked first
 *    because it is not a preference — it is authorisation, and a preference row for a type a member
 *    may not receive must never be able to turn it on.
 * 2. **Their row, or the type's defaults.** No row means "no opinion yet", not "everything off",
 *    so a type added later arrives on for everyone rather than silently off (see
 *    {@see NotificationType::defaultChannels()}).
 * 3. **The mute.** It removes **email and push only** and leaves the bell alone: "stop buzzing me"
 *    is not "hide what happened", and a member back from a quiet week still has to be able to find
 *    out what they missed.
 */
final class NotificationPreferences
{
    /**
     * The channels to send on, which may be empty — an empty list means "do not send", and the
     * dispatcher treats it that way rather than falling back to anything.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(User $user, Team $team, NotificationType $type): array
    {
        $role = $user->roleOn($team);

        if ($role === null) {
            return []; // No longer a member. Their notifications stop with their membership.
        }

        if ($type->isAdminOnly() && ! in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true)) {
            return [];
        }

        $channels = $this->chosenChannels($user, $team, $type);

        if ($this->settingsFor($user, $team)->isMuted()) {
            $channels = array_values(array_filter(
                $channels,
                static fn (NotificationChannel $channel): bool => $channel === NotificationChannel::InApp,
            ));
        }

        return $channels;
    }

    /**
     * Whether this member wants this type at all, on any channel. What the digest asks before it
     * includes a line: a member who switched discovery notifications off should not be told about
     * discovery runs in the round-up either.
     */
    public function wants(User $user, Team $team, NotificationType $type): bool
    {
        return $this->channelsFor($user, $team, $type) !== [];
    }

    /**
     * The member's settings row, or an unsaved one carrying the defaults — so callers never have to
     * handle null and never write a row just by reading.
     */
    public function settingsFor(User $user, Team $team): NotificationSetting
    {
        $settings = NotificationSetting::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->first();

        if ($settings instanceof NotificationSetting) {
            return $settings;
        }

        $settings = new NotificationSetting;
        $settings->forceFill(['user_id' => $user->getKey(), 'team_id' => $team->getKey()]);

        return $settings;
    }

    /**
     * Every type's effective channels for this member, for the preference screen — so the screen
     * shows what *will* happen rather than only the rows that exist.
     *
     * @return array<string, list<string>> Channel values keyed by type value.
     */
    public function allFor(User $user, Team $team): array
    {
        $rows = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->get()
            ->keyBy(static fn (NotificationPreference $row): string => $row->type->value);

        $resolved = [];

        foreach (NotificationType::configurable() as $type) {
            $row = $rows->get($type->value);

            $channels = $row instanceof NotificationPreference
                ? $row->channels()
                : $type->defaultChannels();

            $resolved[$type->value] = array_map(
                static fn (NotificationChannel $channel): string => $channel->value,
                $channels,
            );
        }

        return $resolved;
    }

    /**
     * Step 2: the member's own choice, or the type's defaults when they have not made one.
     *
     * @return list<NotificationChannel>
     */
    private function chosenChannels(User $user, Team $team, NotificationType $type): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $team->getKey())
            ->where('type', $type->value)
            ->first();

        return $preference instanceof NotificationPreference
            ? $preference->channels()
            : $type->defaultChannels();
    }
}
