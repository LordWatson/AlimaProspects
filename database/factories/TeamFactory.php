<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'Europe/London',
            'locale' => 'en',
            'market' => fake()->randomElement(['uk', 'uae']),
        ];
    }
}
