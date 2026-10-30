<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Contracts;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Data\LeadQuota;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use App\Modules\LeadSourcing\Services\StubLeadQuotaSource;

/**
 * Reports a team's lead-discovery allowance for the current period. The MVP binding is an unlimited
 * stub ({@see StubLeadQuotaSource}); Billing (Phase 16) swaps in
 * a plan-backed source so {@see DiscoveryLimitGuard} enforces real
 * limits without any caller change. Mirrors AI Platform's `CreditSource` seam.
 */
interface LeadQuotaSource
{
    public function quotaFor(Team $team): LeadQuota;
}
