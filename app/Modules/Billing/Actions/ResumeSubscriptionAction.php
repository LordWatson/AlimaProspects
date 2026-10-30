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
 * Undo a cancellation that hasn't taken effect yet.
 *
 * Only possible inside the grace period — once the subscription has actually ended there is
 * nothing to resume and the team subscribes again from the pricing screen. Stripe enforces that;
 * the gateway turns its refusal into a `BillingException` rather than a stack trace.
 */
final class ResumeSubscriptionAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly PlanResolver $plans,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when there is nothing to resume, or Stripe is unreachable.
     */
    public function handle(Team $team, User $actor): void
    {
        $this->gateway->resume($team);

        $this->plans->forget($team);
        $team->unsetRelation('subscriptions');

        $this->audit->log('billing.subscription_resumed', $team, [], $actor);
    }
}
