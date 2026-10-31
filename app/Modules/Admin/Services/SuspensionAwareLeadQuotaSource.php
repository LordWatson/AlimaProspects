<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\LeadQuota;

/**
 * A suspended team sources no leads (Phase 19a).
 *
 * The lead-quota half of suspension, decorating whatever source is bound rather than replacing it —
 * see {@see SuspensionAwareCreditSource} for the full reasoning, which is the same.
 *
 * A limit of zero rather than `LeadQuota::unlimited()`'s null, and the distinction matters here more
 * than in the credit case: `clamp()` returns the request unchanged when the limit is null, so an
 * unlimited quota is not "no quota", it is "as many as you asked for". Zero makes `isExhausted()`
 * true and `clamp()` return nothing, which is what the discovery guard reads.
 */
final class SuspensionAwareLeadQuotaSource implements LeadQuotaSource
{
    public function __construct(
        private readonly LeadQuotaSource $inner,
        private readonly TeamSuspension $suspension,
    ) {}

    public function quotaFor(Team $team): LeadQuota
    {
        $quota = $this->inner->quotaFor($team);

        if (! $this->suspension->isSuspended($team)) {
            return $quota;
        }

        return new LeadQuota(limit: 0, used: $quota->used);
    }
}
