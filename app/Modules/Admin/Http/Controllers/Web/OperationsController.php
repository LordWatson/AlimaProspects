<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Actions\DeleteFailedJobAction;
use App\Modules\Admin\Actions\RetryFailedJobAction;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\QueueHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Failed jobs, queues and schedule health (Phase 19c) — diagnosing and clearing a stuck queue
 * without a shell.
 *
 * Thin: one service for the reads, one Action per write, each audited inside the Action rather than
 * here. The retry and delete routes are `POST`/`DELETE` on a **uuid**, not on the numeric id: the
 * uuid is what `queue:retry` takes and what survives a retry-and-fail cycle, and a numeric id would
 * be reused by the next failure.
 */
final class OperationsController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly QueueHealth $health,
        private readonly AdminAudit $audit,
    ) {}

    public function index(Schedule $schedule): Response
    {
        $failed = $this->health->failedJobs();

        // Audited. The failed-job rows carry tenant ids dug out of a payload and a provider's own
        // error text, which is why the route is behind `operations.manage` — and reading somebody
        // else's records is a privacy event whether it arrived through the registry or through
        // here. A count, not the rows.
        $this->audit->record(AdminAudit::RESOURCE_VIEWED, null, [
            'resource' => 'failed-jobs',
            'results' => $failed['total'],
        ]);

        return Inertia::render('admin/operations/Index', [
            'navigation' => $this->registry->navigation(),
            'failed' => $failed,
            'queues' => $this->health->queues(),
            // Read from the live Schedule instance, so a command any module adds in its own
            // provider appears here with no change to this file.
            'schedule' => $this->health->schedule($schedule),
            'pulse' => $this->health->pulse(),
        ]);
    }

    public function retry(string $uuid, RetryFailedJobAction $action): RedirectResponse
    {
        $job = $action->handle($uuid);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ($job['job'] ?? 'The job').' is back on the '.($job['queue'] ?? 'default').' queue.',
        ]);

        return to_route('admin.operations.index');
    }

    public function retryAll(RetryFailedJobAction $action): RedirectResponse
    {
        $count = $action->retryAll();

        Inertia::flash('toast', [
            'type' => $count === 0 ? 'info' : 'success',
            'message' => $count === 0
                ? 'There were no failed jobs to retry.'
                : $count.' '.($count === 1 ? 'job' : 'jobs').' back on their queues.',
        ]);

        return to_route('admin.operations.index');
    }

    public function destroy(string $uuid, DeleteFailedJobAction $action): RedirectResponse
    {
        $job = $action->handle($uuid);

        Inertia::flash('toast', [
            'type' => 'success',
            // Said plainly, because it is: the payload was the only copy of that work.
            'message' => ($job['job'] ?? 'The job').' was dropped. The work it carried is gone.',
        ]);

        return to_route('admin.operations.index');
    }
}
