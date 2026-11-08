<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Enums;

use App\Modules\Scoring\Services\DeterministicScorer;
use App\Modules\Scoring\Services\ScoringPayloadBuilder;

/**
 * How one ICP criterion fared against a lead in the {@see DeterministicScorer}.
 *
 * Two of the four are the reason the rule half can be trusted:
 *
 * - {@see self::Unknown} — the lead has **no data** on the criterion's dimension. We cannot call
 *   that a failure, so the weight is left out of **both** sides of the ratio. That keeps the score
 *   about fit, not about how much enrichment has run.
 * - {@see self::Weak} — the lead **has** data but the rules could not settle it *confidently*: an
 *   industry phrased in a vocabulary the taxonomy doesn't carry, a contact title the ladder can't
 *   rank, a technology or job title that didn't match literally. A literal string compare across
 *   two different vocabularies ("SaaS" vs "cloud software development") says *nothing* reliable, so
 *   treating a non-match there as a hard failure marked good leads down the moment enrichment gave
 *   them an industry at all. A `Weak` row abstains from the ratio exactly like `Unknown`, but it is
 *   kept distinct because it is **handed to the AI half to adjudicate** (see
 *   {@see ScoringPayloadBuilder}) — the rules defer, they don't guess.
 *
 * {@see self::NotMatched} is therefore reserved for a *confident* negative: a company-size band that
 * genuinely doesn't overlap, a location that isn't the one wanted, two industries the taxonomy
 * places in different sectors. Only those count against a lead.
 *
 * Values are stable storage keys (they persist inside `lead_scores.breakdown`).
 */
enum CriterionMatch: string
{
    case Matched = 'matched';
    case NotMatched = 'not_matched';
    case Weak = 'weak';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Match',
            self::NotMatched => 'No match',
            self::Weak => 'Unconfirmed',
            self::Unknown => 'No data',
        };
    }

    /**
     * Whether this outcome counts towards the weighted ratio at all. `Weak` and `Unknown` both
     * abstain — the first because the rules couldn't settle it, the second because there was no
     * data — so only a confident match or miss moves the deterministic score.
     */
    public function isAssessed(): bool
    {
        return $this === self::Matched || $this === self::NotMatched;
    }

    /**
     * Whether the lead held data the rules could not settle, so the AI half must adjudicate it.
     * Distinct from {@see self::Unknown} (no data at all), which the model is told nothing about.
     */
    public function isDeferred(): bool
    {
        return $this === self::Weak;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $match): string => $match->value, self::cases());
    }
}
