<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Services\UnmeteredCreditReserver;

/**
 * Whether a team may spend on this, and the booking that says they did (PL3's seam, PL4's job).
 *
 * The **fifth** seam of the kind ADR 0012 established — after `CreditSource`, `LeadQuotaSource`,
 * `SeatLimitSource` and `SignalQuotaSource` — and the same shape: Enrichment declares it with a
 * default that works on its own, and Billing binds the real one over the top. No module imports
 * `App\Modules\Billing`.
 *
 * **Reserve, then commit, and never the other way round.** A provider call is money leaving the
 * building, so the allowance is checked and held *before* the call; what it actually cost is
 * committed afterwards, and what was held and not spent is released. The failure mode of
 * committing first is the one that matters: a run that reserved nothing and spent twice its
 * allowance, discovered at the end of the month.
 *
 * The default ({@see UnmeteredCreditReserver}) is **permissive**,
 * like the three ADR 0012 stubs and unlike `StubSignalQuotaSource`: there is no cost floor to
 * protect here, because PL2 ships no enabled vendor and so an unmetered reserve can spend nothing.
 * The moment a vendor is enabled, PL4's real reserver is what stands between a plan and a bill.
 */
interface CreditReserver
{
    /**
     * Hold `$units` of allowance for this scope, or refuse.
     *
     * A refusal comes back as a reservation that {@see CreditReservation::granted()} denies, with a
     * reason code — **not** as an exception. A team at its ceiling is an ordinary answer the run
     * records and the screen explains; an exception would make it an error in the logs and a red
     * light on the provider panel.
     */
    public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation;

    /**
     * Settle a reservation against what was actually spent, releasing the rest.
     *
     * Idempotent: a worker that dies between the last call and the commit will be retried, and a
     * double commit must not bill twice.
     */
    public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void;

    /**
     * Give back a reservation nothing was spent against — a run that refused after reserving, or a
     * job that died before calling anybody.
     */
    public function release(CreditReservation $reservation): void;
}
