<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\TopUpOption;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Listeners\GrantTopUpFromStripe;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;

/**
 * Start a Stripe Checkout session for a top-up, and return the URL to send the buyer to (PL4).
 *
 * {@see StartCheckoutAction}'s shape, and the same rule: **nothing is granted here.** The allowance
 * arrives on `checkout.session.completed` through {@see GrantTopUpFromStripe},
 * because a grant written at this point is an allowance given to somebody who closed the tab at the
 * card form. The audit entry records the attempt, which is a different and genuinely useful fact.
 *
 * The **metadata is the whole mechanism**. Stripe hands it straight back on the webhook, and it is
 * how the grant knows which team bought which metric and how much of it. The alternative — a local
 * `pending_topups` row written before payment — is a row that says a team bought something they may
 * never have paid for, and a second place for the truth to live.
 *
 * The metadata deliberately carries **no period**. The grant lands in whatever period it is
 * delivered in, so a purchase made at 23:59 on the last of the month and settled at 00:01 gives the
 * team allowance in the month they are now spending in — rather than in a month that is over, which
 * is allowance they paid for and cannot use.
 */
final class StartTopUpCheckoutAction
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BillingException when Stripe is switched off or unreachable.
     */
    public function handle(
        Team $team,
        TopUpOption $option,
        User $actor,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $url = $this->gateway->topUpUrl(
            $team,
            $option->stripePrice,
            $option->quantity,
            metadata: [
                // Namespaced, because the metadata bag is shared with anything else we ever put on
                // a session, and an un-prefixed `metric` is the key somebody else overwrites.
                'alima_topup_metric' => $option->metric->value,
                'alima_topup_quantity' => (string) $option->quantity,
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        $this->audit->log('billing.topup_started', $team, [
            'metric' => $option->metric->value,
            'quantity' => $option->quantity,
        ], $actor);

        return $url;
    }
}
