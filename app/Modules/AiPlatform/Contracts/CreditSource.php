<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Contracts;

use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\AiPlatform\Services\StubCreditSource;
use App\Modules\Core\Models\Team;

/**
 * Supplies a team's AI spend allowance for the current period. The default binding is a
 * config-backed stub ({@see StubCreditSource}); Billing (Phase 16) binds a plan-aware
 * implementation over it, and neither the credit guard nor any caller changed.
 */
interface CreditSource
{
    public function allowanceFor(Team $team): CreditAllowance;
}
