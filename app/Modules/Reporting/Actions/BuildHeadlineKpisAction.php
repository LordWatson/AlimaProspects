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
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * The four headline tiles at the top of the dashboard.
 *
 * Only the leads tile carries a delta, and it is a true like-for-like: the last 30 days against
 * the 30 before them. The others get a caption instead, because "signals waiting" compared with
 * last month is not a trend — it is a queue — and a number dressed up as a movement invites the
 * wrong decision.
 *
 * The sparklines are read off the trend the dashboard already built, so a tile costs no extra
 * query.
 */
final readonly class BuildHeadlineKpisAction
{
    private const WINDOW_DAYS = 30;

    public function __construct(private TeamContext $teamContext) {}

    /**
     * @param  list<TrendPoint>  $trend  The weekly series from {@see BuildLeadTrendAction}.
     * @return list<Kpi>
     */
    public function handle(Team $team, array $trend): array
    {
        /** @var CarbonImmutable $now */
        $now = Date::now((string) $team->timezone);

        $currentFrom = $now->subDays(self::WINDOW_DAYS);
        $previousFrom = $currentFrom->subDays(self::WINDOW_DAYS);

        return $this->teamContext->run($team, function () use ($team, $now, $trend, $currentFrom, $previousFrom): array {
            $leads = $this->leads($team)->count();
            $recent = $this->leads($team)->where('leads.created_at', '>=', $currentFrom)->count();
            $previous = $this->leads($team)
                ->where('leads.created_at', '>=', $previousFrom)
                ->where('leads.created_at', '<', $currentFrom)
                ->count();

            $strongFits = $this->leads($team)
                ->whereHas(
                    'activeScore',
                    fn (Builder $query): Builder => $query
                        ->where('excluded', false)
                        ->whereIn('grade', [ScoreGrade::A, ScoreGrade::B]),
                )
                ->count();

            $waitingSignals = Signal::query()
                ->where('signals.team_id', $team->getKey())
                ->where('status', SignalStatus::New)
                ->count();

            $spendMicros = (int) AiRequest::query()
                ->where('ai_requests.team_id', $team->getKey())
                ->where('ai_requests.created_at', '>=', $now->startOfMonth())
                ->sum('cost_micros');

            return [
                new Kpi(
                    key: 'leads',
                    label: 'Leads in your list',
                    value: $leads,
                    caption: 'Every company Alima has found for you',
                    delta: $recent - $previous,
                    delta_label: 'vs the 30 days before',
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
                    label: 'AI & data this month',
                    // Micros to minor units: 1,000,000 micros to the major unit, 100 minor units
                    // to it, so 10,000 micros per minor unit. Money stays integer (CLAUDE.md §2).
                    value: intdiv($spendMicros, 10_000),
                    caption: 'What Alima spent finding and grading for you',
                    format: KpiFormat::Money,
                    currency: $this->currency($team),
                ),
            ];
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
     * The team's market currency. Spend is summed in micros across providers; the code is for
     * display only, and an unrecognised market falls back to the UK default rather than failing
     * the whole dashboard over a currency label.
     */
    private function currency(Team $team): string
    {
        return (Market::tryFrom((string) $team->market) ?? Market::Uk)
            ->defaultCurrency()
            ->code();
    }
}
