<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;

/**
 * The default {@see ContactApprovalGate}: allows everything (PL3, replaced in PL5).
 *
 * Permissive because it is the **status quo** and not a new permission. The free-tier extraction
 * has stored contacts with no suppression check since Phase 8, and whether a prospect may be
 * *approached* has always been `OutreachGuard`'s separate answer — so a module that refused here
 * would be enforcing a rule nothing else in the product enforces yet, in the one place that cannot
 * see the suppression list.
 *
 * PL5 binds the real gate, and that is also when the rule becomes real: a person who asked to be
 * left alone should not be stored, and a member should not be charged to reveal an address they may
 * not use.
 */
final class AllowAllContactApproval implements ContactApprovalGate
{
    public function allowsEmail(Team $team, string $email): bool
    {
        return true;
    }

    public function allowsPhone(Team $team, string $phone): bool
    {
        return true;
    }

    public function allowsDomain(Team $team, string $domain): bool
    {
        return true;
    }
}
