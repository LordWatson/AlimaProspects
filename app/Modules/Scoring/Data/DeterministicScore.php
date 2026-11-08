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
        public int $deferredWeight = 0,
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
     * The rules' **authority** over the data the lead actually held (0.0–1.0): of the weight the
     * rules *could* have judged (assessed + deferred), the share they settled confidently —
     * `assessedWeight / (assessedWeight + deferredWeight)`. The blend scales the deterministic half
     * by this, so when the rules settled a heavy criterion confidently but punted a light one to the
     * AI they keep most of their say, and when they confidently settled only a light criterion while
     * deferring the heavy one (industry phrased in an unknown vocabulary) their say shrinks and the
     * AI half — which judged the deferral — carries the score.
     *
     * Deliberately ignores {@see CriterionMatch::Unknown} weight: missing data is a blind spot the
     * *AI half shares*, so it is no reason to shift authority between them. 1.0 when nothing was
     * deferred.
     */
    public function coverage(): float
    {
        $judgeable = $this->assessedWeight + $this->deferredWeight;

        if ($judgeable <= 0) {
            return 0.0;
        }

        return min(1.0, $this->assessedWeight / $judgeable);
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
