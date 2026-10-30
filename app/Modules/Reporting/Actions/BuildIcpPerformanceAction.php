<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Support\Collection;

/**
 * How each ideal customer profile is doing at picking companies out (Phase 13).
 *
 * The question it answers is not "which profile scores highest" — a lenient profile does that by
 * being lenient — but **how much of what it graded was worth calling**: the strong-fit rate, beside
 * the volume it was asked to judge. A profile that grades 400 companies and finds four As is
 * telling the team something about the profile, not about the market.
 *
 * Retired profiles are included and marked. A team usually rewrites its ICP two or three times in
 * its first month, and "the last one was better" is the single most useful thing this table can
 * say; dropping the retired rows would hide it.
 *
 * Scoped by the lead cohort (see {@see DateRange}): of the prospects sourced in the window, how
 * each profile graded them. Excluded scores — a matched ICP exclusion, a hard disqualifier — are
 * counted in their own column rather than folded into D, because "ruled out by your own exclusion"
 * and "graded poorly" are different events.
 */
final readonly class BuildIcpPerformanceAction
{
    /**
     * The status cell's two values, as translation keys.
     *
     * Constants rather than inline strings because they are the cell's whole vocabulary and the
     * column is flagged `translated` — a typo here would ship an untranslated English word into
     * an Arabic report, which is the one failure the flag exists to prevent. Not an enum: there is
     * no `status` column behind them, only `icps.is_active`.
     */
    private const string ACTIVE = 'Active';

    private const string RETIRED = 'Retired';

    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, DateRange $range): ReportTable
    {
        $rows = $this->teamContext->run($team, function () use ($team, $range): Collection {
            /**
             * @var Collection<int, object{
             *     icp_id: int|string,
             *     icp_name: string,
             *     is_active: int|bool,
             *     scored: int|string,
             *     excluded: int|string,
             *     strong: int|string,
             *     average: float|string|null,
             * }> $aggregates
             */
            $aggregates = $range->constrain(
                LeadScore::query()
                    ->join('leads', 'leads.id', '=', 'lead_scores.lead_id')
                    ->join('icps', 'icps.id', '=', 'lead_scores.icp_id')
                    ->where('lead_scores.team_id', $team->getKey())
                    // Both joined tables are pinned as well: the global scope only constrains
                    // `lead_scores`, and an ICP **name** is the one thing in this report that
                    // would be worth leaking. Today only foreign-key integrity says a stray
                    // pointer cannot exist, which is not the same as a query that cannot read one.
                    ->where('leads.team_id', $team->getKey())
                    ->where('icps.team_id', $team->getKey()),
                'leads.created_at',
            )
                ->groupBy('icps.id', 'icps.name', 'icps.is_active')
                ->orderByDesc('icps.is_active')
                ->orderByDesc('scored')
                ->selectRaw(
                    'icps.id as icp_id, icps.name as icp_name, icps.is_active as is_active, '
                    .'count(*) as scored, '
                    // Conditional aggregation rather than four queries: one pass over the same
                    // rows, and the columns are literal so nothing here is assembled from input.
                    .'sum(case when lead_scores.excluded = 1 then 1 else 0 end) as excluded, '
                    .'sum(case when lead_scores.excluded = 0 and lead_scores.grade in (?, ?) then 1 else 0 end) as strong, '
                    .'avg(lead_scores.score) as average',
                    [ScoreGrade::A->value, ScoreGrade::B->value],
                )
                ->toBase()
                ->get();

            return $aggregates;
        });

        $reportRows = [];

        foreach ($rows as $row) {
            $scored = (int) $row->scored;
            $excluded = (int) $row->excluded;
            $strong = (int) $row->strong;

            $reportRows[] = new ReportRow(
                key: 'icp-'.$row->icp_id,
                cells: [
                    'icp' => $row->icp_name,
                    // The profile the team is working to now, versus one it has moved on from.
                    'status' => $row->is_active ? self::ACTIVE : self::RETIRED,
                    'scored' => $scored,
                    'strong' => $strong,
                    // Of what it actually graded: an exclusion is a disqualification, not a
                    // judgement of fit, so it must not sit in the denominator.
                    'strong_rate' => Ratio::of($strong, $scored - $excluded),
                    'excluded' => $excluded,
                    'average' => $row->average === null ? null : (int) round((float) $row->average),
                ],
            );
        }

        return ReportTable::of(
            ReportKey::IcpPerformance,
            $this->columns(),
            $reportRows,
            'No prospects were graded in this period.',
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(): array
    {
        return [
            new ReportColumn('icp', 'Ideal customer'),
            ReportColumn::translated('status', 'Status'),
            ReportColumn::count('scored', 'Graded'),
            ReportColumn::count('strong', 'Strong fits'),
            ReportColumn::percent('strong_rate', 'Strong-fit rate'),
            ReportColumn::count('excluded', 'Ruled out'),
            ReportColumn::count('average', 'Average score'),
        ];
    }
}
