<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\AllowAllLeadInterestGate;

/**
 * Gates discovery on a Legitimate Interest Assessment (LIA) being in place for the team's market —
 * the compliance hook required before sourcing begins (UK GDPR legitimate-interest basis; UAE PDPL
 * prior-consent posture, ADR 0007). The MVP binding passes everything
 * ({@see AllowAllLeadInterestGate}); Compliance & Privacy
 * (Phase 15) swaps in the real gate backed by the team's LIA record, with no change to the
 * discovery Action that calls it.
 */
interface LeadInterestGate
{
    /**
     * Assert the team may run lead discovery right now.
     *
     * @throws LeadSourcingException when no valid basis exists.
     */
    public function assertDiscoveryAllowed(Team $team): void;
}
