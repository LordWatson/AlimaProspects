<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\Core\Models\Team;

/**
 * Blocks AI work for a team that has consumed its allowance, *before* any provider call is made —
 * the first line of margin protection (mvp.md cost model). It checks the allowance reported by the
 * {@see CreditSource}; when a team has no active team context (e.g. a system task) the guard is a
 * no-op, since there is no plan to enforce against.
 */
final class AiCreditGuard
{
    public function __construct(private readonly CreditSource $credits) {}

    /**
     * @throws AiCreditExceededException when the team is already over its allowance.
     */
    public function ensureWithinAllowance(?Team $team): void
    {
        if (! $team instanceof Team) {
            return;
        }

        if ($this->credits->allowanceFor($team)->isExhausted()) {
            throw AiCreditExceededException::make();
        }
    }
}
