<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\AiPlatform\Services\AiUsageLogger;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Services\EnrichmentCostCounter;

/**
 * What one team costs us against what they pay, and the one thing it refuses when that goes wrong
 * (PL4).
 *
 * **The allowance and the margin are different questions, and this is the second one.** A team
 * inside its plan's reveal allowance can still be unprofitable: the allowances are sized for a
 * normal month, and a team whose list is all mobile numbers in a country where our vendor charges
 * triple can stay inside them while costing more than they pay. `UsageAllowance` cannot see that,
 * because it counts units and the problem is money.
 *
 * ## What it stops, and what it deliberately does not
 *
 * **Phone reveals, and nothing else.** The most expensive unit we buy and the one a single
 * enthusiastic week runs up; stopping it moves the ratio, and stopping everything would turn a
 * pricing problem into an outage for a customer who is paying us. Discovery, scoring, email
 * reveals, verification, export and the rest are untouched — and the refusal is recorded on the
 * enrichment run, so the screen can explain it rather than the feature simply going quiet.
 *
 * **A team with no price is exempt**, which is the free plan, a trial and Enterprise-on-application
 * alike. The ratio has no denominator there, and reading "any cost at all" as a breach would refuse
 * the first phone reveal of every trial — the opposite of what a trial is for.
 *
 * ## Where the numbers come from
 *
 * Both halves are **reads over ledgers somebody else owns** (ADR 0012): `enrichment_attempts`
 * through {@see EnrichmentCostCounter}, `ai_requests` through {@see AiUsageLogger}. There is no
 * cost column on `usage_ledger` for exactly this reason — a copy of a cost is the one that is
 * wrong. Discovery and signal spend are left out: they are the cost of finding a company rather
 * than of enriching one, they are bounded by their own quotas, and including them would mean a
 * team's own discovery allowance could switch their phone reveals off.
 *
 * `shortcut: vendor cost is summed without converting currency — the vendors bill in USD and the
 * plans are priced in GBP, so the ratio reads low by roughly the spread and the limit is slightly
 * permissive. Upgrade when a second billing currency ships, or when the margin screen's conversion
 * (Admin's `PlatformMoney`) moves somewhere both can share.`
 */
final class MarginGuard
{
    public function __construct(
        private readonly PlanResolver $plans,
        private readonly EnrichmentCostCounter $enrichmentCost,
        private readonly AiUsageLogger $aiUsage,
    ) {}

    /**
     * Whether this metric is refused for this team right now.
     *
     * Asked by {@see PlanCreditReserver} before it holds anything, so a refusal is an answer the
     * run records rather than a reservation that has to be unwound.
     */
    public function blocks(Team $team, BillingMetric $metric, ?BillingPeriod $period = null): bool
    {
        if ($metric !== BillingMetric::PhoneReveal || ! $this->enabled()) {
            return false;
        }

        $ratio = $this->ratio($team, $period);

        return $ratio !== null && $ratio > $this->limit();
    }

    /**
     * Vendor cost ÷ what the team pays for the period, or null when there is no price to divide by.
     */
    public function ratio(Team $team, ?BillingPeriod $period = null): ?float
    {
        $period ??= BillingPeriod::containing();
        $revenueMicros = $this->revenueMicros($team);

        if ($revenueMicros <= 0) {
            return null;
        }

        return $this->vendorCostMicros($team, $period) / $revenueMicros;
    }

    /**
     * Vendor spend in micro-units over the period: contact data plus AI.
     */
    public function vendorCostMicros(Team $team, ?BillingPeriod $period = null): int
    {
        $period ??= BillingPeriod::containing();

        return $this->enrichmentCost->vendorCostMicrosBetween($team, $period->start, $period->end)
            + $this->aiUsage->usedMicrosBetween($team, $period->start, $period->end);
    }

    /**
     * The configured ceiling on that ratio.
     */
    public function limit(): float
    {
        return max(0.0, (float) config('billing.margin_guard.max_vendor_cost_ratio', 0.35));
    }

    public function enabled(): bool
    {
        return (bool) config('billing.margin_guard.enabled', true);
    }

    /**
     * What this team pays us for one period, in micro-units.
     *
     * The plan's list price, not an invoice: an invoice is a round trip to Stripe and this is asked
     * in the path of a reveal. A discount or a bespoke Enterprise price therefore reads as the list
     * price, which errs *strict* for a discounted team — stated rather than silently corrected,
     * because the alternative is a payment provider in the path of a guard.
     */
    private function revenueMicros(Team $team): int
    {
        $amount = $this->plans->planFor($team)->amount;

        // Null is price-on-application and 0 is the free plan: no denominator either way.
        return $amount === null ? 0 : $amount * 10_000;
    }
}
