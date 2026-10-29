<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\LeadQuota;

/**
 * The MVP lead-quota source: every team is unlimited until Billing (Phase 16) replaces this binding
 * with a plan-backed source. Mirrors AI Platform's `StubCreditSource`. Keeping the seam real now
 * means {@see DiscoveryLimitGuard} and its callers never change when real limits arrive.
 */
final class StubLeadQuotaSource implements LeadQuotaSource
{
    public function quotaFor(Team $team): LeadQuota
    {
        return LeadQuota::unlimited();
    }
}
