<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\Core\Models\Team;

/**
 * A suspended team has no AI allowance (Phase 19a).
 *
 * A **decorator**, not a replacement: it wraps whatever `CreditSource` is already bound — the
 * config-backed stub, or Billing's plan-backed one — and only intervenes when the team is
 * suspended. Admin therefore adds a rule to the credit guard without knowing what a plan is, and
 * Billing keeps owning the allowance. Registered with `extend()` for the same reason; a `bind()`
 * would have replaced Billing's source rather than sitting in front of it.
 *
 * The allowance is reported as **fully consumed** rather than as a limit of zero with zero used,
 * which looks like the same thing and is not: `CreditAllowance::isExhausted()` compares used
 * against limit, and `0 >= 0` is true, but the screen that renders "x of y used" would show a
 * meaningless `0 of 0`. Reporting the real usage against a zero limit tells a member the truth —
 * their allowance is gone — and `remainingMicros()` clamps at zero either way.
 *
 * Why here and not in middleware: ADR 0012's argument, unchanged. A queued enrichment job and a
 * scheduled insight run spend a team's allowance with no request behind them, so a check in the
 * request pipeline would stop a suspended team's screens and none of its background spend.
 */
final class SuspensionAwareCreditSource implements CreditSource
{
    public function __construct(
        private readonly CreditSource $inner,
        private readonly TeamSuspension $suspension,
    ) {}

    public function allowanceFor(Team $team): CreditAllowance
    {
        $allowance = $this->inner->allowanceFor($team);

        if (! $this->suspension->isSuspended($team)) {
            return $allowance;
        }

        return new CreditAllowance(limitMicros: 0, usedMicros: $allowance->usedMicros);
    }
}
