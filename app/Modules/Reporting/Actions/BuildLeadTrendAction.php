<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Data\TrendPoint;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\TrendBuckets;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leads sourced per bucket against how many of them turned out to be a strong fit (Reporting).
 *
 * It is a **cohort** read, not two unrelated series: both numbers key off the bucket the lead
 * arrived in, so a bar says "of the 40 we found that week, 11 were worth calling" rather than
 * mixing arrival dates with scoring dates. That is the question a prospecting team actually asks,
 * and it keeps the second series bounded by the first — which is what makes the chart readable.
 *
 * Bucket width comes from the selected window (see {@see TrendBuckets}), so the chart is daily
 * under a short filter and monthly under a long one without anything here changing.
 *
 * Two queries, each bucketing in SQL; no per-bucket round trips and no unbounded row fetch.
 */
final readonly class BuildLeadTrendAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @param  int  $fallbackPeriods  Buckets to show when the window is open-ended (all time).
     * @return list<TrendPoint>
     */
    public function handle(Team $team, DateRange $range, int $fallbackPeriods = 12): array
    {
        $buckets = TrendBuckets::forRange($range, $fallbackPeriods);

        return $this->teamContext->run($team, function () use ($team, $buckets): array {
            $sourced = $buckets->readRow($this->row($this->leads($team), $buckets));
            $qualified = $buckets->readRow($this->row(
                $this->leads($team)->whereHas(
                    'activeScore',
                    fn (Builder $query): Builder => $query
                        ->where('excluded', false)
                        ->whereIn('grade', [ScoreGrade::A, ScoreGrade::B]),
                ),
                $buckets,
            ));

            $points = [];

            foreach ($buckets->buckets as $index => $bucket) {
                $points[] = new TrendPoint(
                    label: $bucket['label'],
                    starts_on: $bucket['starts_on'],
                    sourced: $sourced[$index] ?? 0,
                    qualified: $qualified[$index] ?? 0,
                );
            }

            return $points;
        });
    }

    /**
     * @return Builder<Lead>
     */
    private function leads(Team $team): Builder
    {
        return Lead::query()->where('leads.team_id', $team->getKey());
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array<string, mixed>
     */
    private function row(Builder $query, TrendBuckets $buckets): array
    {
        /** @var object|null $row */
        $row = $query
            // The buckets already carry the window, so this is only a floor for the planner — it
            // keeps the scan off rows older than the first bar.
            //
            // `->utc()` for the same reason {@see DateRange::constrain()} does it: the bucket start
            // is a team-timezone instant, and Laravel binds a date by formatting it without
            // converting. An unconverted floor here would cut the first bar short for any team
            // east of UTC while the funnel still counted those leads — the two figures would
            // disagree about the same window.
            ->where('leads.created_at', '>=', $buckets->from()->utc())
            ->selectRaw($buckets->countSelect('leads.created_at'), $buckets->bindings())
            ->toBase()
            ->first();

        return $row === null ? [] : (array) $row;
    }
}
