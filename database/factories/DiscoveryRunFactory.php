<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryRun>
 */
class DiscoveryRunFactory extends Factory
{
    protected $model = DiscoveryRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'icp_id' => null,
            'source' => LeadSource::CompaniesHouse,
            'status' => DiscoveryRunStatus::Pending,
            'params' => [
                'terms' => 'marketing agency',
                'location' => null,
                'limit' => 25,
                'icp_id' => null,
                'industries' => ['marketing'],
            ],
            'requested_limit' => 25,
            'leads_found' => 0,
            'leads_created' => 0,
            'cost_micros' => 0,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'created_by' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DiscoveryRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(int $found = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DiscoveryRunStatus::Completed,
            'leads_found' => $found,
            'leads_created' => $found,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DiscoveryRunStatus::Failed,
            'error_message' => 'We couldn’t complete this discovery run. Please try again.',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
