<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Services;

use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Data\CriterionCalibration;
use App\Modules\ICP\Data\IcpCalibration;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds an {@see IcpCalibration} from the scores already stored against a profile — the real
 * implementation of the ICP module's {@see IcpCalibrationSource} seam, bound by
 * `ScoringServiceProvider`. No AI, no re-grading: it reads `lead_scores`, so it costs a few queries
 * and tells a team the truth about how their active profile is grading leads *now*.
 *
 * The grade mix is counted over every graded lead (a cheap grouped count). The per-criterion
 * outcome counts are read from each score's stored `breakdown` (a JSON column), so they are taken
 * from a **capped, most-recent sample** (`scoring.calibration_sample`) to bound the work on a team
 * with many thousands of leads; the mix is exact, the criterion picture is a recent read of it.
 */
final class IcpCalibrator implements IcpCalibrationSource
{
    public function summarise(Icp $icp): IcpCalibration
    {
        $base = LeadScore::query()
            ->where('team_id', $icp->team_id)
            ->where('icp_id', $icp->getKey());

        $total = (clone $base)->count();

        if ($total === 0) {
            return IcpCalibration::empty();
        }

        $grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];

        foreach ((clone $base)->selectRaw('grade, count(*) as aggregate')->groupBy('grade')->pluck('aggregate', 'grade') as $grade => $count) {
            if (array_key_exists((string) $grade, $grades)) {
                $grades[(string) $grade] = (int) $count;
            }
        }

        $excluded = (clone $base)->where('excluded', true)->count();

        $cap = max(1, (int) config('scoring.calibration_sample', 500));

        /** @var Collection<int, LeadScore> $sample */
        $sample = (clone $base)->orderByDesc('scored_at')->limit($cap)->get(['breakdown']);

        $criteria = $this->aggregateCriteria($sample);

        return new IcpCalibration(
            scoredLeads: $total,
            excluded: $excluded,
            grades: $grades,
            criteria: $criteria,
            sampleSize: $sample->count(),
        );
    }

    /**
     * Fold every sampled score's breakdown into one row per criterion (type + value), counting each
     * outcome. Exclusion rows are skipped — they are a disqualifier, not a weighted signal — and the
     * result is ordered heaviest first, because the heavy criteria are the ones deciding grades.
     *
     * @param  Collection<int, LeadScore>  $sample
     * @return list<CriterionCalibration>
     */
    private function aggregateCriteria($sample): array
    {
        /** @var array<string, array{type: CriterionType, value: string, weight: int|null, matched: int, not_matched: int, weak: int, unknown: int}> $acc */
        $acc = [];

        foreach ($sample as $score) {
            foreach ($score->breakdown as $row) {
                if ($row->type === CriterionType::Exclusion) {
                    continue; // a disqualifier, not a weighted signal
                }

                $key = $row->type->value.'|'.Str::lower(trim($row->value));

                if (! isset($acc[$key])) {
                    $acc[$key] = [
                        'type' => $row->type,
                        'value' => $row->value,
                        'weight' => $row->weight,
                        'matched' => 0,
                        'not_matched' => 0,
                        'weak' => 0,
                        'unknown' => 0,
                    ];
                }

                $bucket = match ($row->match) {
                    CriterionMatch::Matched => 'matched',
                    CriterionMatch::NotMatched => 'not_matched',
                    CriterionMatch::Weak => 'weak',
                    CriterionMatch::Unknown => 'unknown',
                };

                $acc[$key][$bucket]++;
            }
        }

        $criteria = array_map(
            static fn (array $row): CriterionCalibration => new CriterionCalibration(
                type: $row['type']->value,
                typeLabel: $row['type']->label(),
                value: $row['value'],
                weight: $row['weight'],
                matched: $row['matched'],
                notMatched: $row['not_matched'],
                weak: $row['weak'],
                unknown: $row['unknown'],
            ),
            array_values($acc),
        );

        usort(
            $criteria,
            static fn (CriterionCalibration $a, CriterionCalibration $b): int => ($b->weight ?? 0) <=> ($a->weight ?? 0),
        );

        return $criteria;
    }
}
