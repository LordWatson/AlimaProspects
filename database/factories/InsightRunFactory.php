<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\AiInsights\Models\InsightRun;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsightRun>
 */
class InsightRunFactory extends Factory
{
    protected $model = InsightRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'local_date' => now()->toDateString(),
            'generated_at' => now(),
            'insights_created' => 0,
            'ai_called' => false,
            'cost_micros' => 0,
        ];
    }

    /**
     * A run on a given team-local date — the state the "is this team due?" tests are about.
     */
    public function on(string $localDate): self
    {
        return $this->state(fn (): array => ['local_date' => $localDate]);
    }
}
