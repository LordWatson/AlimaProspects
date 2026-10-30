<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 */
class RetentionPolicyFactory extends Factory
{
    protected $model = RetentionPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'is_enabled' => true,
            'uncontacted_lead_days' => (int) config('compliance.retention.defaults.uncontacted_lead_days', 180),
            'contacted_lead_days' => (int) config('compliance.retention.defaults.contacted_lead_days', 540),
        ];
    }

    /**
     * A team that has switched the sweep off — a litigation hold, or a team mid-migration. The
     * sweep must skip them entirely, which is worth a state of its own.
     */
    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    /**
     * Short windows, so a test can age a lead past them without inventing a date two years ago.
     */
    public function windows(int $uncontactedDays, ?int $contactedDays = null): self
    {
        return $this->state(fn (): array => [
            'uncontacted_lead_days' => $uncontactedDays,
            'contacted_lead_days' => $contactedDays ?? $uncontactedDays,
        ]);
    }
}
