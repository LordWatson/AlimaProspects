<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\ICP\Models\Icp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Icp>
 */
class IcpFactory extends Factory
{
    protected $model = Icp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->randomElement(['UK growth agencies', 'UAE hospitality', 'SaaS scale-ups']),
            'description' => fake()->optional()->sentence(),
            'is_active' => false,
        ];
    }

    /**
     * The team's active ICP.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
        ]);
    }
}
