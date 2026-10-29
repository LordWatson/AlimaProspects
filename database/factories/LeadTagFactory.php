<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadTag>
 */
class LeadTagFactory extends Factory
{
    protected $model = LeadTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => LeadTag::normalise($name),
        ];
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => LeadTag::normalise($name),
        ]);
    }
}
