<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Enums\OnboardingStep;
use App\Modules\Onboarding\Models\OnboardingProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingProgress>
 */
class OnboardingProgressFactory extends Factory
{
    protected $model = OnboardingProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'current_step' => OnboardingStep::Company->value,
            'completed_steps' => [],
            'completed_at' => null,
        ];
    }

    /**
     * Progress with every data step saved and onboarding finished.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_step' => OnboardingStep::Review->value,
            'completed_steps' => OnboardingStep::dataValues(),
            'completed_at' => now(),
        ]);
    }
}
