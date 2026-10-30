<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Enums;

use App\Modules\Scoring\Services\DeterministicScorer;

/**
 * How one ICP criterion fared against a lead in the {@see DeterministicScorer}.
 *
 * {@see self::Unknown} is the important one: when the lead simply has no data for a criterion's
 * dimension, we cannot say it fails — so the criterion's weight is left out of **both** sides of
 * the ratio rather than counted against the lead. That keeps the fit score about fit, not about
 * data coverage, and keeps the explanation grounded in what we actually know.
 *
 * Values are stable storage keys (they persist inside `lead_scores.breakdown`).
 */
enum CriterionMatch: string
{
    case Matched = 'matched';
    case NotMatched = 'not_matched';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Match',
            self::NotMatched => 'No match',
            self::Unknown => 'No data',
        };
    }

    /**
     * Whether this outcome counts towards the weighted ratio at all.
     */
    public function isAssessed(): bool
    {
        return $this !== self::Unknown;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $match): string => $match->value, self::cases());
    }
}
