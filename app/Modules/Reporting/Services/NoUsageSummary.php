<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Contracts\UsageSummarySource;

/**
 * The module's own default: no allowances to show.
 *
 * Reporting measures what a team did; it has no idea what a team is entitled to, and must not
 * acquire one. So the default answer is an empty list and the dashboard draws no allowance panel —
 * which is correct for an installation with no billing module bound, and is why this stub needs no
 * argument about being permissive or restrictive the way the quota seams do: it refuses nothing.
 */
final class NoUsageSummary implements UsageSummarySource
{
    /**
     * @return list<never>
     */
    public function allowancesFor(Team $team): array
    {
        return [];
    }
}
