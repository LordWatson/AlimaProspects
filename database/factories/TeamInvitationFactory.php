<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    protected $model = TeamInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'invited_by' => null,
            'email' => fake()->unique()->safeEmail(),
            'role' => TeamRole::Member->value,
            'token' => Str::random(64),
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }
}
