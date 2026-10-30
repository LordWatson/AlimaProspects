<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Spatie\LaravelData\Data;

/**
 * A member's submitted notification settings (Phase 12).
 *
 * Unknown types and channels in the request are **dropped rather than rejected**: the screen may
 * be a cached page from before a type was renamed, and losing a whole form over one stale key would
 * be worse than ignoring it. The channels for a type the request omits entirely come back as an
 * empty list, which is a real answer — "tell me nothing about this".
 */
final class NotificationPreferencesData extends Data
{
    /**
     * @param  array<string, list<NotificationChannel>>  $channels  Keyed by type value.
     */
    public function __construct(
        public readonly array $channels,
        public readonly DigestFrequency $digest,
        public readonly int $digestHour,
        public readonly ?DateTimeImmutable $mutedUntil = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $submitted = $validated['channels'] ?? [];
        $channels = [];

        foreach (NotificationType::configurable() as $type) {
            $raw = is_array($submitted) ? ($submitted[$type->value] ?? []) : [];

            $channels[$type->value] = self::parseChannels(is_array($raw) ? $raw : []);
        }

        return new self(
            channels: $channels,
            digest: DigestFrequency::tryFrom((string) ($validated['digest'] ?? '')) ?? DigestFrequency::Off,
            // Clamped rather than validated into an error: an hour outside 0–23 is a client bug,
            // and 8am is a better answer than a red form.
            digestHour: max(0, min(23, (int) ($validated['digest_hour'] ?? 8))),
            mutedUntil: self::timestamp($validated['muted_until'] ?? null),
        );
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channelsFor(NotificationType $type): array
    {
        return $this->channels[$type->value] ?? [];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<NotificationChannel>
     */
    private static function parseChannels(array $raw): array
    {
        $channels = [];

        foreach ($raw as $value) {
            $channel = is_string($value) ? NotificationChannel::tryFrom($value) : null;

            if ($channel !== null && ! in_array($channel, $channels, strict: true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * A mute in the past is the same as no mute, so it is normalised to null rather than stored as
     * an expired row the screen would then have to reason about.
     */
    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = Date::parse($value)->utc();

        return $parsed->isPast() ? null : $parsed->toDateTimeImmutable();
    }
}
