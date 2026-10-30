<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;

/**
 * The MVP LIA gate: permits all discovery until Compliance & Privacy (Phase 15) replaces this
 * binding with the real assessment check (UK legitimate-interest / UAE prior-consent, ADR 0007).
 * The seam exists now so the discovery Action already calls the gate — Phase 15 only swaps the
 * implementation.
 */
final class AllowAllLeadInterestGate implements LeadInterestGate
{
    public function assertDiscoveryAllowed(Team $team): void
    {
        // No-op: a legitimate-interest assessment is not yet enforced (stub until Phase 15).
    }
}
