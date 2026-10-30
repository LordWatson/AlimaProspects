<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\AiPlatform\Services\StubCreditSource;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Billing\Support\EnforcesPlanLimits;
use App\Modules\Core\Models\Team;

/**
 * The real AI allowance, replacing Phase 5's {@see StubCreditSource}: the plan's monthly credits,
 * or its overage ceiling where the plan meters instead of stopping. Bound over the stub in
 * {@see BillingServiceProvider}; the credit guard and every caller
 * of it are untouched, which is what the seam was for.
 *
 * **The limit reported is the ceiling, not the allowance.** `CreditAllowance::isExhausted()` is
 * what the guard refuses on, and on a metered plan a team past its included credits is still
 * entitled to work — it is simply being billed for it. Reporting the included figure here would
 * stop them at the point we start charging, which is the opposite of what overage means.
 */
final class PlanCreditSource implements CreditSource
{
    use EnforcesPlanLimits;

    public function __construct(private readonly UsageLedger $usage) {}

    public function allowanceFor(Team $team): CreditAllowance
    {
        $metric = BillingMetric::AiCredits;
        $usage = $this->usageFor($team, $metric);

        // Back into micro-units, which is what `CreditAllowance` and the `ai_requests` ledger
        // speak. Credits are Billing's unit and stop at this boundary. The round trip loses the
        // part of a credit a team has spent but not yet completed — under a tenth of a penny,
        // always in the customer's favour, and the alternative is a second unit in the ledger.
        return new CreditAllowance(
            limitMicros: $usage->ceiling === null ? PHP_INT_MAX : $metric->toMicros($usage->ceiling),
            usedMicros: $metric->toMicros($usage->used),
        );
    }

    protected function usageLedger(): UsageLedger
    {
        return $this->usage;
    }
}
