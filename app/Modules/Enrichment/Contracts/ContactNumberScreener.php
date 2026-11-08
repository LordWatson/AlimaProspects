<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\NoContactScreening;

/**
 * Screen a revealed number against whatever do-not-call register applies to it (PL3's seam, PL5's
 * job).
 *
 * The second seam Compliance fills in on this module, and deliberately **not** part of
 * {@see ContactApprovalGate}: that one answers *may we hold and show this at all* before any money
 * moves, and this one records *a fact about the number* once we have it. A TPS registration does
 * not stop a team holding a switchboard number or calling it about an existing contract — it stops
 * an unsolicited marketing call — so a screening that refused would be enforcing a rule that does
 * not exist, and one folded into the approval gate would have to.
 *
 * It returns nothing, which is the point: the verdict is **stamped on the contact** so the lead
 * screen can render a badge without a lookup per row, and the register's own answer is cached
 * platform-wide so two teams holding the same number pay for one lookup. A caller that wanted the
 * verdict back would be a caller deciding what to do with it.
 *
 * The default ({@see NoContactScreening}) does nothing, which leaves every number `unchecked` —
 * not `clear`. PL5 binds the real one.
 */
interface ContactNumberScreener
{
    /**
     * Screen this contact's number and record the verdict on it. Idempotent, and a no-op when
     * there is no number, no register applies, or a recent verdict already stands.
     */
    public function screen(Team $team, LeadContact $contact): void;
}
