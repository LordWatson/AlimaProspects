<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Billing\Enums\PlanKey;
use Carbon\CarbonImmutable;

/**
 * What an operator is granting (Phase 19b).
 *
 * The three allowance fields are nullable and null means **"use the plan's own number"**, not
 * unlimited — the plan's own null is what means unlimited. Keeping those two readings apart is the
 * difference between a contract for 40,000 leads and a contract for all of them.
 */
final readonly class PlanGrantData
{
    public function __construct(
        public PlanKey $plan,
        public string $note,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt = null,
        public ?int $seats = null,
        public ?int $leads = null,
        public ?int $credits = null,
    ) {}
}
