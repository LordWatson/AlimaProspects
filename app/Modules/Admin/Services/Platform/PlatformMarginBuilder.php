<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Data\PlatformMargin;
use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * **Gross margin per tenant** — what a team pays against what its AI and data calls cost us
 * (Phase 19c).
 *
 * The figure the business lives on, and the one nothing in the product surfaced before this phase
 * even though both halves have been recorded since Phase 5: `ai_requests.cost_micros` and
 * `discovery_runs.cost_micros` carry the cost, and the plan carries the price. **A tenant can be on
 * Scale and still be unprofitable**, and until somebody puts the two numbers next to each other
 * nobody knows which ones are.
 *
 * Three honesty rules, each of which is a way this report could have lied:
 *
 * 1. **Revenue is prorated from the daily snapshots, not taken as MRR.** MRR is monthly; a window
 *    is however long an operator chose. So revenue over the window is the sum of the tenant's daily
 *    MRR divided by a nominal 30-day month — which also handles a plan change mid-window correctly,
 *    because each day contributes the plan it was actually on. A 31-day window therefore shows a
 *    touch over one month's revenue; the alternative, weighting each day by its own month's length,
 *    buys a third decimal place nobody acts on.
 * 2. **Cost that cannot be converted is shown, not absorbed.** {@see PlatformMoney} returns the
 *    unconvertible micro-units alongside the total and they travel into every row, because the
 *    phase's own risk note is a margin that looks authoritative while a provider's cost is
 *    unrecorded.
 * 3. **Cost with no tenant behind it is its own row.** `ai_requests.team_id` is nullable, so some
 *    spend belongs to the platform rather than to a customer. Spreading it would flatter every
 *    margin equally and be invisible; it is reported as `unattributed` and excluded from every
 *    tenant row.
 */
final class PlatformMarginBuilder
{
    /** The nominal month revenue is prorated against. See rule 1 in the class note. */
    private const int NOMINAL_MONTH_DAYS = 30;

    /**
     * The per-plan bucket for a tenant that earned nothing in the window — free, trialing, or
     * churned part-way through. Their cost is real and has to land somewhere.
     */
    private const string NO_REVENUE = '__no_revenue__';

    public function __construct(
        private readonly PlatformMetricStore $store,
        private readonly PlatformMoney $money,
        private readonly PlanRegistry $plans,
    ) {}

    /**
     * Margin per tenant, worst margin first — which is the order an operator wants, because the
     * question is which customers are costing us money.
     *
     * @return list<PlatformMargin>
     */
    public function perTeam(DateRange $range): array
    {
        $revenue = $this->revenueByTeam($range);
        $cost = $this->costByOwner($range);

        $teamIds = array_values(array_unique([
            ...array_keys($revenue),
            ...array_map(
                static fn (string $owner): int => (int) Str::after($owner, 't'),
                array_values(array_filter(
                    array_keys($cost),
                    static fn (string $owner): bool => PlatformMetric::teamIdFromDimension($owner) !== null,
                )),
            ),
        ]));

        $names = $teamIds === [] ? [] : Team::query()
            ->whereIn('id', $teamIds)
            ->pluck('name', 'id')
            ->all();

        $rows = [];

        foreach ($teamIds as $teamId) {
            $owner = PlatformMetric::teamDimension($teamId);

            $rows[] = $this->row(
                key: $owner,
                label: (string) ($names[$teamId] ?? 'Tenant #'.$teamId),
                plan: $revenue[$teamId]['plan'] ?? null,
                revenueMinor: $revenue[$teamId]['minor'] ?? 0,
                microsByCurrency: $cost[$owner] ?? [],
            );
        }

        if (isset($cost['unattributed'])) {
            // Last, always, and never folded into a tenant. See rule 3.
            $rows[] = $this->row(
                key: 'unattributed',
                label: 'Unattributed platform spend',
                plan: null,
                revenueMinor: 0,
                microsByCurrency: $cost['unattributed'],
            );
        }

        usort($rows, static fn (PlatformMargin $a, PlatformMargin $b): int => $a->margin_minor <=> $b->margin_minor);

        return $rows;
    }

    /**
     * The same figures aggregated by the plan in force — what says whether a plan is priced right.
     *
     * The unattributed row is **excluded** here rather than carried: it belongs to no plan, and
     * adding it to one would make that plan look worse for a reason that has nothing to do with it.
     * It stays on the per-tenant report, where it is visible.
     *
     * @return list<PlatformMargin>
     */
    public function perPlan(DateRange $range): array
    {
        return $this->aggregate(
            $range,
            // A tenant with no revenue in the window has no plan in the rollup to read — free and
            // trial tenants never get a `TeamMrr` row — so they go in a bucket of their own rather
            // than being dropped. Dropping them is what made the Free plan incapable of ever
            // showing a loss, which is the single figure somebody would look at this report for.
            static fn (PlatformMargin $row): string => $row->plan ?? self::NO_REVENUE,
            fn (string $plan): string => $plan === self::NO_REVENUE
                ? 'No revenue in this window'
                : $this->plans->fromKey($plan)->name,
        );
    }

    /**
     * The same figures aggregated by the month the tenant signed up — what says whether the
     * customers we are winning now are better or worse than the ones we won last year.
     *
     * @return list<PlatformMargin>
     */
    public function perCohort(DateRange $range): array
    {
        $cohorts = Team::query()->pluck('created_at', 'id')->all();

        $monthOf = static function (PlatformMargin $row) use ($cohorts): ?string {
            $teamId = PlatformMetric::teamIdFromDimension($row->key);

            if ($teamId === null) {
                return null;
            }

            $createdAt = $cohorts[$teamId] ?? null;

            return $createdAt === null
                ? null
                : CarbonImmutable::parse($createdAt)->utc()->format('Y-m');
        };

        return $this->aggregate($range, $monthOf, static fn (string $month): string => $month);
    }

    /**
     * Fold the per-tenant rows onto a key.
     *
     * **Cost is summed in micro-units and converted once per group**, not summed after conversion.
     * That is not tidiness: the conversion floors to the minor unit, so a thousand tenants each
     * costing a fraction of a penny would each round to zero and the group would report no cost at
     * all — overstating margin, which is the one direction this report must not err in.
     *
     * The unattributed row is dropped by every caller's key function: it belongs to no plan and no
     * signup cohort, and adding it to one would make that group look worse for a reason that has
     * nothing to do with it. It stays on the per-tenant report, where it is visible.
     *
     * @param  callable(PlatformMargin): ?string  $keyOf
     * @param  callable(string): string  $labelOf
     * @return list<PlatformMargin>
     */
    private function aggregate(DateRange $range, callable $keyOf, callable $labelOf): array
    {
        $costByOwner = $this->costByOwner($range);

        /** @var array<string, array{revenue: int, micros: array<string, int>}> $groups */
        $groups = [];

        foreach ($this->perTeam($range) as $row) {
            if ($row->key === 'unattributed') {
                continue;
            }

            $key = $keyOf($row);

            if ($key === null) {
                continue;
            }

            $groups[$key] ??= ['revenue' => 0, 'micros' => []];
            $groups[$key]['revenue'] += $row->revenue_minor;

            foreach ($costByOwner[$row->key] ?? [] as $code => $micros) {
                $groups[$key]['micros'][$code] = ($groups[$key]['micros'][$code] ?? 0) + $micros;
            }
        }

        $rows = [];

        foreach ($groups as $key => $group) {
            $rows[] = $this->row(
                key: $key,
                label: $labelOf($key),
                plan: null,
                revenueMinor: $group['revenue'],
                microsByCurrency: $group['micros'],
            );
        }

        usort($rows, static fn (PlatformMargin $a, PlatformMargin $b): int => $a->margin_minor <=> $b->margin_minor);

        return $rows;
    }

    /**
     * Revenue attributed to the window per tenant, with the plan the tenant spent most of it on.
     *
     * @return array<int, array{minor: int, plan: string}>
     */
    private function revenueByTeam(DateRange $range): array
    {
        /** @var array<int, array{days: array<string, int>}> $accumulated */
        $accumulated = [];

        // `sumByDimension`, not `byDimension`: MRR is a snapshot, and this is the one place a sum
        // of snapshots is correct — see that method's own note.
        foreach ($this->store->sumByDimension(PlatformMetric::TeamMrr, $range) as $dimension => $summedMrr) {
            $owner = Str::contains($dimension, '|') ? (string) Str::before($dimension, '|') : $dimension;
            $plan = Str::contains($dimension, '|') ? (string) Str::after($dimension, '|') : '';
            $teamId = PlatformMetric::teamIdFromDimension($owner);

            if ($teamId === null) {
                continue;
            }

            $accumulated[$teamId]['days'][$plan] = ($accumulated[$teamId]['days'][$plan] ?? 0) + $summedMrr;
        }

        $revenue = [];

        foreach ($accumulated as $teamId => $entry) {
            $total = array_sum($entry['days']);

            // The plan the tenant spent most of the window's revenue on. A tenant that upgraded
            // mid-window is reported under the plan that accounts for most of the money, and the
            // revenue figure itself is exact either way because it is summed per day.
            arsort($entry['days']);
            $plan = (string) array_key_first($entry['days']);

            $revenue[$teamId] = [
                'minor' => intdiv($total, self::NOMINAL_MONTH_DAYS),
                'plan' => $plan,
            ];
        }

        return $revenue;
    }

    /**
     * Provider cost over the window per owner, split by the currency it was billed in.
     *
     * @return array<string, array<string, int>> owner => (ISO code => cost micro-units)
     */
    private function costByOwner(DateRange $range): array
    {
        $cost = [];

        foreach ($this->store->byDimension(PlatformMetric::TeamCostMicros, $range) as $dimension => $micros) {
            [$owner, $currency] = PlatformMoney::splitCostDimension($dimension);

            // A dimension with no currency half is a historical row. Keyed under the empty string,
            // which has no configured rate, so `PlatformMoney` reports it as unconverted rather
            // than guessing — see `splitCostDimension`.
            $code = $currency ?? '';
            $cost[$owner][$code] = ($cost[$owner][$code] ?? 0) + $micros;
        }

        return $cost;
    }

    /**
     * @param  array<string, int>  $microsByCurrency
     */
    private function row(
        string $key,
        string $label,
        ?string $plan,
        int $revenueMinor,
        array $microsByCurrency,
    ): PlatformMargin {
        $converted = $this->money->convert($microsByCurrency);
        $margin = $revenueMinor - $converted->minor_units;

        return new PlatformMargin(
            key: $key,
            label: $label,
            plan: $plan === '' ? null : $plan,
            revenue_minor: $revenueMinor,
            cost_minor: $converted->minor_units,
            unconverted_micros: $converted->unconverted,
            margin_minor: $margin,
            margin_ratio: $this->ratio($margin, $revenueMinor),
        );
    }

    /**
     * Margin as a fraction of revenue — **null when there is no revenue**.
     *
     * A free-plan tenant costs us money and earns none; a ratio of 0.0 would render as "0%" and
     * read as break-even. Reporting's whole module is built on the rule that a zero must only ever
     * mean zero, and this is the platform's version of it.
     */
    private function ratio(int $margin, int $revenue): ?float
    {
        return $revenue === 0 ? null : $margin / $revenue;
    }
}
