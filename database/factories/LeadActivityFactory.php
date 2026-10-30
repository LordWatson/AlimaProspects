<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadActivity>
 */
class LeadActivityFactory extends Factory
{
    protected $model = LeadActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            // Null is the common case: most of the timeline is work the platform did on its own.
            'user_id' => null,
            'type' => LeadActivityType::Discovered,
            'subject_type' => null,
            'subject_id' => null,
            'properties' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function type(LeadActivityType $type, array $properties = []): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    /**
     * An entry a member did, rather than the platform.
     */
    public function by(int $userId): self
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
