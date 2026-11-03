<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Whether a playbook cares that the signal is about an account the team already watches (S14).
 *
 * A three-case enum rather than a nullable boolean, because the three answers are genuinely
 * different instructions and the middle one is the valuable one: "only events about companies I am
 * already working" is the rule most teams want first, and "only events about companies I am *not*"
 * is how the other half of the feed gets its own handling. A null boolean would make "either" look
 * like an unset field somebody forgot.
 *
 * The question is asked of `signals.matched_lead_id` — the lead this event is **about** (S10) —
 * rather than of the watch's watchlist, because a topical watch can perfectly well turn up news
 * about a company in the pipeline, and that is exactly the case the rule means.
 */
enum PlaybookWatchlistCondition: string
{
    /** Don't care. */
    case Any = 'any';

    /** Only events Alima matched to one of the team's leads. */
    case Matched = 'matched';

    /** Only events about companies the team has no record of. */
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Either',
            self::Matched => 'Only companies I already have',
            self::Unmatched => 'Only companies new to me',
        };
    }

    public function matches(bool $hasMatchedLead): bool
    {
        return match ($this) {
            self::Any => true,
            self::Matched => $hasMatchedLead,
            self::Unmatched => ! $hasMatchedLead,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
