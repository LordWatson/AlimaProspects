<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\LeadQuota;

/**
 * The fallback lead-quota source: unlimited.
 *
 * **Billing replaced this in production** (Phase 16) — `Billing\Services\PlanLeadQuotaSource` is
 * bound over it and reports the team's plan allowance, and neither {@see DiscoveryLimitGuard} nor
 * any of its callers changed, which is exactly what the seam was built for in Phase 7. It stays as
 * the default binding so this module still works without Billing registered.
 */
final class StubLeadQuotaSource implements LeadQuotaSource
{
    public function quotaFor(Team $team): LeadQuota
    {
        return LeadQuota::unlimited();
    }
}
