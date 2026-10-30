<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadTaskData;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Support\Facades\DB;

/**
 * Adds a task to a lead (Phase 10b).
 *
 * `remind_at` is stored but nothing sends it yet — Notifications (Phase 12) sweeps it. It is
 * written now so that a team setting reminders today has them waiting when the sweep arrives,
 * rather than having silently lost them.
 *
 * Must be called inside the lead's team context.
 */
final class CreateLeadTaskAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Lead $lead, User $creator, LeadTaskData $data): LeadTask
    {
        return DB::transaction(function () use ($lead, $creator, $data): LeadTask {
            $task = LeadTask::query()->create([
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                'created_by' => $creator->getKey(),
                // Unassigned means the team owns it, which is a real answer — not every task is
                // one person's.
                'assigned_to' => $data->assignedTo,
                'title' => $data->title,
                'due_at' => $data->dueAt,
                'remind_at' => $data->remindAt,
            ]);

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::TaskCreated,
                subject: $task,
                properties: [
                    'title' => $task->title,
                    'due_at' => $task->due_at?->toIso8601String(),
                ],
                actor: $creator,
            );

            $this->audit->log('leads.task_created', $task, [
                'lead_id' => $lead->getKey(),
                'assigned_to' => $task->assigned_to,
                'has_reminder' => $task->remind_at !== null,
            ], $creator);

            return $task;
        });
    }
}
