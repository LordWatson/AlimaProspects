<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalRun>
 */
class SignalRunFactory extends Factory
{
    protected $model = SignalRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'signal_watch_id' => SignalWatch::factory(),
            'status' => SignalRunStatus::Queued,
            'queries_count' => 0,
            'results_count' => 0,
            'signals_created' => 0,
            'cost_micros' => 0,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(int $created = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalRunStatus::Completed,
            'queries_count' => 8,
            'results_count' => 40,
            'signals_created' => $created,
            'cost_micros' => 40_000,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalRunStatus::Failed,
            'error' => 'We couldn’t complete this signal run. Please try again.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}
