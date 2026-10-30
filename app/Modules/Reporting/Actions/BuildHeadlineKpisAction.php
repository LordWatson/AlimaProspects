<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Reporting\Data\Kpi;
use App\Modules\Reporting\Data\TrendPoint;
use App\Modules\Reporting\Enums\KpiFormat;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Illuminate\Database\Eloquent\Builder;

/**
 * The four headline tiles at the top of the dashboard.
 *
 * Only the leads tile carries a delta, and it is a true like-for-like: the selected window against
 * the window of equal length before it (see {@see DateRange::previous()}). The others get a caption
 * instead, because "signals waiting" compared with last month is not a trend — it is a queue — and
 * a number dressed up as a movement invites the wrong decision. All time has no window before it,
 * so it has no delta at all.
 *
 * The labels change with the filter rather than staying put: "leads in your list" is an inventory
 * figure and is only true unfiltered. Under a window the same tile is "leads sourced", because
 * that is what the number now is.
 *
 * The sparklines are read off the trend the dashboard already built, so a tile costs no extra
 * query.
 */
final readonly class BuildHeadlineKpisAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @param  list<TrendPoint>  $trend  The series from {@see BuildLeadTrendAction}.
     * @return list<Kpi>
     */
    public function handle(Team $team, DateRange $range, array $trend): array
    {
        $previous = $range->previous();

        return $this->teamContext->run($team, function () use ($team, $range, $previous, $trend): array {
            $leads = $this->leads($team, $range)->count();

            $delta = $previous === null
                ? null
                : $leads - $this->leads($team, $previous)->count();

            $strongFits = $this->leads($team, $range)
                ->whereHas(
                    'activeScore',
                    fn (Builder $query): Builder => $query
                        ->where('excluded', false)
                        ->whereIn('grade', [ScoreGrade::A, ScoreGrade::B]),
                )
                ->count();

            // A queue, scoped by when the signal was found: "trigger events from this period that
            // nobody has acted on".
            $waitingSignals = $range->constrain(
                Signal::query()
                    ->where('signals.team_id', $team->getKey())
                    ->where('status', SignalStatus::New),
                'signals.created_at',
            )->count();

            // Spend is activity, not a lead cohort, so it is scoped by when the call was made.
            $spendMicros = (int) $range->constrain(
                AiRequest::query()->where('ai_requests.team_id', $team->getKey()),
                'ai_requests.created_at',
            )->sum('cost_micros');

            return [
                new Kpi(
                    key: 'leads',
                    label: $range->isBounded() ? 'Prospects sourced' : 'Leads in your list',
                    value: $leads,
                    caption: $range->isBounded()
                        ? 'Companies Alima found for you in this period'
                        : 'Every company Alima has found for you',
                    delta: $delta,
                    delta_label: $delta === null ? null : $range->preset->deltaLabel(),
                    spark: array_map(static fn (TrendPoint $point): int => $point->sourced, $trend),
                ),
                new Kpi(
                    key: 'strong_fits',
                    label: 'Strong fits',
                    value: $strongFits,
                    // A key, never an assembled sentence: the frontend translates these, and
                    // `sprintf` here would ship English to an Arabic screen. The "out of N
                    // scored" denominator is on the fit-mix chart, which owns that number.
                    caption: 'Graded A or B against your active ideal customer',
                    spark: array_map(static fn (TrendPoint $point): int => $point->qualified, $trend),
                ),
                new Kpi(
                    key: 'signals_waiting',
                    label: 'Signals waiting',
                    value: $waitingSignals,
                    caption: 'Trigger events nobody has acted on yet',
                ),
                new Kpi(
                    key: 'ai_spend',
                    label: $range->preset === ReportRange::ThisMonth
                        ? 'AI & data this month'
                        : 'AI & data',
                    // Micros to minor units: 1,000,000 micros to the major unit, 100 minor units
                    // to it, so 10,000 micros per minor unit. Money stays integer (CLAUDE.md §2).
                    value: intdiv($spendMicros, 10_000),
                    caption: 'What Alima spent finding and grading for you',
                    format: KpiFormat::Money,
                    currency: self::currency($team),
                ),
            ];
        });
    }

    /**
     * The team's market currency. Spend is summed in micros across providers; the code is for
     * display only, and an unrecognised market falls back to the UK default rather than failing
     * the whole dashboard over a currency label.
     */
    public static function currency(Team $team): string
    {
        return (Market::tryFrom((string) $team->market) ?? Market::Uk)
            ->defaultCurrency()
            ->code();
    }

    /**
     * @return Builder<Lead>
     */
    private function leads(Team $team, DateRange $range): Builder
    {
        return $range->constrain(
            Lead::query()->where('leads.team_id', $team->getKey()),
            'leads.created_at',
        );
    }
}
