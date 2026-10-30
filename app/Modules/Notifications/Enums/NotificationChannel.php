<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Modules\Notifications\Channels\PushChannel;

/**
 * How a notification reaches a member (Phase 12).
 *
 * {@see self::driver()} is the only place the Laravel channel names are written down, so the
 * product's vocabulary ("in-app") and the framework's ("database", "broadcast") never have to agree
 * anywhere else.
 *
 * **In-app is two Laravel channels, not one.** `database` is what the bell reads on the next page
 * load; `broadcast` is what makes it appear without one. They are a single thing as far as a member
 * is concerned — "tell me in Alima" — and splitting them in the preference UI would ask them a
 * question about our transport.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Mail = 'mail';
    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In Alima',
            self::Mail => 'Email',
            self::Push => 'Push',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::InApp => 'The bell, and a toast if you are looking at the time.',
            self::Mail => 'An email, straight away.',
            self::Push => 'A notification on your phone.',
        };
    }

    /**
     * The Laravel notification channels this one sends over.
     *
     * @return list<string>
     */
    public function drivers(): array
    {
        return match ($this) {
            self::InApp => ['database', 'broadcast'],
            self::Mail => ['mail'],
            self::Push => [PushChannel::class],
        };
    }

    /**
     * The column on `notification_preferences` that holds this channel's switch.
     */
    public function column(): string
    {
        return match ($this) {
            self::InApp => 'in_app',
            self::Mail => 'mail',
            self::Push => 'push',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
