<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadTask>
 */
class LeadTaskFactory extends Factory
{
    protected $model = LeadTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'created_by' => User::factory(),
            'assigned_to' => null,
            'title' => 'Call them back',
            'status' => LeadTaskStatus::Open,
            'due_at' => null,
            'remind_at' => null,
            'reminded_at' => null,
            'completed_at' => null,
            'completed_by' => null,
        ];
    }

    public function dueOn(string $due, ?string $remind = null): self
    {
        return $this->state(fn (): array => [
            'due_at' => $due,
            'remind_at' => $remind,
        ]);
    }

    public function overdue(): self
    {
        return $this->state(fn (): array => ['due_at' => now()->subDay()]);
    }

    public function done(): self
    {
        return $this->state(fn (): array => [
            'status' => LeadTaskStatus::Done,
            'completed_at' => now(),
        ]);
    }

    public function assignedTo(int $userId): self
    {
        return $this->state(fn (): array => ['assigned_to' => $userId]);
    }
}
