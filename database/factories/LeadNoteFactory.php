<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadNote>
 */
class LeadNoteFactory extends Factory
{
    protected $model = LeadNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
