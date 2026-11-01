<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Data\PlatformMargin;
use App\Modules\Admin\Data\PlatformReportPage;
use App\Modules\Admin\Data\PlatformReportTable;
use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\Admin\Enums\PlatformReport;
use App\Modules\Admin\Support\PlatformWindow;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Data\FunnelStep;
use App\Modules\Reporting\Data\Kpi;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Enums\ColumnFormat;
use App\Modules\Reporting\Enums\KpiFormat;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The platform's reports, built from the rollup (Phase 19c).
 *
 * **Its own builders, not Reporting's Actions**, and the distinction is the first task of this
 * phase. Reporting's builders are scoped by design and each of their figures has a cohort rule
 * written for one team's window; running them with the scope removed would not produce a
 * cross-tenant version of the same report, it would silently answer a different question. So the
 * platform asks its own questions here.
 *
 * What it *does* reuse is the discipline and the plumbing: {@see DateRange} and its UTC rule,
 * {@see Ratio}'s refusal to divide by nothing, and the `ReportColumn`/`ReportRow`/`Kpi`/
 * `FunnelStep`/`BreakdownSlice` shapes — so one Vue chart set and one CSV writer serve both.
 *
 * Every figure comes from {@see PlatformMetricStore}, never from a live cross-tenant scan: a sum
 * over `ai_requests` is the one query in this product with no `team_id` to narrow it.
 */
final class PlatformReportBuilder
{
    public function __construct(
        private readonly PlatformMetricStore $store,
        private readonly PlatformMarginBuilder $margins,
        private readonly PlatformMoney $money,
        private readonly PlanRegistry $plans,
    ) {}

    public function build(DateRange $range): PlatformReportPage
    {
        $margins = $this->margins->perTeam($range);
        $tables = $this->tables($range, $margins);

        $hasData = false;

        foreach ($tables as $table) {
            if ($table->rows !== []) {
                $hasData = true;

                break;
            }
        }

        return new PlatformReportPage(
            kpis: $this->kpis($range, $margins),
            funnel: $this->funnel($range),
            signups_by_market: $this->signupsByMarket($range),
            conversion_days: $this->conversionDays($range),
            tables: $tables,
            currency: $this->money->currency(),
            fx_rates: $this->money->rates(),
            last_rolled_up: $this->store->lastRolledUpDay(),
            range_from: $range->fromDate(),
            range_to: $range->toDate(),
            range: $range->preset->value,
            has_data: $hasData,
            has_unconverted_cost: array_any(
                $margins,
                static fn (PlatformMargin $row): bool => $row->isPartial(),
            ),
        );
    }

    /**
     * One table, for the CSV export — built the same way the screen's is, so a download and the
     * tab it came from cannot disagree.
     */
    public function one(DateRange $range, PlatformReport $report): PlatformReportTable
    {
        foreach ($this->tables($range, $this->margins->perTeam($range)) as $table) {
            if ($table->key === $report) {
                return $table;
            }
        }

        // Unreachable while `tables()` covers every case, which `PlatformReportingTest` asserts.
        return PlatformReportTable::of($report, [], []);
    }

    /* -------------------------------------------------------------- Chart layer */

    /**
     * The tiles. Deltas come from {@see DateRange::previous()} — the window of equal length
     * immediately before this one, which is why "this month so far" is compared against the same
     * number of days rather than against the whole of last month.
     *
     * MRR's delta is the **movement** over the window rather than a difference of two snapshots,
     * because that is the figure that is actually explainable: a difference of snapshots tells you
     * revenue moved, and the movement figures tell you why.
     *
     * @param  list<PlatformMargin>  $margins
     * @return list<Kpi>
     */
    private function kpis(DateRange $range, array $margins): array
    {
        // `PlatformWindow::previous()`, not `DateRange::previous()`. The latter is an interval of
        // instants, and a rolling preset's bounds are mid-day — reduced to whole days by the store,
        // its comparison window overlaps the current one on the boundary date and spans one date
        // more. Every delta on this screen would be biased by about a thirtieth, in the direction
        // nobody checks.
        $previous = PlatformWindow::previous($range);
        $currency = $this->money->currency();

        $mrr = $this->store->total(PlatformMetric::Mrr, $range);
        $movement = 0;

        foreach (PlatformMetric::movement() as $metric) {
            $movement += $this->store->total($metric, $range);
        }

        $marginTotal = 0;
        $revenueTotal = 0;

        foreach ($margins as $row) {
            $marginTotal += $row->margin_minor;
            $revenueTotal += $row->revenue_minor;
        }

        return [
            $this->kpi(PlatformMetric::Signups, $range, $previous),
            $this->kpi(PlatformMetric::Activations, $range, $previous),
            $this->kpi(PlatformMetric::TrialConversions, $range, $previous),
            new Kpi(
                key: 'mrr',
                label: PlatformMetric::Mrr->label(),
                value: $mrr,
                caption: PlatformMetric::Mrr->definition(),
                format: KpiFormat::Money,
                currency: $currency,
                delta: $movement,
                delta_label: 'movement over this window',
                spark: array_values($this->store->series(PlatformMetric::Mrr, $range)),
            ),
            new Kpi(
                key: 'arr',
                label: 'ARR',
                value: $mrr * 12,
                caption: 'MRR at the end of the window, annualised. Not a forecast — twelve times one month.',
                format: KpiFormat::Money,
                currency: $currency,
            ),
            new Kpi(
                key: 'gross_margin',
                label: 'Gross margin',
                value: $marginTotal,
                caption: 'Revenue attributed to this window less the provider cost of serving it, across every tenant and the unattributed platform spend.',
                format: KpiFormat::Money,
                currency: $currency,
                delta: null,
                delta_label: $this->marginCaption($marginTotal, $revenueTotal),
            ),
            $this->kpi(PlatformMetric::PayingTeams, $range, $previous),
            new Kpi(
                key: 'allowance_ceiling',
                label: 'Ceilings hit',
                value: $this->store->total(PlatformMetric::AllowanceCeiling, $range),
                // Deliberately not "tenants at the ceiling": the figure sums across both metered
                // metrics, so a tenant that has exhausted its leads **and** its credits is counted
                // twice. That is the right number for "how many allowance problems are there" and
                // the wrong one for "how many customers are stuck", and the label has to pick.
                caption: 'Allowances exhausted, counted per metric — a tenant out of both leads and '
                    .'credits counts twice. The pricing report breaks it down.',
            ),
        ];
    }

    /**
     * The gross-margin tile's subtitle, or null when there is no revenue to express it against.
     *
     * Null rather than "0% of revenue", for {@see Ratio}'s reason: a platform with no paying
     * tenants has no margin percentage, and printing one would invent a denominator.
     */
    private function marginCaption(int $margin, int $revenue): ?string
    {
        $ratio = Ratio::of($margin, $revenue);

        return $ratio === null ? null : 'margin '.round($ratio * 100, 1).'% of revenue';
    }

    private function kpi(PlatformMetric $metric, DateRange $range, ?DateRange $previous): Kpi
    {
        $value = $this->store->total($metric, $range);

        return new Kpi(
            key: $metric->value,
            label: $metric->label(),
            value: $value,
            caption: $metric->definition(),
            format: $metric->kind()->format() === ColumnFormat::Money
                ? KpiFormat::Money
                : KpiFormat::Count,
            currency: null,
            delta: $previous === null ? null : $value - $this->store->total($metric, $previous),
            delta_label: $previous === null ? null : 'vs the window before',
            spark: array_values($this->store->series($metric, $range)),
        );
    }

    /**
     * The onboarding funnel over the signup cohorts **inside the window**.
     *
     * The window selects cohorts, not events, which is the cohort rule this report lives or dies
     * by: "ran a discovery" means *of the tenants that signed up in this window, how many have
     * since run one* — not "how many discoveries happened in this window". Those are different
     * questions and only the first one has a funnel shape.
     *
     * @return list<FunnelStep>
     */
    private function funnel(DateRange $range): array
    {
        return array_map(
            fn (PlatformMetric $metric): FunnelStep => new FunnelStep(
                key: $metric->value,
                label: $metric->label(),
                caption: $metric->definition(),
                value: $this->store->total($metric, $range),
            ),
            PlatformMetric::funnel(),
        );
    }

    /**
     * @return list<BreakdownSlice>
     */
    private function signupsByMarket(DateRange $range): array
    {
        $slices = [];

        foreach ($this->store->byDimension(PlatformMetric::Signups, $range) as $market => $count) {
            $slices[] = new BreakdownSlice(
                key: $market,
                label: Market::tryFrom($market)?->label() ?? $market,
                value: $count,
            );
        }

        return $slices;
    }

    /**
     * Conversions by how long they took, in day order rather than by size — the shape is the point,
     * and a bar list sorted by volume would hide whether people convert on day 1 or day 13.
     *
     * @return list<BreakdownSlice>
     */
    private function conversionDays(DateRange $range): array
    {
        $byDay = $this->store->byDimension(PlatformMetric::ConversionDay, $range);

        uksort($byDay, static fn (string $a, string $b): int => (int) Str::after($a, 'd') <=> (int) Str::after($b, 'd'));

        $slices = [];

        foreach ($byDay as $dimension => $count) {
            $day = (int) Str::after($dimension, 'd');

            $slices[] = new BreakdownSlice(
                key: $dimension,
                label: $day === 0 ? 'Same day' : 'Day '.$day,
                value: $count,
            );
        }

        return $slices;
    }

    /* ------------------------------------------------------------------ Tables */

    /**
     * @param  list<PlatformMargin>  $margins
     * @return list<PlatformReportTable>
     */
    private function tables(DateRange $range, array $margins): array
    {
        return [
            $this->growthTable($range),
            $this->funnelTable($range),
            $this->revenueTable($range),
            $this->movementTable($range),
            $this->retentionTable($range),
            $this->pricingTable($range),
            $this->costTable($range),
            $this->aiCostTable($range),
            $this->leadCostTable($range),
            $this->marginTable(PlatformReport::Margin, $margins, 'Tenant'),
            $this->marginTable(PlatformReport::MarginByPlan, $this->margins->perPlan($range), 'Plan'),
            $this->marginTable(PlatformReport::MarginByCohort, $this->margins->perCohort($range), 'Signup month'),
            $this->outreachTable($range),
        ];
    }

    private function growthTable(DateRange $range): PlatformReportTable
    {
        $previous = PlatformWindow::previous($range);

        $metrics = [
            PlatformMetric::Signups,
            PlatformMetric::TrialStarts,
            PlatformMetric::Activations,
            PlatformMetric::TrialConversions,
        ];

        $rows = array_map(
            fn (PlatformMetric $metric): ReportRow => new ReportRow($metric->value, [
                'measure' => $metric->label(),
                'value' => $this->store->total($metric, $range),
                // An empty cell, not a zero: "all time" has no window before it, and a zero there
                // would read as "nothing happened" rather than "there is nothing to compare".
                'previous' => $previous === null ? null : $this->store->total($metric, $previous),
            ]),
            $metrics,
        );

        return PlatformReportTable::of(
            PlatformReport::Growth,
            [
                new ReportColumn('measure', 'Measure'),
                ReportColumn::count('value', 'This window'),
                ReportColumn::count('previous', 'Window before'),
            ],
            $rows,
            array_map(static fn (PlatformMetric $m): string => $m->label().': '.$m->definition(), $metrics),
        );
    }

    private function funnelTable(DateRange $range): PlatformReportTable
    {
        $steps = PlatformMetric::funnel();
        $cohort = $this->store->total(PlatformMetric::FunnelSignedUp, $range);

        $rows = array_map(
            fn (PlatformMetric $metric): ReportRow => new ReportRow($metric->value, [
                'step' => $metric->label(),
                'tenants' => $this->store->total($metric, $range),
                // `Ratio::of` returns null over a zero denominator, which is what stops an
                // empty cohort rendering as 0% conversion rather than as "no cohort".
                'share' => Ratio::of($this->store->total($metric, $range), $cohort),
            ]),
            $steps,
        );

        return PlatformReportTable::of(
            PlatformReport::Funnel,
            [
                new ReportColumn('step', 'Step'),
                ReportColumn::count('tenants', 'Tenants'),
                ReportColumn::percent('share', 'Share of cohort'),
            ],
            $rows,
            array_map(static fn (PlatformMetric $m): string => $m->label().': '.$m->definition(), $steps),
        );
    }

    private function revenueTable(DateRange $range): PlatformReportTable
    {
        $currency = $this->money->currency();
        $mrr = $this->store->byDimension(PlatformMetric::Mrr, $range);
        $paying = $this->store->byDimension(PlatformMetric::PayingTeams, $range);

        $plans = array_values(array_unique([...array_keys($mrr), ...array_keys($paying)]));
        $rows = [];

        foreach ($plans as $plan) {
            $amount = $mrr[$plan] ?? 0;

            $rows[] = new ReportRow($plan, [
                'plan' => $this->plans->fromKey($plan)->name,
                'mrr' => $amount,
                'arr' => $amount * 12,
                'tenants' => $paying[$plan] ?? 0,
            ]);
        }

        if ($rows !== []) {
            $rows[] = new ReportRow('total', [
                'plan' => 'Total',
                'mrr' => array_sum($mrr),
                'arr' => array_sum($mrr) * 12,
                'tenants' => array_sum($paying),
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::Revenue,
            [
                new ReportColumn('plan', 'Plan'),
                ReportColumn::money('mrr', 'MRR', $currency),
                ReportColumn::money('arr', 'ARR', $currency),
                ReportColumn::count('tenants', 'Paying tenants'),
            ],
            $rows,
            [
                PlatformMetric::Mrr->definition(),
                'ARR is twelve times MRR. Not a forecast.',
                PlatformMetric::PayingTeams->definition(),
            ],
        );
    }

    private function movementTable(DateRange $range): PlatformReportTable
    {
        $currency = $this->money->currency();
        $rows = [];
        $net = 0;

        foreach (PlatformMetric::movement() as $metric) {
            $amount = $this->store->total($metric, $range);
            $net += $amount;

            $rows[] = new ReportRow($metric->value, [
                'movement' => $metric->label(),
                'amount' => $amount,
            ]);
        }

        $rows[] = new ReportRow('net', ['movement' => 'Net change', 'amount' => $net]);

        return PlatformReportTable::of(
            PlatformReport::Movement,
            [
                new ReportColumn('movement', 'Movement'),
                ReportColumn::money('amount', 'Amount', $currency),
            ],
            $rows,
            array_map(
                static fn (PlatformMetric $m): string => $m->label().': '.$m->definition(),
                PlatformMetric::movement(),
            ),
        );
    }

    /**
     * Retention by signup cohort — how many of each month's signups ever paid, and how many still
     * do.
     *
     * "Ever paid" is read from the whole rollup history rather than from the window, because that
     * is what the question means: a cohort's retention does not depend on which window an operator
     * happens to be looking at. The window selects **which cohorts** are shown, exactly as the
     * funnel's does.
     */
    private function retentionTable(DateRange $range): PlatformReportTable
    {
        $months = max(1, (int) config('admin.reporting.retention_months', 12));
        $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($months - 1);

        /** @var array<string, array{signed_up: int, ever: int, still: int}> $cohorts */
        $cohorts = [];

        $everPaid = [];

        // `TeamPaying`, not `TeamMrr`. A plan priced on application and a manually granted plan
        // both contribute **zero** MRR, so a cohort of Enterprise and invoiced customers would
        // read as having never paid us a penny — which is the opposite of what they are.
        foreach ($this->store->dimensionsEverPositive(PlatformMetric::TeamPaying) as $dimension) {
            $teamId = PlatformMetric::teamIdFromDimension($dimension);

            if ($teamId !== null) {
                $everPaid[$teamId] = true;
            }
        }

        $stillPaying = [];
        $latest = $this->store->latestKnownDay(PlatformMetric::TeamPaying, DateRange::allTime('UTC'));

        if ($latest !== null) {
            foreach ($this->store->dimensionsOn(CarbonImmutable::parse($latest, 'UTC'), PlatformMetric::TeamPaying) as $dimension => $paying) {
                $teamId = PlatformMetric::teamIdFromDimension($dimension);

                if ($teamId !== null && $paying > 0) {
                    $stillPaying[$teamId] = true;
                }
            }
        }

        Team::query()
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($teams) use (&$cohorts, $everPaid, $stillPaying, $range): void {
                /** @var Collection<int, Team> $teams */
                foreach ($teams as $team) {
                    $createdAt = $team->created_at;

                    if ($createdAt === null) {
                        continue;
                    }

                    $signedUp = CarbonImmutable::instance($createdAt)->utc();

                    // The window selects which cohorts are shown, the same rule the funnel uses.
                    if ($range->from !== null && $signedUp->lessThan($range->from->utc())) {
                        continue;
                    }

                    if ($range->to !== null && ! $signedUp->lessThan($range->to->utc())) {
                        continue;
                    }

                    $month = $signedUp->format('Y-m');
                    $id = (int) $team->getKey();

                    $cohorts[$month] ??= ['signed_up' => 0, 'ever' => 0, 'still' => 0];
                    $cohorts[$month]['signed_up']++;
                    $cohorts[$month]['ever'] += isset($everPaid[$id]) ? 1 : 0;
                    $cohorts[$month]['still'] += isset($stillPaying[$id]) ? 1 : 0;
                }
            });

        krsort($cohorts);

        $rows = [];

        foreach ($cohorts as $month => $cohort) {
            $rows[] = new ReportRow($month, [
                'cohort' => $month,
                'signed_up' => $cohort['signed_up'],
                'ever' => $cohort['ever'],
                'still' => $cohort['still'],
                'retained' => Ratio::of($cohort['still'], $cohort['ever']),
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::Retention,
            [
                new ReportColumn('cohort', 'Signup month'),
                ReportColumn::count('signed_up', 'Signed up'),
                ReportColumn::count('ever', 'Ever paid'),
                ReportColumn::count('still', 'Still paying'),
                ReportColumn::percent('retained', 'Retained'),
            ],
            $rows,
            [
                'Signed up: tenants created in that calendar month, UTC.',
                'Ever paid: of those, how many have had a plan in force and paid for on any rolled-up day — read from the whole history, not the window, and counted whether or not the plan has a list price, so an Enterprise or invoiced customer counts.',
                'Still paying: of those, how many were paying on the last day rolled up.',
                'Retained: still paying as a share of ever paid — empty for a cohort nobody ever paid from, because there is nothing to retain.',
            ],
        );
    }

    private function pricingTable(DateRange $range): PlatformReportTable
    {
        $bands = $this->store->byDimension(PlatformMetric::AllowanceBand, $range);
        $ceiling = $this->store->byDimension(PlatformMetric::AllowanceCeiling, $range);

        // Band order, not volume order: the distribution's shape is the signal, and sorting by
        // size would destroy exactly the thing this report is for.
        $order = ['0-25' => 1, '25-50' => 2, '50-75' => 3, '75-100' => 4, '100+' => 5];

        uksort($bands, static function (string $a, string $b) use ($order): int {
            [$metricA, $bandA] = array_pad(explode(':', $a, 2), 2, '');
            [$metricB, $bandB] = array_pad(explode(':', $b, 2), 2, '');

            return [$metricA, $order[$bandA] ?? 9] <=> [$metricB, $order[$bandB] ?? 9];
        });

        $rows = [];

        foreach ($bands as $dimension => $count) {
            [$metric, $band] = array_pad(explode(':', $dimension, 2), 2, '');

            $rows[] = new ReportRow($dimension, [
                'metric' => BillingMetric::tryFrom($metric)?->label() ?? $metric,
                'band' => $band.'%',
                'tenants' => $count,
                'at_ceiling' => $band === '100+' ? ($ceiling[$metric] ?? 0) : null,
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::Pricing,
            [
                new ReportColumn('metric', 'Metric'),
                new ReportColumn('band', 'Allowance consumed'),
                ReportColumn::count('tenants', 'Tenants'),
                ReportColumn::count('at_ceiling', 'At the ceiling'),
            ],
            $rows,
            [
                PlatformMetric::AllowanceBand->definition(),
                PlatformMetric::AllowanceCeiling->definition(),
                'A tenant with an unlimited allowance appears in neither figure: `usage_meters` stores a zero allowance for unlimited, and a tenant that cannot hit a ceiling must not be counted as permanently over it.',
            ],
        );
    }

    private function costTable(DateRange $range): PlatformReportTable
    {
        $calls = $this->store->byDimension(PlatformMetric::ProviderCalls, $range);
        $failures = $this->store->byDimension(PlatformMetric::ProviderFailures, $range);
        $cost = $this->store->byDimension(PlatformMetric::ProviderCostMicros, $range);

        $rows = [];

        foreach (PlatformProvider::cases() as $provider) {
            if (! $provider->isMetered()) {
                continue;
            }

            $dimension = $provider->metricDimension();
            $made = $calls[$dimension] ?? 0;
            $failed = $failures[$dimension] ?? 0;

            $rows[] = new ReportRow($dimension, [
                'provider' => $provider->label(),
                'calls' => $made,
                'failures' => $failed,
                'failure_rate' => Ratio::of($failed, $made),
                'cost' => $this->convertMicros($dimension === PlatformProvider::Claude->metricDimension(), $cost[$dimension] ?? 0),
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::Cost,
            [
                new ReportColumn('provider', 'Provider'),
                ReportColumn::count('calls', 'Calls'),
                ReportColumn::count('failures', 'Failures'),
                ReportColumn::percent('failure_rate', 'Failure rate'),
                ReportColumn::money('cost', 'Cost', $this->money->currency()),
            ],
            $rows,
            [
                PlatformMetric::ProviderCalls->definition(),
                PlatformMetric::ProviderFailures->definition(),
                'Failure rate is empty, not zero, for a provider nobody called in this window.',
                'Cost is converted from the provider\'s currency at the configured rate shown on this screen.',
            ],
        );
    }

    private function aiCostTable(DateRange $range): PlatformReportTable
    {
        $byModel = $this->store->byDimension(PlatformMetric::AiCostMicros, $range);
        $byWork = $this->store->byDimension(PlatformMetric::AiCostMicrosByWork, $range);
        $requests = $this->store->byDimension(PlatformMetric::AiRequests, $range);
        $latency = $this->store->total(PlatformMetric::AiLatencyMsTotal, $range);
        $totalCalls = array_sum($requests);

        $rows = [];

        foreach ($byModel as $model => $micros) {
            $rows[] = new ReportRow('model:'.$model, [
                'dimension' => 'Model',
                'name' => $model,
                'cost' => $this->convertMicros(true, $micros),
            ]);
        }

        foreach ($byWork as $prompt => $micros) {
            $rows[] = new ReportRow('work:'.$prompt, [
                'dimension' => 'Work',
                'name' => $prompt,
                'cost' => $this->convertMicros(true, $micros),
            ]);
        }

        $rows[] = new ReportRow('calls', [
            'dimension' => 'Calls',
            'name' => $totalCalls.' calls, '.($requests[AiRequestStatus::Failed->value] ?? 0).' failed'
                .($totalCalls > 0 ? ', mean '.intdiv($latency, $totalCalls).'ms' : ''),
            'cost' => null,
        ]);

        return PlatformReportTable::of(
            PlatformReport::AiCost,
            [
                new ReportColumn('dimension', 'Breakdown'),
                new ReportColumn('name', 'Name'),
                ReportColumn::money('cost', 'Cost', $this->money->currency()),
            ],
            $rows,
            [
                PlatformMetric::AiCostMicros->definition(),
                PlatformMetric::AiCostMicrosByWork->definition(),
                PlatformMetric::AiRequests->definition(),
            ],
        );
    }

    private function leadCostTable(DateRange $range): PlatformReportTable
    {
        $leads = $this->store->byDimension(PlatformMetric::LeadsCreated, $range);
        $cost = $this->store->byDimension(PlatformMetric::ProviderCostMicros, $range);

        $rows = [];

        foreach ($leads as $source => $count) {
            $micros = $cost[$source] ?? 0;
            $converted = $this->convertMicros(false, $micros);

            $rows[] = new ReportRow($source, [
                'source' => LeadSource::tryFrom($source)?->label() ?? $source,
                'prospects' => $count,
                'cost' => $converted,
                // Null over a zero denominator. A source that found nothing has no cost per
                // prospect, and a zero there would read as "free".
                'per_prospect' => $count === 0 ? null : intdiv($converted, $count),
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::LeadCost,
            [
                new ReportColumn('source', 'Source'),
                ReportColumn::count('prospects', 'Prospects'),
                ReportColumn::money('cost', 'Provider cost', $this->money->currency()),
                ReportColumn::money('per_prospect', 'Cost per prospect', $this->money->currency()),
            ],
            $rows,
            [
                PlatformMetric::LeadsCreated->definition(),
                'Provider cost is the cost of calls to that provider in the window, which is not the same as the cost of the prospects it found: a run that cost money and returned nothing is counted in the cost and not in the volume. That is the honest shape of the figure — it is what a provider cost us, divided by what it gave us.',
                'Sources that are not providers — an import, a company somebody typed in, a converted signal — have no provider cost and show an empty cell rather than a zero.',
            ],
        );
    }

    /**
     * @param  list<PlatformMargin>  $margins
     */
    private function marginTable(PlatformReport $report, array $margins, string $keyLabel): PlatformReportTable
    {
        $currency = $this->money->currency();

        $rows = array_map(
            static fn (PlatformMargin $row): ReportRow => new ReportRow($row->key, [
                'subject' => $row->label.($row->isPartial() ? ' *' : ''),
                'plan' => $row->plan,
                'revenue' => $row->revenue_minor,
                'cost' => $row->cost_minor,
                'margin' => $row->margin_minor,
                'ratio' => $row->margin_ratio,
            ]),
            $margins,
        );

        return PlatformReportTable::of(
            $report,
            [
                new ReportColumn('subject', $keyLabel),
                new ReportColumn('plan', 'Plan'),
                ReportColumn::money('revenue', 'Revenue', $currency),
                ReportColumn::money('cost', 'Provider cost', $currency),
                ReportColumn::money('margin', 'Gross margin', $currency),
                ReportColumn::percent('ratio', 'Margin'),
            ],
            $rows,
            [
                'Revenue is the tenant\'s daily MRR summed over the window and divided by a nominal 30-day month, so a plan change mid-window is accounted for day by day.',
                PlatformMetric::TeamCostMicros->definition(),
                'Margin is revenue less cost. A row marked * has cost in a currency with no configured rate, which makes its margin a ceiling rather than a figure.',
                'Margin % is empty, not zero, where there is no revenue to divide by — a free-plan tenant costs us money and earns none, and 0% would read as break-even.',
            ],
            'Nothing rolled up for this window yet.',
        );
    }

    private function outreachTable(DateRange $range): PlatformReportTable
    {
        $sent = $this->store->byDimension(PlatformMetric::OutreachSent, $range);
        $attempts = $this->store->byDimension(PlatformMetric::OutreachAttempts, $range);
        $outcomes = $this->store->byDimension(PlatformMetric::OutreachOutcomes, $range);

        $outcomeFor = static function (string $channel, ContactOutcome $outcome) use ($outcomes): int {
            return $outcomes[$channel.':'.$outcome->value] ?? 0;
        };

        $rows = [];

        foreach (OutreachChannel::cases() as $channel) {
            $key = $channel->value;
            $logged = $attempts[$key] ?? 0;

            $bounced = $outcomeFor($key, ContactOutcome::Bounced);
            $optedOut = $outcomeFor($key, ContactOutcome::OptedOut);
            $replied = $outcomeFor($key, ContactOutcome::Replied)
                + $outcomeFor($key, ContactOutcome::Interested)
                + $outcomeFor($key, ContactOutcome::NotInterested)
                + $outcomeFor($key, ContactOutcome::MeetingBooked);

            $rows[] = new ReportRow($key, [
                'channel' => $channel->value,
                'sent' => $sent[$key] ?? 0,
                'attempts' => $logged,
                'replies' => $replied,
                'bounces' => $bounced,
                'opt_outs' => $optedOut,
                'bounce_rate' => Ratio::of($bounced, $logged),
                'opt_out_rate' => Ratio::of($optedOut, $logged),
            ]);
        }

        return PlatformReportTable::of(
            PlatformReport::Outreach,
            [
                ReportColumn::translated('channel', 'Channel'),
                ReportColumn::count('sent', 'Sent by Alima'),
                ReportColumn::count('attempts', 'Approaches logged'),
                ReportColumn::count('replies', 'Replies'),
                ReportColumn::count('bounces', 'Bounces'),
                ReportColumn::count('opt_outs', 'Opt-outs'),
                ReportColumn::percent('bounce_rate', 'Bounce rate'),
                ReportColumn::percent('opt_out_rate', 'Opt-out rate'),
            ],
            $rows,
            [
                PlatformMetric::OutreachSent->definition(),
                PlatformMetric::OutreachAttempts->definition(),
                'Replies count the four outcomes that mean somebody answered — replied, interested, not interested, meeting booked.',
                'Both rates are over approaches logged, and are empty rather than zero for a channel nobody used.',
            ],
        );
    }

    /**
     * Convert cost micro-units to the reporting currency's minor units.
     *
     * `$fromAiLedger` picks which currency the micro-units are in, and the two sources genuinely
     * differ: `ai_requests` carries a `currency` column, while `discovery_runs` and `signal_runs`
     * do not and are denominated in `admin.reporting.data_cost_currency`.
     *
     * **A limitation worth stating rather than hiding.** Only `TeamCostMicros` carries the currency
     * in its dimension, so only the margin reports can honour the never-absorb-an-unknown-currency
     * promise row by row. The cost, AI-cost and lead-cost reports aggregate across whatever
     * currencies the ledger held and convert at the configured rate for the source — which is
     * exact while a deployment bills in one currency per ledger (today's case, since
     * `ai-platform.cost.currency` is a single config value) and would understate or overstate if
     * one ever billed in two. Closing it means a currency column on the two run tables and the
     * currency in these dimensions; it is recorded in `docs/modules/admin.md`.
     */
    private function convertMicros(bool $fromAiLedger, int $micros): int
    {
        $currency = $fromAiLedger
            ? (string) config('ai-platform.cost.currency', 'usd')
            : (string) config('admin.reporting.data_cost_currency', 'usd');

        return $this->money->convert([$currency => $micros])->minor_units;
    }
}
