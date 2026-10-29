<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\Core\Models\Team;

/**
 * The Phase 5 stub allowance: every team gets the same flat monthly allowance from config
 * (`ai-platform.credits`), expressed in credits and converted to cost micro-units. Usage is read
 * from the real `ai_requests` ledger, so the guard behaves correctly end-to-end; only the *limit*
 * is a placeholder. Billing (Phase 16) swaps this for a plan-aware source — no caller changes.
 */
final class StubCreditSource implements CreditSource
{
    public function __construct(private readonly AiUsageLogger $usage) {}

    public function allowanceFor(Team $team): CreditAllowance
    {
        $credits = (int) config('ai-platform.credits.monthly_default', 100_000);
        $microsPerCredit = (int) config('ai-platform.credits.micros_per_credit', 1_000);

        return new CreditAllowance(
            limitMicros: $credits * $microsPerCredit,
            usedMicros: $this->usage->usedMicros($team),
        );
    }
}
