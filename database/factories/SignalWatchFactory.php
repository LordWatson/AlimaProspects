<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalWatch>
 */
class SignalWatchFactory extends Factory
{
    protected $model = SignalWatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'icp_id' => null,
            'name' => 'Dubai wealth events',
            'market' => Market::Uae,
            'location' => 'Dubai',
            'event_types' => [
                SignalEventType::Acquisition->value,
                SignalEventType::FundingRound->value,
                SignalEventType::Relocation->value,
            ],
            'cadence' => SignalWatchCadence::Daily,
            'is_active' => true,
            'last_run_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cadence' => SignalWatchCadence::Weekly,
        ]);
    }
}
