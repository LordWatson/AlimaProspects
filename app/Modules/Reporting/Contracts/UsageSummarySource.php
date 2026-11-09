<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Data\AllowanceLine;
use App\Modules\Reporting\Services\NoUsageSummary;

/**
 * What this team has used of its monthly allowances, for the dashboard to draw.
 *
 * The eighth of the seams Billing fills in (ADR 0012), and built to the shape of the other seven:
 * this module ships its own default binding ({@see NoUsageSummary}, which answers *nothing to
 * show*), Billing is registered last and binds a plan-backed source over it, and nothing in
 * Reporting imports `App\Modules\Billing` (CLAUDE.md §3).
 *
 * It is the one seam here that is **purely a read** — it gates nothing and refuses nothing, so
 * there is no permissive-versus-restrictive question to settle: with no plan-backed source bound,
 * the dashboard simply has no allowance panel, which is the honest rendering of an installation
 * that does not meter anything.
 *
 * Which lines are worth a team's attention is **Billing's** judgement, not this module's: a
 * dashboard that filtered the list would be deciding which allowances matter, and a plan's shape
 * is exactly the thing Reporting is not supposed to know. It renders what it is handed, in order.
 */
interface UsageSummarySource
{
    /**
     * This period's position on the allowances worth putting on a dashboard, in display order.
     *
     * @return list<AllowanceLine>
     */
    public function allowancesFor(Team $team): array;
}
