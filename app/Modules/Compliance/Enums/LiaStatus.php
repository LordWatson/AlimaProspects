<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

use App\Modules\Compliance\Models\LiaRecord;

/**
 * Where a team's Legitimate Interest Assessment stands (docs/compliance.md §2).
 *
 * Only {@see self::Active} opens the discovery gate, and even then only while the review date
 * holds — expiry is a property of the record rather than a case here, because a stored status
 * cannot know what today is. {@see LiaRecord::isInForce()} is the question callers ask.
 *
 * Values are stable storage keys; {@see self::label()} is display text. No magic strings.
 */
enum LiaStatus: string
{
    /**
     * Written down but not affirmed. The team can save work in progress without that being taken
     * as a signed-off lawful basis — the one thing a half-filled legal record must not become.
     */
    case Draft = 'draft';

    /** Affirmed by a named person on a named date. */
    case Active = 'active';

    /**
     * The team has stopped relying on legitimate interest. Kept rather than deleted, because
     * "there was a basis, and it ran until this date" is itself the accountability record (§3.8).
     */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * The one line a member needs beside the status.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Draft => 'Saved, but not signed off yet — discovery stays closed until it is.',
            self::Active => 'Signed off. Discovery is open while the review date holds.',
            self::Withdrawn => 'Withdrawn. Discovery is closed until a new assessment is signed off.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
