<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How often a member wants the round-up (Phase 12).
 *
 * The window each frequency covers is {@see self::windowStart()}'s answer rather than the caller's,
 * so "what counts as this week" is decided once. The weekly digest goes out on **Monday**, which is
 * when a sales team plans rather than when a week technically starts.
 */
enum DigestFrequency: string
{
    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Never',
            self::Daily => 'Every day',
            self::Weekly => 'Every Monday',
        };
    }

    public function isOn(): bool
    {
        return $this !== self::Off;
    }

    /**
     * How far back a digest sent at `$now` should look.
     *
     * Slightly generous on purpose: a daily digest looks back 24 hours and a weekly one 7 days from
     * the moment it is built, rather than to the previous calendar boundary. A sweep that runs a
     * few minutes late must not leave a gap nothing was ever reported in.
     */
    public function windowStart(DateTimeInterface $now): CarbonImmutable
    {
        // `DateTimeInterface` in, CarbonImmutable out: the app runs on CarbonImmutable
        // (AppServiceProvider), which is a *sibling* of Illuminate\Support\Carbon rather than a
        // subclass, so a Carbon parameter would reject a model's own timestamp.
        $from = CarbonImmutable::instance($now);

        return match ($this) {
            self::Daily => $from->subDay(),
            // A whole week, so Monday's digest covers the one just gone.
            self::Weekly => $from->subWeek(),
            // Never built, so never asked — but a total rather than a crash if it ever is.
            self::Off => $from->subDay(),
        };
    }

    /**
     * Whether a digest is due now, given the member's chosen hour and when they last had one.
     *
     * `$localNow` must already be in the **team's** timezone — the one thing in this module that is
     * easy to get wrong, and the reason the caller converts rather than this method guessing
     * (CLAUDE.md: timestamps UTC in the DB, displayed in the team timezone).
     */
    public function isDue(DateTimeInterface $localNow, int $hour, ?DateTimeInterface $lastSentAt): bool
    {
        if (! $this->isOn()) {
            return false;
        }

        // `DateTimeInterface` rather than Carbon in the signature: the app runs on CarbonImmutable
        // (AppServiceProvider), which is a *sibling* of Illuminate\Support\Carbon rather than a
        // subclass — so a model's `digest_sent_at` would not satisfy a Carbon parameter.
        $local = CarbonImmutable::instance($localNow);

        if ($local->hour !== $hour) {
            return false;
        }

        if ($this === self::Weekly && ! $local->isMonday()) {
            return false;
        }

        if ($lastSentAt === null) {
            return true;
        }

        // The hourly sweep sees the same hour once, but a retry or a clock change could show it
        // twice; half a window is comfortably past either.
        $minimumGap = $this === self::Weekly ? $local->subDays(3) : $local->subHours(12);

        return CarbonImmutable::instance($lastSentAt)->lessThan($minimumGap);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $frequency): string => $frequency->value, self::cases());
    }
}
