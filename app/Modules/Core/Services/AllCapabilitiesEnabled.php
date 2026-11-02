<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;

/**
 * The default capability gate: everything is on.
 *
 * Deliberately permissive, like Auth's `UnlimitedSeatLimit` and Lead Sourcing's quota stub. An
 * installation with neither Billing nor Admin bound should behave exactly as the product did before
 * capabilities existed, rather than switching six features off for everybody — and a refusal with
 * no plan behind it would read as a bug in whichever module hit it, not as a missing binding.
 *
 * Billing wraps this with the plan's list; Admin wraps that with the operator's levers.
 */
final class AllCapabilitiesEnabled implements CapabilityGate
{
    public function allows(Team $team, Capability $capability): bool
    {
        return $capability->enabledByDefault();
    }
}
