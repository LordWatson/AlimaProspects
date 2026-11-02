<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Services\ConfiguredStripeSwitch;

/**
 * Whether Stripe is switched on for this deployment (Phase 19b).
 *
 * The seam behind the global kill switch. Billing ships {@see ConfiguredStripeSwitch}, which answers
 * from `.env` the way the module always has; Admin binds a settings-backed one, so an operator can
 * turn Stripe off from a browser at 2am without a deploy.
 *
 * **What "off" means, precisely, because getting this wrong costs customers:** checkout, the
 * customer portal, plan swaps, cancellation, resumption and metered reporting all refuse, and the
 * webhook stops being accepted. **Entitlement is untouched.** A team on Scale stays on Scale with
 * its allowances intact and goes on working — see `PlanResolver`, which reads a live subscription
 * row regardless of whether we are currently willing to talk to Stripe about it.
 */
interface StripeSwitch
{
    public function isEnabled(): bool;
}
