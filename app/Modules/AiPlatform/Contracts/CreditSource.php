<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Contracts;

use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\AiPlatform\Services\StubCreditSource;
use App\Modules\Core\Models\Team;

/**
 * Supplies a team's AI spend allowance for the current period. Phase 5 ships a config-backed stub
 * ({@see StubCreditSource}); Billing (Phase 16) replaces it with a
 * plan-aware implementation without the credit guard or any caller changing.
 */
interface CreditSource
{
    public function allowanceFor(Team $team): CreditAllowance;
}
