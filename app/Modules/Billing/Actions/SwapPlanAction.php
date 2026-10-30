<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Auth\Services\SeatCounter;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;

/**
 * Move a subscribed team onto a different plan.
 *
 * **A downgrade that would not fit the team is refused, not applied.** If three people are in a
 * team moving to a one-seat plan, something has to give, and the only alternative to refusing is
 * picking which two colleagues lose their account — a decision no billing screen should make on an
 * Owner's behalf. The usage allowance is not treated the same way: a team that has already used
 * 600 companies this month and drops to a 500 plan keeps what it has found and simply stops, which
 * costs nobody their access.
 *
 * Stripe is called outside any transaction (CLAUDE.md §2); the local work afterwards is re-reading
 * state Stripe has just changed, not writing to it — Cashier's own `swap()` has already updated the
 * subscription row by the time this returns.
 */
final class SwapPlanAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly PlanResolver $plans,
        private readonly SeatCounter $seats,
        private readonly UsageLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when the team has no subscription, the plan isn't purchasable, or
     *                          the team has more members than the target plan has seats.
     */
    public function handle(Team $team, PlanData $plan, User $actor): void
    {
        $from = $this->plans->planFor($team);

        $members = $this->seats->members($team);

        if ($plan->seats !== null && $members > $plan->seats) {
            throw BillingException::tooManySeats($members, $plan->seats);
        }

        $this->gateway->swapPlan($team, $plan);

        // Two caches, not one. `forget()` drops this module's memo; `unsetRelation()` drops the
        // `subscriptions` collection Cashier loaded onto this very `$team` instance, which the swap
        // has just made wrong. Without the second, the re-snapshot below would record the allowance
        // of the plan the team has just left.
        $this->plans->forget($team);
        $team->unsetRelation('subscriptions');

        // Re-snapshot the meters against the new allowance, so the billing screen the Owner lands
        // back on shows the plan they are now on rather than the one they left.
        $this->ledger->sync($team);

        $this->audit->log('billing.plan_swapped', $team, [
            'from' => $from->key->value,
            'to' => $plan->key->value,
        ], $actor);
    }
}
