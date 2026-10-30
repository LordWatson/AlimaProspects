<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use Spatie\LaravelData\Data;

/**
 * One push notification, already in the recipient's language (Phase 12).
 *
 * Unlike {@see NotificationContent}, the strings here are **finished prose**: a push is rendered
 * once, at send time, and the device shows exactly what it is given. The translation happened in
 * the channel, under the team's locale.
 *
 * `data` carries the machine-readable half the app needs to open the right screen when the
 * notification is tapped. It is deliberately small — ids and a type, never a payload — because
 * FCM caps the whole message and because a device is not a cache.
 */
final class PushMessage extends Data
{
    /**
     * @param  array<string, string>  $data  Everything FCM requires as strings.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
