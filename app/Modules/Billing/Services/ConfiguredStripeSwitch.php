<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\StripeSwitch;

/**
 * Billing's own answer: **yes, unless the deployment says otherwise** (Phase 19b; the config lever
 * added later).
 *
 * `billing.stripe.enabled` is the deployment's answer — one line in `.env`, versioned with the
 * release, surviving a fresh database. Admin's `SettingsStripeSwitch` ANDs an operator's own kill
 * switch over the top, so either can turn Stripe off and neither can turn the other back on.
 *
 * **Off does not demote anybody.** Entitlement comes from `PlanResolver`; whether a plan *gates*
 * anything is `billing.enforce_plans`, which is a different switch for a different question.
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
        return (bool) config('billing.stripe.enabled', true);
    }
}
