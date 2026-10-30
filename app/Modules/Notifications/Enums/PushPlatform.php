<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * Which kind of device a push token belongs to (Phase 12).
 *
 * All three go out over **FCM**: Firebase relays to APNs for iOS, so one provider and one token
 * format cover the lot, and the platform is recorded for diagnostics and for the small payload
 * differences each expects rather than to pick a transport.
 */
enum PushPlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
    /** A browser, via the Web Push side of FCM. */
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iPhone or iPad',
            self::Android => 'Android',
            self::Web => 'Browser',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $platform): string => $platform->value, self::cases());
    }
}
