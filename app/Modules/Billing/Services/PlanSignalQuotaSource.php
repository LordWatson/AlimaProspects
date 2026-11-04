<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Contracts\SignalQuotaSource;
use App\Modules\Signals\Data\SignalAllowance;
use App\Modules\Signals\Services\StubSignalQuotaSource;

/**
 * What a team's **plan** allows them to spend on Signals (S16 task 1).
 *
 * The fourth seam this module fills in, bound over {@see StubSignalQuotaSource} in
 * {@see BillingServiceProvider}; `SignalSpendGuard` and every caller of it are untouched, and
 * nothing in Signals imports `App\Modules\Billing`.
 *
 * **It decorates rather than replaces, and that is the difference from the other three.**
 * `PlanCreditSource`, `PlanLeadQuotaSource` and `PlanSeatLimit` each answer one number, so swapping
 * the stub out is the whole story. This one answers eight, and a plan definition is allowed to be
 * partial — so each figure a plan **omits** falls through to the module's own floor rather than
 * becoming unlimited ({@see self::figure()}). The same per-key fall-through
 * {@see PlanCapabilityGate} applies to a plan with no `capabilities` key, for the same reason: a
 * missing configuration is not a product decision, and the conservative reading is the safe one
 * when the figure is money.
 *
 * Which makes **three** states per figure rather than two, and they are all three real: a number, a
 * declared `null` meaning *unlimited*, and an absent key meaning *no position taken*. Only the third
 * reaches the floor.
 *
 * **One of the eight is read as a ceiling rather than as a plain number.**
 * `monthly_source_queries` is also `BillingMetric::SignalQueries`, so what the guard is handed is
 * `PlanData::ceilingFor()` — the included allowance × the overage multiplier on a metered plan. A
 * paid plan therefore keeps watching past what it included and is billed for the excess, exactly as
 * it keeps discovering past its lead allowance.
 *
 * It is read straight off the plan rather than through `EnforcesPlanLimits`, which is the obvious
 * place to reach for and the wrong one: that concern's job is to compare a ceiling with **usage**,
 * and getting the usage costs a `SUM` over `signal_runs`. This class is asked for the allowance by
 * every guard method, including ones that only want `maxWatches`, so paying for a sum and
 * discarding it was waste on every call (found in the S16 review). `SignalSpendGuard` owns the
 * comparison and counts the usage itself through `SignalUsageCounter`, which is also what
 * `UsageLedger` reads for the meter — so the refusal and the meter still cannot disagree.
 */
final class PlanSignalQuotaSource implements SignalQuotaSource
{
    public function __construct(
        /** The module's own floors, which this decorates. */
        private readonly SignalQuotaSource $inner,
        private readonly PlanResolver $plans,
    ) {}

    public function allowanceFor(Team $team): SignalAllowance
    {
        // One plan resolution for the whole method. `PlanResolver` memoises per team anyway, but
        // reading it twice here read as though the two answers might differ.
        $plan = $this->plans->planFor($team);
        $declared = $this->declaredFor($plan->key->value);
        $floor = $this->inner->allowanceFor($team);

        return new SignalAllowance(
            maxDefinitions: $this->figure($declared, 'max_definitions', $floor->maxDefinitions),
            maxWatches: $this->figure($declared, 'max_watches', $floor->maxWatches),
            maxWatchlistEntries: $this->figure($declared, 'max_watchlist_entries', $floor->maxWatchlistEntries),
            // **The metered one, and it is the plan's ceiling rather than its included allowance.**
            // A null ceiling is an unlimited plan; the module's floor for this figure is also null,
            // so there is nothing to fall back to and nothing to reconcile.
            monthlySourceQueries: $declared === null
                ? $floor->monthlySourceQueries
                : $plan->ceilingFor(BillingMetric::SignalQueries),
            monthlyPreviewQueries: $this->figure($declared, 'monthly_preview_queries', $floor->monthlyPreviewQueries),
            articleFetchMicros: $this->figure($declared, 'article_fetch_micros', $floor->articleFetchMicros),
            // **Both must say yes.** Each of these is a refusal, and a decorator must not be able
            // to hand back something the layer beneath it denied — so a plan that says nothing
            // leaves the module's own answer standing, and an explicit `false` on either side wins.
            playbooksEnabled: $floor->playbooksEnabled && $this->boolOrTrue($declared, 'playbooks'),
            webhookSourceEnabled: $floor->webhookSourceEnabled && $this->boolOrTrue($declared, 'webhook_source'),
        );
    }

    /**
     * The plan's `signals` block, or null when it declares none.
     *
     * Read from config by plan key rather than from `PlanData`, which carries only the one figure
     * that is a billing metric. Putting the other seven on that DTO would have put a set of limits
     * next to the marketing copy the pricing page renders.
     *
     * @return array<string, mixed>|null
     */
    private function declaredFor(string $planKey): ?array
    {
        /** @var mixed $declared */
        $declared = config('billing.plans.'.$planKey.'.signals');

        return is_array($declared) ? $declared : null;
    }

    /**
     * One figure from the block, falling back to the module's own floor when the plan is silent.
     *
     * **A key set to `null` and a key that is absent are different answers, and telling them apart
     * is the whole of this method.** `null` is a plan saying *unlimited* — which is how
     * `config/billing.php` reads a null `seats` or `leads`, and what the Enterprise plan spells out
     * rather than omitting — so it wins over the floor. An **absent** key is a plan that has not
     * taken a position, so the module's own floor stands. Collapsing the two would have held an
     * explicitly-unlimited Enterprise plan to the module's ten watches, with the config saying
     * otherwise a line above.
     *
     * A negative number is read as zero rather than ignored: "no allowance" is a real answer, and a
     * typo that became "unlimited" would be the wrong way to fail.
     *
     * @param  array<string, mixed>|null  $declared
     */
    private function figure(?array $declared, string $key, ?int $floor): ?int
    {
        if ($declared === null || ! array_key_exists($key, $declared)) {
            return $floor;
        }

        if ($declared[$key] === null) {
            return null;
        }

        return max(0, (int) $declared[$key]);
    }

    /**
     * @param  array<string, mixed>|null  $declared
     */
    private function boolOrTrue(?array $declared, string $key): bool
    {
        if ($declared === null || ! array_key_exists($key, $declared)) {
            return true;
        }

        return (bool) $declared[$key];
    }
}
