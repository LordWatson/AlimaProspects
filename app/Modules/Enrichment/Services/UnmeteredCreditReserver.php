<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentScope;

/**
 * The default {@see CreditReserver}: grants everything and records nothing (PL3, replaced in PL4).
 *
 * Permissive, like the three ADR 0012 stubs and unlike `StubSignalQuotaSource`. The difference is
 * whether there is a **cost floor** to protect: the signal stub is strict because S6/S7/S10 shipped
 * real spend guards that `BILLING_ENFORCE_PLANS=false` must not remove. Here there is nothing to
 * protect yet — PL2 ships no enabled vendor, so an unmetered reserve can spend precisely nothing.
 *
 * That changes the day a vendor is enabled, which is why PL4 comes before any of them does.
 */
final class UnmeteredCreditReserver implements CreditReserver
{
    public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
    {
        return CreditReservation::grant($scope, $units);
    }

    public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void
    {
        // Nothing to settle. The spend is on the attempts either way, which is where PL4 will read
        // it from — so a run made before PL4 is still a run whose cost can be reconstructed.
    }

    public function release(CreditReservation $reservation): void
    {
        // Nothing was held.
    }
}
