<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageMeter>
 */
class UsageMeterFactory extends Factory
{
    protected $model = UsageMeter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $period = BillingPeriod::containing();

        return [
            'team_id' => Team::factory(),
            'metric' => BillingMetric::Leads->value,
            'period_start' => $period->start,
            'period_end' => $period->end,
            'used' => 0,
            'included' => 0,
            'reported' => 0,
            'synced_at' => now(),
        ];
    }

    public function metric(BillingMetric $metric): self
    {
        return $this->state(fn (): array => ['metric' => $metric->value]);
    }

    /**
     * A meter already past its allowance — the state an overage test starts from.
     */
    public function overBy(int $units, int $included = 100): self
    {
        return $this->state(fn (): array => [
            'included' => $included,
            'used' => $included + $units,
        ]);
    }

    public function forPeriod(BillingPeriod $period): self
    {
        return $this->state(fn (): array => [
            'period_start' => $period->start,
            'period_end' => $period->end,
        ]);
    }
}
