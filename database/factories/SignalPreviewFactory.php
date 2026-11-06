<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Models\SignalPreview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalPreview>
 */
class SignalPreviewFactory extends Factory
{
    protected $model = SignalPreview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => null,
            'draft_hash' => sha1((string) $this->faker->unique()->numberBetween(1, 1_000_000)),
            'queries_count' => 4,
            'results_count' => 12,
            'kept_count' => 3,
            // The default preview's real cost: four Serper searches plus one cheap AI pass.
            'cost_micros' => 5_000,
        ];
    }

    /**
     * A preview that cost a given amount — what a budget test is actually about.
     */
    public function costing(int $micros): static
    {
        return $this->state(fn (array $attributes): array => ['cost_micros' => $micros]);
    }
}
