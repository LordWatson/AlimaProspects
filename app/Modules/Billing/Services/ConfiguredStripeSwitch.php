<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\StripeSwitch;

/**
 * Billing's own answer: **yes, unless somebody says otherwise** (Phase 19b).
 *
 * The behaviour the module has always had, now behind a seam so Admin can put an operator's kill
 * switch in front of it.
 *
 * It deliberately does **not** read `cashier.secret`. "Stripe is configured" and "Stripe is
 * switched on" are different questions, and answering the second with the first was a mistake worth
 * recording: the test suite clears `STRIPE_SECRET` on purpose — so that nothing can reach real
 * Stripe while `FakeBillingGateway` stands in — and a switch that read the key reported Stripe as
 * off for the entire suite, no-oping every overage report and every webhook sync. A deployment with
 * no keys is a misconfiguration that Stripe's own error says more clearly than we can, and the one
 * configuration gap that genuinely matters (a missing webhook secret in production) already has its
 * own boot-time assertion in `BillingServiceProvider`.
 */
final class ConfiguredStripeSwitch implements StripeSwitch
{
    public function isEnabled(): bool
    {
        return true;
    }
}
