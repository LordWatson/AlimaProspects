<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\BillingMetric;
use Spatie\LaravelData\Data;

/**
 * What one metric has cost a team this period, and what is left of its allowance.
 *
 * Everything here is in the metric's own unit (see {@see BillingMetric}) — leads as leads, AI as
 * credits. The three numbers answer three different questions and none of them substitutes for
 * another: `remaining` is what a member wants to know, `overage` is what the invoice will say, and
 * `ceiling` is where work stops regardless.
 */
final class MetricUsage extends Data
{
    public function __construct(
        public readonly BillingMetric $metric,
        public readonly int $used,
        /** The plan's monthly allowance; null = unlimited. */
        public readonly ?int $included,
        /** Where work stops however much overage is permitted; null = no ceiling. */
        public readonly ?int $ceiling,
        /** Whether the plan meters work past the allowance rather than stopping. */
        public readonly bool $overageAllowed,
    ) {}

    /**
     * Allowance still unspent, or null when unlimited.
     */
    public function remaining(): ?int
    {
        if ($this->included === null) {
            return null;
        }

        return max(0, $this->included - $this->used);
    }

    /**
     * How much of the allowance is gone, 0–100. An unlimited plan is always 0 — there is no bar to
     * fill, and showing one at 100% would read as a warning.
     */
    public function usedPercent(): int
    {
        if ($this->included === null || $this->included <= 0) {
            return $this->included === 0 && $this->used > 0 ? 100 : 0;
        }

        return (int) min(100, (int) floor($this->used / $this->included * 100));
    }

    /**
     * Units consumed beyond the allowance — what overage is billed on. Zero unless the plan permits
     * overage, because on a plan that stops at the allowance there is nothing to bill for.
     */
    public function overage(): int
    {
        if (! $this->overageAllowed || $this->included === null) {
            return 0;
        }

        return max(0, $this->used - $this->included);
    }

    /**
     * Whether further work is refused. A plan with overage is exhausted at its ceiling, not at its
     * allowance — that is the whole difference between the two numbers.
     */
    public function isExhausted(): bool
    {
        if ($this->ceiling === null) {
            return false;
        }

        return $this->used >= $this->ceiling;
    }

    /**
     * How many more units may be consumed before the ceiling, or null when there is no ceiling.
     * Used to clamp a requested run size rather than refuse the whole run.
     */
    public function headroom(): ?int
    {
        if ($this->ceiling === null) {
            return null;
        }

        return max(0, $this->ceiling - $this->used);
    }
}
