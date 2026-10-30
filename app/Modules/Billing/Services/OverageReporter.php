<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes metered overage to Stripe, exactly once per unit.
 *
 * Stripe **sums** the usage events it is sent over a billing period, so this cannot send a running
 * total — it sends increments, and the whole design is about making sure an increment is never sent
 * twice. The sequence per metric is:
 *
 *  1. Sync the meter from the ledgers, so `used` is current (a sealed, closed period is read as it
 *     stands — see {@see UsageLedger::sync()}).
 *  2. **Claim** the unreported units with a conditional `UPDATE` that only succeeds if they are
 *     still unclaimed, before Stripe is told anything.
 *  3. Call Stripe with a **deterministic** event identifier derived from the claim.
 *  4. Release the claim only when Stripe has definitely refused.
 *
 * Three things make "exactly once" true, and all three are needed:
 *
 * - **The claim is conditional, not an increment.** Two reporters racing — the 04:10 schedule and
 *   somebody running the command by hand — would both read `reported = 0`, both add the same units
 *   and both send them. `WHERE used - included - reported >= ?` means the second one's update
 *   matches no row and it sends nothing.
 * - **The identifier is derived from `reported`**, not random. Stripe dedupes meter events by
 *   identifier, so a retry of the same claim is discarded at their end rather than summed.
 * - **An ambiguous failure keeps the claim.** A timeout or a 5xx may mean Stripe accepted the event
 *   and we never heard; releasing on that would re-send it. Only a definite refusal — a 4xx, which
 *   means it was not recorded — releases. So the failure modes are: a definite refusal costs
 *   nothing, and an ambiguous one risks *under*-billing by one claim, loudly, in the `billing` log.
 *   That is the direction to err: the other one takes money from somebody who did not agree to it.
 *
 * Units are billed in the metric's `overageUnit()` — singly for leads, per thousand for credits —
 * and the remainder below a whole unit is deliberately **not** claimed, so it rolls into the next
 * run rather than being rounded away or double-counted.
 */
final class OverageReporter
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly UsageLedger $ledger,
        private readonly PlanResolver $plans,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * Report everything outstanding for one team in one period. Returns the number of **billed
     * units** sent, across both metrics.
     */
    public function report(Team $team, ?BillingPeriod $period = null): int
    {
        $period ??= BillingPeriod::containing();
        $isCurrent = $period->contains(now());

        // The plan gate applies to the **current** period only. Whether overage was permitted in a
        // month that has closed was decided by the plan the team was on then, and that decision is
        // already in the stored row's `included` — a team that has since downgraded to a plan with
        // no overage must still be billed for the month it ran over.
        if ($isCurrent && ! $this->plans->planFor($team)->overage) {
            return 0;
        }

        $this->teamContext->run($team, fn () => $this->ledger->sync($team, $period));

        $sent = 0;

        foreach (BillingMetric::cases() as $metric) {
            $sent += $this->reportMetric($team, $metric, $period);
        }

        return $sent;
    }

    private function reportMetric(Team $team, BillingMetric $metric, BillingPeriod $period): int
    {
        $price = config('billing.overage.'.$metric->value.'.stripe_price');

        if (! is_string($price) || $price === '') {
            return 0;
        }

        $meter = $this->teamContext->run($team, fn (): UsageMeter => $this->ledger->meter($team, $metric, $period));

        $unit = $metric->overageUnit();
        $units = intdiv($meter->unreportedOverage(), $unit);

        if ($units <= 0) {
            return 0;
        }

        $claimed = $units * $unit;
        $reportedBefore = $meter->reported;

        if (! $this->claim($meter, $claimed)) {
            // Another reporter got there first. Not an error, and not worth retrying: whatever it
            // claimed, it is now responsible for sending.
            return 0;
        }

        try {
            $this->gateway->reportOverage(
                $team,
                $price,
                $units,
                idempotencyKey: $this->idempotencyKey($team, $metric, $period, $reportedBefore),
            );
        } catch (Throwable $e) {
            $this->handleFailure($team, $metric, $meter, $claimed, $units, $e);

            throw $e;
        }

        Log::channel('billing')->info('Overage reported.', [
            'team_id' => $team->getKey(),
            'metric' => $metric->value,
            'units' => $units,
            'period_start' => $period->start->toDateString(),
        ]);

        return $units;
    }

    /**
     * Claim `$amount` units of overage, returning false when they are no longer there to claim.
     *
     * A conditional `UPDATE` rather than `increment()`: the amount was computed from a row read a
     * moment ago, so an unconditional increment would let two concurrent reporters each add the same
     * units. The `WHERE` re-checks the arithmetic at the database, which is the only place it can be
     * checked without a lock, and the affected-row count is the answer.
     */
    private function claim(UsageMeter $meter, int $amount): bool
    {
        $claimed = DB::transaction(static fn (): int => UsageMeter::query()
            ->withoutGlobalScopes()
            ->whereKey($meter->getKey())
            ->whereRaw('used - included - reported >= ?', [$amount])
            ->increment('reported', $amount));

        if ($claimed > 0) {
            $meter->reported += $amount;
        }

        return $claimed > 0;
    }

    /**
     * Hand the units back, so the next run claims them again.
     *
     * Guarded by `reported >= $amount` rather than clamped with `GREATEST`/`MAX`, which spell
     * differently in MySQL and SQLite — and because a release that would take `reported` negative
     * means something other than this claim has already moved it, which is a situation to leave
     * alone rather than paper over (`sqlite-tests-mysql-prod`: a green suite says nothing about the
     * SQL MySQL will run).
     */
    private function release(UsageMeter $meter, int $amount): void
    {
        DB::transaction(static function () use ($meter, $amount): void {
            UsageMeter::query()
                ->withoutGlobalScopes()
                ->whereKey($meter->getKey())
                ->where('reported', '>=', $amount)
                ->decrement('reported', $amount);
        });

        $meter->reported = max(0, $meter->reported - $amount);
    }

    /**
     * Decide what a failed report means for the claim.
     *
     * A {@see BillingException} is what the gateway raises for anything Stripe said or failed to
     * say, so it cannot be told apart here — the gateway marks the ones it knows were **refused**
     * (`billing.overage_refused`), and only those release. Everything else keeps the claim and says
     * so in the log, because re-sending units Stripe may already hold is the one outcome worth
     * avoiding.
     */
    private function handleFailure(
        Team $team,
        BillingMetric $metric,
        UsageMeter $meter,
        int $claimed,
        int $units,
        Throwable $e,
    ): void {
        $refused = $e instanceof BillingException && $e->errorCode() === 'billing.overage_refused';

        if ($refused) {
            $this->release($meter, $claimed);
        }

        Log::channel('billing')->error(
            $refused
                ? 'Overage report refused; claim released and the units will be retried.'
                : 'Overage report failed with an unknown outcome; claim KEPT to avoid double billing. Reconcile against Stripe.',
            [
                'team_id' => $team->getKey(),
                'metric' => $metric->value,
                'units' => $units,
                'claim_released' => $refused,
                'message' => $e->getMessage(),
            ],
        );
    }

    /**
     * A key that is the same for every attempt at the same claim, and different for the next one.
     *
     * `reported` before the claim is what makes that true: it only moves when a claim succeeds, so
     * two attempts at one claim share a key and the following claim cannot collide with it.
     */
    private function idempotencyKey(
        Team $team,
        BillingMetric $metric,
        BillingPeriod $period,
        int $reportedBefore,
    ): string {
        return sprintf(
            'alima-overage-%d-%s-%s-%d',
            $team->getKey(),
            $metric->value,
            $period->start->format('Y-m'),
            $reportedBefore,
        );
    }
}
