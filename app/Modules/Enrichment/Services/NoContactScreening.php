<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\ContactNumberScreener;
use App\Modules\Enrichment\Models\LeadContact;

/**
 * The default {@see ContactNumberScreener}: records nothing (PL3, replaced in PL5).
 *
 * Doing nothing leaves `lead_contacts.tps_status` at `unchecked`, which is **not** `clear` — the
 * distinction this default depends on. A no-op that stamped `clear` would turn a module that has
 * never asked a register into one asserting every number is callable.
 */
final class NoContactScreening implements ContactNumberScreener
{
    public function screen(Team $team, LeadContact $contact): void
    {
        // Nothing. The column's default says so.
    }
}
