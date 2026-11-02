<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\QueueHealth;
use Illuminate\Support\Facades\DB;

/**
 * Drop a failed job without retrying it (Phase 19c).
 *
 * The right call for a job that will never succeed — a lead whose team has since been deleted, a
 * send to an address that is now suppressed — and it is **irreversible**: the payload is the only
 * copy of that work. So the audit entry is written **before** the row is deleted, inside the same
 * transaction, carrying the job class and the tenant it belonged to. Writing it afterwards would
 * mean a crash between the two left the work gone and the trail silent, which is the one ordering
 * that cannot be recovered from.
 *
 * `failed_jobs` is a framework table with no module and no Action watching it, so the delete is
 * written here rather than offered through the generic resource writer — the same reasoning that
 * keeps generic delete opt-in per resource (`AdminResource` rule 3).
 */
final class DeleteFailedJobAction
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
        $exists = DB::table('failed_jobs')->where('uuid', $uuid)->exists();

        if (! $exists) {
            throw AdminException::failedJobMissing($uuid);
        }

        $details = $this->describe($uuid);

        DB::transaction(function () use ($uuid, $details): void {
            // The entry first. See the class note: the payload is the only copy of this work.
            // The class, the queue, the uuid and the tenant — and **not** the exception text.
            //
            // An exception message routinely carries bound values: a provider error quoting an
            // address, a database message quoting a parameter. The failed-job screen is behind
            // `operations.manage` precisely because that text leaks a tenant's internals, and the
            // audit viewer is behind `audit.read`, which Finance and Read-only also hold — so
            // copying it here would widen the audience for the thing the narrower ability exists
            // to contain. The uuid is enough to find the row while it is still there, and the logs
            // keep the rest.
            $this->audit->record(AdminAudit::JOB_DELETED, null, [
                'uuid' => $uuid,
                'queue' => $details['queue'] ?? null,
                'job' => $details['job'] ?? null,
                'subject_team_id' => $details['team_id'] ?? null,
            ]);

            DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        });

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $uuid): array
    {
        return $this->health->describeFailedJob($uuid) ?? [];
    }
}
