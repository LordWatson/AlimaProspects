<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessIntelReport>
 */
class BusinessIntelReportFactory extends Factory
{
    protected $model = BusinessIntelReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'version' => 1,
            'status' => BackgroundCheckStatus::Ready,
            'business_name' => fake()->company(),
            'website' => fake()->url(),
            'findings' => [
                'summary' => fake()->paragraph(),
                'offering' => fake()->sentence(),
                'positioning' => fake()->sentence(),
                'target_customers' => [fake()->jobTitle(), fake()->jobTitle()],
                'competitors' => [fake()->company(), fake()->company()],
                'suggested_industries' => ['SaaS', 'Logistics'],
                'confidence' => 0.8,
            ],
            'prompt_key' => 'business_intel.background_check',
            'prompt_version' => 1,
            'model' => 'claude-sonnet-5-5',
            'cost_micros' => 12_000,
            'error_message' => null,
            'completed_at' => now(),
            'accepted_at' => null,
            'accepted_by' => null,
        ];
    }

    /**
     * A report still being generated (no findings yet).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BackgroundCheckStatus::Pending,
            'findings' => null,
            'prompt_key' => null,
            'prompt_version' => null,
            'model' => null,
            'cost_micros' => 0,
            'completed_at' => null,
        ]);
    }

    /**
     * A run that could not complete.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BackgroundCheckStatus::Failed,
            'findings' => null,
            'error_message' => 'The background check could not be completed.',
            'completed_at' => now(),
        ]);
    }

    /**
     * An accepted report.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BackgroundCheckStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
