<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

use App\Modules\AiPlatform\Contracts\CreditSource;

/**
 * A team's AI spend allowance for the current period, in cost micro-units (see the `ai_requests`
 * ledger for the unit). Supplied by a {@see CreditSource} —
 * stubbed from config until Billing provides the real plan allowance.
 */
final readonly class CreditAllowance
{
    public function __construct(
        public int $limitMicros,
        public int $usedMicros,
    ) {}

    public function remainingMicros(): int
    {
        return max(0, $this->limitMicros - $this->usedMicros);
    }

    /**
     * Whether the allowance is already fully consumed (no further spend permitted).
     */
    public function isExhausted(): bool
    {
        return $this->usedMicros >= $this->limitMicros;
    }
}
