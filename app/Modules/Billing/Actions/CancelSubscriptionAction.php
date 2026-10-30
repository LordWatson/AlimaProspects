<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;

/**
 * Cancel a team's subscription at the end of the period it has already paid for.
 *
 * Not immediately, and not with a refund: the team keeps working until the date they paid up to,
 * which is both what they bought and what makes the decision reversible — see
 * {@see ResumeSubscriptionAction}. Until that date the plan's entitlements still apply, which is
 * why `SubscriptionState::GracePeriod` is an entitled state.
 */
final class CancelSubscriptionAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly PlanResolver $plans,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when the team has no subscription, or Stripe is unreachable.
     */
    public function handle(Team $team, User $actor): void
    {
        $plan = $this->plans->planFor($team);

        $this->gateway->cancel($team);

        $this->plans->forget($team);
        $team->unsetRelation('subscriptions');

        $this->audit->log('billing.subscription_cancelled', $team, [
            'plan' => $plan->key->value,
        ], $actor);
    }
}
