<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the task reminders a team has set (Phase 12) — the sweep `lead_tasks.remind_at` was built
 * for in Phase 10b and has been storing reminders for, unsent, ever since.
 *
 * Idempotent by the same mechanism the column was designed around: a reminder is only picked up
 * when `reminded_at` is null, and that is stamped **before** the notification is raised. A sweep
 * that crashes half way through, or a scheduler that overlaps itself, can therefore send nothing
 * twice — and the worst case is a reminder stamped but never delivered, which is the right way
 * round for something whose whole purpose is to arrive once.
 *
 * Deliberately **does not** skip a reminder whose window has passed. A member who set a reminder
 * for Friday and whose worker was down until Monday still wants to be told; the task is still open,
 * which is the only test that matters.
 */
final class SendTaskRemindersCommand extends Command
{
    protected $signature = 'notifications:task-reminders
        {--limit=500 : How many reminders to send in one pass}
        {--dry-run : Report what would be sent without sending or stamping anything}';

    protected $description = 'Send due task reminders to the members they belong to.';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        // Read without the team scope: this is a platform sweep across every tenant, and the team
        // is resolved per row so each notification is raised in the right one.
        $due = LeadTask::withoutGlobalScopes()
            ->where('status', LeadTaskStatus::Open->value)
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->whereNull('reminded_at')
            ->with(['lead:id,company_name', 'team'])
            ->orderBy('remind_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No reminders are due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($due as $task) {
            $team = $task->team;
            // An unassigned task is the team's, and the person who set the reminder is the one who
            // wanted it — so it goes to them rather than nowhere.
            $recipientId = $task->assigned_to ?? $task->created_by;

            if (! $team instanceof Team || $recipientId === null) {
                $skipped++;

                continue;
            }

            $recipient = User::query()->find($recipientId);

            if (! $recipient instanceof User) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            // Stamped first. A reminder that is sent twice is worse than one that is stamped and
            // lost: the second is a bug someone can see in the task, the first is us crying wolf.
            $task->forceFill(['reminded_at' => now()])->save();

            // Per task, so one failure is one lost reminder rather than every reminder after it.
            // The sweep is the only thing that will ever send these, and a member whose reminder
            // was simply never reached would have no way of knowing.
            try {
                $dispatcher->send($team, $recipient, NotificationType::TaskDue, [
                    'task_id' => $task->getKey(),
                    'title' => $task->title,
                    'lead_id' => $task->lead_id,
                    'company' => (string) $task->lead->company_name,
                    'due_at' => $task->due_at?->toIso8601String(),
                ]);

                $sent++;
            } catch (Throwable $e) {
                $failed++;

                Log::channel('notifications')->error('Could not send a task reminder.', [
                    'team_id' => $team->getKey(),
                    'task_id' => $task->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }

        Log::channel('notifications')->info('Task reminders swept.', [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'dry_run' => $dryRun,
        ]);

        $this->info($dryRun
            ? "Would send {$sent} reminders (skipping {$skipped})."
            : "Sent {$sent} reminders (skipped {$skipped}, {$failed} failed).");

        return self::SUCCESS;
    }
}
