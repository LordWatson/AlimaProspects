<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * The kinds of wealth / liquidity event Signals watches for (ADR 0011). Each is an *event* about a
 * person or company — not a stated intent — found in published news and classified by AI. The set
 * is deliberately small for v1 (UAE-only); new types are added as data (query templates) and a new
 * case here.
 *
 * Values are stable storage keys; {@see self::label()} is the display name and
 * {@see self::description()} the one-line UI explainer. No magic strings.
 */
enum SignalEventType: string
{
    /** A company sold, a founder exits, or a stake changes hands. */
    case Acquisition = 'acquisition';

    /** A funding round — seed through late stage. */
    case FundingRound = 'funding_round';

    /** A founder or executive relocating to the watched market (e.g. a family office opening). */
    case Relocation = 'relocation';

    public function label(): string
    {
        return match ($this) {
            self::Acquisition => 'Acquisition',
            self::FundingRound => 'Funding round',
            self::Relocation => 'Relocation',
        };
    }

    /**
     * A one-line description of the event, shown next to the event-type checkbox when setting up a
     * watch and on the signal card.
     */
    public function description(): string
    {
        return match ($this) {
            self::Acquisition => 'A company is sold or a founder exits — a classic liquidity event.',
            self::FundingRound => 'A business raises a funding round, putting fresh capital in founders’ hands.',
            self::Relocation => 'A founder, executive or family office relocates to the market.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
