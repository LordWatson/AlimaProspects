<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\Signal;

/**
 * Why a member dismissed a {@see Signal} (ADR 0011, S4). Optional — a member who just wants the
 * card gone shouldn't have to justify it — but stored when given, because the reasons are the only
 * honest signal of *how* the extraction is wrong: "not an event" says the classifier is too loose,
 * "not relevant" says the queries or the relevance floor are, and "already known" says neither is
 * (the feed simply found a company the team already works). The post-v1 backlog learns from them;
 * v1 only needs to keep them.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum SignalDismissReason: string
{
    /** A real event, but not a prospect for this team. */
    case NotRelevant = 'not_relevant';

    /** A real, relevant event about a company the team already has. */
    case AlreadyKnown = 'already_known';

    /** Not an event at all — a listicle, an opinion piece, a rumour. A classifier miss. */
    case NotAnEvent = 'not_an_event';

    public function label(): string
    {
        return match ($this) {
            self::NotRelevant => 'Not relevant',
            self::AlreadyKnown => 'Already known',
            self::NotAnEvent => 'Not an event',
        };
    }

    /**
     * Whether this reason says the AI classified the item wrongly (as opposed to classifying it
     * correctly and the team not wanting it). Tuning reads this, not the raw counts.
     */
    public function indicatesMisclassification(): bool
    {
        return $this === self::NotAnEvent;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
