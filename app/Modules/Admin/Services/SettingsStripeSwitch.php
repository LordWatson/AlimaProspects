<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Billing\Contracts\StripeSwitch;

/**
 * The operator's Stripe kill switch (Phase 19b).
 *
 * Decorates Billing's own answer rather than replacing it, and the `&&` is the whole logic: Stripe
 * is on when **both** an operator has not turned it off *and* it is actually configured. Either one
 * alone is not enough — a deployment with no keys cannot take a payment however the switch is set,
 * and an operator who has turned it off means it, keys or not.
 *
 * What "off" does **not** do, because this is the mistake that would cost customers: it does not
 * change anybody's plan. Entitlement comes from `PlanResolver`, which reads a grant and then a
 * subscription row — neither of which cares whether we are currently willing to talk to Stripe.
 */
final class SettingsStripeSwitch implements StripeSwitch
{
    public function __construct(
        private readonly StripeSwitch $inner,
        private readonly PlatformSettings $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->bool('billing.stripe_enabled') && $this->inner->isEnabled();
    }
}
