<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\AiPlatform\Data\CreditAllowance;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use Spatie\LaravelData\Data;

/**
 * A team's lead-discovery allowance for the current billing period, reported by a
 * {@see LeadQuotaSource}. The MVP source is unlimited (a stub);
 * Billing (Phase 16) swaps in a real plan-backed source without changing the guard or callers —
 * mirrors AI Platform's {@see CreditAllowance}.
 *
 * `limit === null` means "no cap" (the stub). Counts are whole leads.
 */
final class LeadQuota extends Data
{
    public function __construct(
        public ?int $limit,
        public int $used,
    ) {}

    /**
     * An unlimited allowance (the MVP stub).
     */
    public static function unlimited(int $used = 0): self
    {
        return new self(limit: null, used: $used);
    }

    /**
     * Leads still available this period, or null when unlimited.
     */
    public function remaining(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * Whether the team has already consumed its allowance (always false when unlimited).
     */
    public function isExhausted(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * Clamp a requested run size to what's left in the allowance (unchanged when unlimited).
     */
    public function clamp(int $requested): int
    {
        $remaining = $this->remaining();

        return $remaining === null ? $requested : max(0, min($requested, $remaining));
    }
}
