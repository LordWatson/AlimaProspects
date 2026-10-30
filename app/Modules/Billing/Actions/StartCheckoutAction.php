<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;

/**
 * Start a Stripe Checkout session for a team subscribing to a plan, and return the URL to send
 * them to.
 *
 * **No transaction, and nothing written but the audit entry.** The subscription row is created by
 * Stripe's webhook once the payment actually goes through — writing one here would mean a team
 * that opened checkout and closed the tab is recorded as subscribed. The audit entry records the
 * attempt, which is a different and genuinely useful fact.
 */
final class StartCheckoutAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when the plan can't be bought from the app, or Stripe is unreachable.
     */
    public function handle(Team $team, PlanData $plan, User $actor, string $successUrl, string $cancelUrl): string
    {
        $url = $this->gateway->checkoutUrl($team, $plan, $successUrl, $cancelUrl);

        $this->audit->log('billing.checkout_started', $team, [
            'plan' => $plan->key->value,
        ], $actor);

        return $url;
    }
}
