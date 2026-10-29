<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Support\Facades\DB;

/**
 * Ticks a task off, or puts it back (Phase 10b).
 *
 * Idempotent: completing an already-done task changes nothing and records nothing, so a double-tap
 * on a phone doesn't put two "Task done" entries on the lead. Re-opening clears the completion but
 * leaves the timeline entry — the task genuinely was done at that moment, and history isn't edited.
 *
 * Must be called inside the task's team context.
 */
final class CompleteLeadTaskAction
{
    public function __construct(
        private readonly RecordLeadActivityAction $record,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(LeadTask $task, User $actor, bool $done = true): LeadTask
    {
        $target = $done ? LeadTaskStatus::Done : LeadTaskStatus::Open;

        if ($task->status === $target) {
            return $task;
        }

        return DB::transaction(function () use ($task, $actor, $done, $target): LeadTask {
            $task->forceFill([
                'status' => $target,
                'completed_at' => $done ? now() : null,
                'completed_by' => $done ? $actor->getKey() : null,
            ])->save();

            if ($done) {
                /** @var Lead $lead */
                $lead = $task->lead()->firstOrFail();

                $this->record->handle(
                    lead: $lead,
                    type: LeadActivityType::TaskCompleted,
                    subject: $task,
                    properties: ['title' => $task->title],
                    actor: $actor,
                );
            }

            $this->audit->log($done ? 'leads.task_completed' : 'leads.task_reopened', $task, [
                'lead_id' => $task->lead_id,
            ], $actor);

            return $task;
        });
    }
}
