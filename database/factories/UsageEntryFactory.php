<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Billing\Models\UsageEntry;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UsageEntry>
 */
class UsageEntryFactory extends Factory
{
    protected $model = UsageEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'meter' => BillingMetric::EmailReveal->value,
            'direction' => LedgerDirection::Commit->value,
            'quantity' => 1,
            'period' => BillingPeriod::containing()->key(),
            'reservation' => null,
            'idempotency_key' => null,
            'meta' => null,
            'created_at' => now(),
        ];
    }

    public function meter(BillingMetric $metric): self
    {
        return $this->state(fn (): array => ['meter' => $metric->value]);
    }

    /**
     * An unsettled hold — the state a commit, a release or a concurrency test starts from.
     *
     * The handle is generated here rather than left null, because a reserve row without one is a
     * hold nothing can ever settle.
     */
    public function reserved(int $quantity = 1): self
    {
        return $this->state(fn (): array => [
            'direction' => LedgerDirection::Reserve->value,
            'quantity' => $quantity,
            'reservation' => (string) Str::uuid(),
        ]);
    }

    /**
     * Allowance bought outright.
     */
    public function toppedUp(int $quantity, ?string $idempotencyKey = null): self
    {
        return $this->state(fn (): array => [
            'direction' => LedgerDirection::TopUp->value,
            'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
