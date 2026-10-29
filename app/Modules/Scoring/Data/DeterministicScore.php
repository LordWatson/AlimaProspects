<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Data;

use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Services\DeterministicScorer;
use Spatie\LaravelData\Data;

/**
 * The rule-based half of the hybrid score: a normalised 0–100 fit figure plus the per-criterion
 * {@see CriterionScore} breakdown that produced it. Returned by the {@see DeterministicScorer};
 * no AI involved, so it is fully reproducible for a given (lead, ICP) pair.
 *
 * `score` is `matchedWeight / assessedWeight`, where *assessed* excludes criteria the lead has no
 * data for — see {@see CriterionMatch::Unknown}. When nothing could be
 * assessed, {@see self::hasSignals()} is false and the score is 0 but meaningless: the caller
 * leans on the AI half instead. A matched hard exclusion sets {@see self::$excluded} and forces 0.
 */
final class DeterministicScore extends Data
{
    /**
     * @param  list<CriterionScore>  $breakdown
     */
    public function __construct(
        public int $score,
        public array $breakdown = [],
        public bool $excluded = false,
        public ?string $excludedBy = null,
        public int $matchedWeight = 0,
        public int $assessedWeight = 0,
    ) {}

    /**
     * Whether anything at all could be assessed from the lead's data (and so whether `score` means
     * anything). A lead with no enriched fields, or an ICP with only exclusions, yields false.
     */
    public function hasSignals(): bool
    {
        return $this->assessedWeight > 0;
    }

    /**
     * An excluded lead: the ICP names something about it as a hard disqualifier.
     *
     * @param  list<CriterionScore>  $breakdown
     */
    public static function excludedBy(string $value, array $breakdown = []): self
    {
        return new self(score: 0, breakdown: $breakdown, excluded: true, excludedBy: $value);
    }
}
