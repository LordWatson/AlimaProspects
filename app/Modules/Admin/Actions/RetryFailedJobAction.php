<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push a failed job back onto its queue, or every failed job at once (Phase 19c).
 *
 * **Through `queue:retry`, not by hand.** Retrying means writing the payload back to the queue with
 * a fresh uuid and deleting the `failed_jobs` row, and the driver decides how: the database failer
 * and Redis do it differently, and a batched job has to be re-registered with its batch. Laravel's
 * command is the one implementation that knows all of that, and reimplementing it here would be a
 * second way to put work on a queue — the thing CLAUDE.md's single-write-path rule is about.
 *
 * **No transaction.** There is deliberately nothing to wrap: `queue:retry` writes to the queue and
 * removes the failed row itself, and opening a transaction around it would mean an external push
 * inside a transaction — the one thing CLAUDE.md §2 forbids outright. The audit entry is written
 * after the retry has happened, so a failed retry leaves no entry claiming it worked.
 *
 * Audited either way, with the job's class and the tenant it belonged to, because a retry can send
 * a real email or spend a real allowance a second time. `retryAll()` records the count rather than
 * one entry per job: the act was "retry everything", and five hundred near-identical rows would
 * bury it.
 */
final class RetryFailedJobAction
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly QueueHealth $health,
    ) {}

    /**
     * @return array<string, mixed> the row as it was, for the confirmation message
     */
    public function handle(string $uuid): array
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first(['uuid', 'queue', 'payload']);

        if ($row === null) {
            // A job somebody else retried a moment ago. A refusal rather than a silent success,
            // because "retried" and "was already gone" are different things to tell an operator.
            throw AdminException::failedJobMissing($uuid);
        }

        // The one row, by uuid. Paging the newest thousand and searching them would quietly lose
        // the job class and the tenant id for anything older than that — on the very screen whose
        // reason to exist is a backlog.
        $details = $this->health->describeFailedJob($uuid) ?? [];

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $this->audit->record(AdminAudit::JOB_RETRIED, null, [
            'uuid' => $uuid,
            'queue' => (string) $row->queue,
            'job' => $details['job'] ?? null,
            // The tenant the job belonged to, which is the field this whole screen exists for.
            'subject_team_id' => $details['team_id'] ?? null,
        ]);

        Log::channel('billing')->info('Failed job retried from the admin panel.', [
            'uuid' => $uuid,
            'queue' => (string) $row->queue,
        ]);

        return $details;
    }

    /**
     * Retry everything in `failed_jobs`.
     *
     * The count is read **before** the command runs: afterwards the table is empty, and an entry
     * saying "0 jobs retried" would be the opposite of what happened.
     */
    public function retryAll(): int
    {
        $count = (int) DB::table('failed_jobs')->count();

        if ($count === 0) {
            return 0;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->audit->record(AdminAudit::JOB_RETRIED, null, [
            'uuid' => 'all',
            'count' => $count,
        ]);

        return $count;
    }
}
