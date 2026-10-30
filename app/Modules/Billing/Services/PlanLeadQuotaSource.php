<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Billing\Support\EnforcesPlanLimits;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\LeadQuota;
use App\Modules\LeadSourcing\Services\StubLeadQuotaSource;

/**
 * The real lead allowance, replacing Phase 7's unlimited {@see StubLeadQuotaSource}. Bound over the
 * stub in {@see BillingServiceProvider}; `DiscoveryLimitGuard` and
 * every caller of it are untouched.
 *
 * Like the AI source, the limit reported is the **ceiling**, so a metered plan keeps discovering
 * past its included companies and is billed for the excess. Where this one matters more is
 * `LeadQuota::clamp()`: a run asked for 200 companies with 40 left is cut to 40 rather than
 * refused, so a team near the edge of its allowance gets the companies it is still entitled to
 * instead of an error.
 */
final class PlanLeadQuotaSource implements LeadQuotaSource
{
    use EnforcesPlanLimits;

    public function __construct(private readonly UsageLedger $usage) {}

    public function quotaFor(Team $team): LeadQuota
    {
        $usage = $this->usageFor($team, BillingMetric::Leads);

        if ($usage->ceiling === null) {
            return LeadQuota::unlimited($usage->used);
        }

        return new LeadQuota(limit: $usage->ceiling, used: $usage->used);
    }

    protected function usageLedger(): UsageLedger
    {
        return $this->usage;
    }
}
