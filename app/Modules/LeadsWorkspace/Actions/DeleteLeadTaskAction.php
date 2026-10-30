<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Support\Facades\DB;

/**
 * Removes a task (Phase 10b), together with the timeline entries about it — a task that was
 * cancelled rather than done shouldn't leave "Task added" on the lead as if it were still outstanding.
 * The audit trail keeps the fact of the deletion.
 */
final class DeleteLeadTaskAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(LeadTask $task, User $actor): void
    {
        DB::transaction(function () use ($task, $actor): void {
            $this->audit->log('leads.task_deleted', $task, [
                'lead_id' => $task->lead_id,
                'was' => $task->status->value,
            ], $actor);

            LeadActivity::query()
                ->where('team_id', $task->team_id)
                ->where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->getKey())
                ->delete();

            $task->delete();
        });
    }
}
