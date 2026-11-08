<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\Scoring\Enums\FeedbackRating;

/**
 * A member's verdict on one item of evidence (PL1) — the reason-level sibling of
 * {@see FeedbackRating}, which does the same job for a score.
 *
 * Four cases and only one of them changes anything today: {@see self::Wrong} says *this did not
 * happen, or not to this company*, and an item flagged wrong contributes nothing to the lead's
 * confidence from that moment. The other three are kept and reported, and deliberately **do not**
 * move a number: "outdated" is what decay already measures, "irrelevant" is a statement about this
 * team's interests rather than about the fact, and "useful" is the signal the post-launch outcome
 * loop will learn from.
 *
 * That asymmetry is the point. A system that let four buttons each nudge a weight would be learning
 * from four different questions at once, and nobody could say afterwards what a confidence of 61
 * had been told.
 */
enum EvidenceVerdict: string
{
    /** Didn't happen, or not to this company. Zeroes the item's contribution. */
    case Wrong = 'wrong';

    /** It happened, but it's old news. Recorded; decay is what already answers this. */
    case Outdated = 'outdated';

    /** True, and not a reason this team cares about. Recorded, never applied to the fact. */
    case Irrelevant = 'irrelevant';

    /** This is why I picked up the phone. The one the outcome loop will want. */
    case Useful = 'useful';

    public function label(): string
    {
        return match ($this) {
            self::Wrong => 'Wrong',
            self::Outdated => 'Out of date',
            self::Irrelevant => 'Not relevant',
            self::Useful => 'Useful',
        };
    }

    /**
     * Whether this verdict disputes the fact itself — the only kind that changes a lead's
     * confidence.
     */
    public function disputesTheFact(): bool
    {
        return $this === self::Wrong;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $verdict): string => $verdict->value, self::cases());
    }
}
