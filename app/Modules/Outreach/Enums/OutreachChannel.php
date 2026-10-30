<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Enums;

use App\Modules\Core\Enums\OutreachRestriction;

/**
 * How a lead is approached (Phase 11).
 *
 * The three cases are not interchangeable, and the difference that matters is
 * {@see self::isDirectMarketing()}: email is a message Alima sends on the team's behalf and is
 * therefore electronic direct marketing, regulated by PECR in the UK and by PDPL/TDRA in the UAE.
 * LinkedIn and a phone call are not — the member does them, on a platform or a line where the other
 * person chooses whether to answer. That single distinction is what {@see OutreachRestriction}
 * branches on, so a lead carrying a prior-consent restriction can still be drafted for the channels
 * it permits rather than being undraftable.
 *
 * Values are stable storage keys; {@see self::label()} is a translation key the screens pass through
 * `t()` (EN/AR, ADR 0007).
 */
enum OutreachChannel: string
{
    /** An email Alima sends through the team's configured sender (Resend). */
    case Email = 'email';

    /** Message text the member copies into LinkedIn themselves. Alima never posts it. */
    case LinkedIn = 'linkedin';

    /** A call script the member reads from. Nothing is sent at all. */
    case Call = 'call';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::LinkedIn => 'LinkedIn message',
            self::Call => 'Call script',
        };
    }

    /**
     * One line on what the channel actually does, so a member knows whether pressing the button
     * sends something.
     */
    public function description(): string
    {
        return match ($this) {
            self::Email => 'Alima sends it from your verified address.',
            self::LinkedIn => 'Copy the text and send it yourself on LinkedIn.',
            self::Call => 'A script to work from — nothing is sent.',
        };
    }

    /**
     * Whether using this channel is **electronic direct marketing**, and so subject to suppression,
     * sender identification, an opt-out and (in the UAE) prior consent.
     *
     * Read this rather than comparing cases: SMS arrives post-MVP and must be caught by every
     * check that catches email, without anyone revisiting a `match` that still compiles.
     */
    public function isDirectMarketing(): bool
    {
        return match ($this) {
            self::Email => true,
            self::LinkedIn, self::Call => false,
        };
    }

    /**
     * Whether Alima transmits the message itself, as opposed to handing the member text to use.
     * Only a sendable channel has a sender identity, an opt-out footer or a delivery outcome.
     */
    public function isSendable(): bool
    {
        return $this === self::Email;
    }

    /**
     * Whether the channel needs a subject line.
     */
    public function hasSubject(): bool
    {
        return $this === self::Email;
    }

    /**
     * The channels still open under a restriction, in the order to offer them.
     *
     * @return list<self>
     */
    public static function permittedUnder(?OutreachRestriction $restriction): array
    {
        $cases = [self::Email, self::LinkedIn, self::Call];

        if ($restriction === null || $restriction->allowsDirectMarketing()) {
            return $cases;
        }

        return array_values(array_filter(
            $cases,
            static fn (self $channel): bool => ! $channel->isDirectMarketing(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
