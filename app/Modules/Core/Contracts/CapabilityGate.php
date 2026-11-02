<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AllCapabilitiesEnabled;

/**
 * **The one question anything asks about a feature being available to a team** (Phase 19b, ADR 0015).
 *
 * It lives in Core, with a permissive default ({@see AllCapabilitiesEnabled}), and is then decorated
 * twice — exactly the pattern Billing already uses for credits, lead quota and seats:
 *
 * 1. **Core** binds the default: everything on.
 * 2. **Billing** wraps it with the plan's `capabilities` list, because "what does this team's plan
 *    include" is Billing's question and nobody else's. This is why no module has to import
 *    `App\Modules\Billing` to find out (CLAUDE.md §3).
 * 3. **Admin** wraps that with the operator's levers, in this order: **suspension beats the global
 *    kill switch beats the per-team override beats the plan.** A suspended team is not a feature
 *    question; a kill switch exists for the afternoon something ships broken, and an operator must
 *    not have to hunt down every team override to use it.
 *
 * Provider order in `bootstrap/providers.php` gives that composition for free: Core, then Billing,
 * then Admin last.
 *
 * **One gate, three callers.** The guards, the `capability:` route middleware and the shared Inertia
 * prop all come through here, so a member cannot be offered something the guard will refuse — which
 * is the drift this contract exists to prevent. Nothing reads `plans.*.capabilities` directly.
 */
interface CapabilityGate
{
    public function allows(Team $team, Capability $capability): bool;
}
