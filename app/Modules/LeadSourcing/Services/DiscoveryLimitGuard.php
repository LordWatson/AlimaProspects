<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Services\AiCreditGuard;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\LeadQuota;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;

/**
 * Blocks a discovery run for a team that has consumed its lead allowance, *before* any provider
 * call is made — the first line of margin protection (mvp.md cost model), mirroring AI Platform's
 * {@see AiCreditGuard}. Reads the allowance from the
 * {@see LeadQuotaSource} (an unlimited stub until Billing). Also used to clamp a requested run size
 * to what's left.
 */
final class DiscoveryLimitGuard
{
    public function __construct(private readonly LeadQuotaSource $quota) {}

    /**
     * @throws LeadSourcingException when the team is already over its allowance.
     */
    public function ensureWithinAllowance(Team $team): void
    {
        if ($this->quota->quotaFor($team)->isExhausted()) {
            throw LeadSourcingException::quotaExhausted();
        }
    }

    /**
     * The team's current allowance (for clamping a run size or surfacing remaining leads).
     */
    public function quotaFor(Team $team): LeadQuota
    {
        return $this->quota->quotaFor($team);
    }
}
