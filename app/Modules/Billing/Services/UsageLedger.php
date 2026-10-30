<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\AiPlatform\Services\AiUsageLogger;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Services\LeadUsageCounter;
use Illuminate\Support\Facades\DB;

/**
 * What a team has used this period, and the durable record of it.
 *
 * Two jobs, deliberately separated:
 *
 * - **Reading** ({@see self::usage()}) goes straight to the authoritative ledgers — `ai_requests`
 *   for spend, `discovery_runs` for leads — through each owning module's own counter. The guards
 *   call this on every AI request and every discovery run, so it has to be a number that cannot be
 *   stale: an increment the app forgot to make is an allowance a team silently overspends, and an
 *   increment made twice is one they are wrongly refused.
 * - **Writing** ({@see self::sync()}) snapshots those numbers into `usage_meters`. That row is the
 *   billing artefact: it survives retention pruning the ledgers behind it, and its `reported`
 *   column is what stops the overage reporter billing the same units twice.
 *
 * Nothing increments a counter at the point of use, which is why there is no transaction to join
 * and no job to be idempotent about. The cost is two aggregate queries per guard check — the same
 * two the AI credit guard has made since Phase 5.
 */
final class UsageLedger
{
    public function __construct(
        private readonly PlanResolver $plans,
        private readonly AiUsageLogger $aiUsage,
        private readonly LeadUsageCounter $leadUsage,
    ) {}

    /**
     * The live position for one metric: used, included, ceiling, and whether overage applies.
     */
    public function usage(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): MetricUsage
    {
        $period ??= BillingPeriod::containing();
        $plan = $this->plans->planFor($team);

        return new MetricUsage(
            metric: $metric,
            used: $this->used($team, $metric, $period),
            included: $plan->allowanceFor($metric),
            ceiling: $plan->ceilingFor($metric),
            overageAllowed: $plan->overage,
        );
    }

    /**
     * Both metrics at once, keyed by metric value — what the billing screen renders from.
     *
     * @return array<string, MetricUsage>
     */
    public function summary(Team $team, ?BillingPeriod $period = null): array
    {
        $period ??= BillingPeriod::containing();
        $summary = [];

        foreach (BillingMetric::cases() as $metric) {
            $summary[$metric->value] = $this->usage($team, $metric, $period);
        }

        return $summary;
    }

    /**
     * Raw consumption for one metric over a period, in the metric's own unit.
     */
    public function used(Team $team, BillingMetric $metric, BillingPeriod $period): int
    {
        return match ($metric) {
            BillingMetric::Leads => $this->leadUsage->createdBetween($team, $period->start, $period->end),
            BillingMetric::AiCredits => $metric->fromMicros(
                $this->aiUsage->usedMicrosBetween($team, $period->start, $period->end),
            ),
        };
    }

    /**
     * Write the current position into `usage_meters`, and return the same summary
     * {@see self::summary()} would — so a caller that needs both (the billing screen) gets one pass
     * over the ledgers instead of two.
     *
     * Idempotent by construction: it writes absolute values read from the ledgers rather than adding
     * to what is there, so running it twice in a row — or running it after a worker crashed halfway
     * — leaves exactly the same numbers. `reported` is never touched here; it belongs to
     * {@see OverageReporter}, which is the only thing that knows what Stripe has been told.
     *
     * **A sealed period is never rewritten.** See {@see self::isSealed()}: once a closed month has
     * been snapshotted, re-syncing it would overwrite `used` with whatever the ledgers still hold —
     * which, after Compliance's retention sweep has pruned them, is less than the month actually
     * cost — and overwrite `included` with the allowance of whatever plan the team is on *now*. Both
     * would mis-size an invoice for a month that is already settled, and the second one is how a
     * team that downgraded on the 1st would be billed last month's overage against this month's
     * allowance.
     *
     * @return array<string, MetricUsage>
     */
    public function sync(Team $team, ?BillingPeriod $period = null): array
    {
        $period ??= BillingPeriod::containing();
        $plan = $this->plans->planFor($team);

        return DB::transaction(function () use ($team, $period, $plan): array {
            $summary = [];

            foreach (BillingMetric::cases() as $metric) {
                $meter = $this->meter($team, $metric, $period);

                if (! $this->isSealed($meter)) {
                    $meter->forceFill([
                        'used' => $this->used($team, $metric, $period),
                        // Re-snapshotted every sync, so a team that upgrades mid-month is metered
                        // against the allowance it now has rather than the one it started on.
                        'included' => $plan->allowanceFor($metric) ?? 0,
                        'synced_at' => now(),
                    ])->save();
                }

                $summary[$metric->value] = new MetricUsage(
                    metric: $metric,
                    used: $meter->used,
                    included: $plan->allowanceFor($metric),
                    ceiling: $plan->ceilingFor($metric),
                    overageAllowed: $plan->overage,
                );
            }

            return $summary;
        });
    }

    /**
     * Whether a meter row is final.
     *
     * A row is sealed once it has been synced **at or after** its period ended — not merely once
     * the period is over. That distinction is the month boundary: the last hourly sync of a month
     * runs before midnight, so the final hour would be missing from the snapshot if the first sync
     * after midnight refused to touch it. One write after the period closes captures the whole
     * month and seals it; everything after that leaves it alone.
     */
    private function isSealed(UsageMeter $meter): bool
    {
        return $meter->synced_at !== null && $meter->synced_at >= $meter->period_end;
    }

    /**
     * The meter row for one team, metric and period — created if this is the first time the period
     * has been touched.
     *
     * `firstOrCreate` on the unique key rather than a read-then-write, because the scheduled sync
     * and a member opening the billing screen can land on the same new period in the same second.
     */
    public function meter(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): UsageMeter
    {
        $period ??= BillingPeriod::containing();

        /** @var UsageMeter $meter */
        $meter = UsageMeter::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'team_id' => $team->getKey(),
                'metric' => $metric->value,
                'period_start' => $period->start,
            ],
            [
                'period_end' => $period->end,
                'used' => 0,
                'included' => 0,
                'reported' => 0,
            ],
        );

        return $meter;
    }
}
