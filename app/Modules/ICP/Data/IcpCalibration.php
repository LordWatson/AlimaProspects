<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

use App\Modules\ICP\Contracts\IcpCalibrationSource;

/**
 * A read-only picture of how a profile is *actually* grading a team's leads — the grade mix and,
 * per criterion, how often each one matched, missed, was deferred to the AI half or had no data.
 * It turns an ICP from a fire-and-forget definition into something a team can tune: if most leads
 * are C/D, this says which criterion is doing the rejecting.
 *
 * Built by a {@see IcpCalibrationSource} over the scores already stored
 * (no AI spend, no re-grading). The grade mix is over every graded lead; the per-criterion counts
 * are over a capped recent sample, because they are read from each score's stored breakdown.
 */
final class IcpCalibration
{
    /**
     * @param  array<string, int>  $grades  Keyed by grade letter (A, B, C, D).
     * @param  list<CriterionCalibration>  $criteria  Heaviest first.
     */
    public function __construct(
        public readonly int $scoredLeads,
        public readonly int $excluded,
        public readonly array $grades,
        public readonly array $criteria,
        public readonly int $sampleSize,
    ) {}

    public static function empty(): self
    {
        return new self(
            scoredLeads: 0,
            excluded: 0,
            grades: ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
            criteria: [],
            sampleSize: 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scored_leads' => $this->scoredLeads,
            'excluded' => $this->excluded,
            'grades' => $this->grades,
            'criteria' => array_map(
                static fn (CriterionCalibration $criterion): array => $criterion->toArray(),
                $this->criteria,
            ),
            'sample_size' => $this->sampleSize,
        ];
    }
}
