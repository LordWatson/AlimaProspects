<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a {@see SignalWatch} (S5), transactionally and audited.
 *
 * **It takes the watch's un-converted signals with it**, which the screens say before asking.
 * That is deliberate rather than a soft delete: a watch is a standing instruction to spend money
 * on searches, and a team removing one is saying they don't want its results either.
 *
 * **A converted signal is kept.** It is the provenance of a real lead — the lead's detail screen
 * reads it back for its origin panel — and `compliance.md` §5a promises it lives as long as that
 * lead does. Deleting the watch nulls its `signal_watch_id`/`signal_run_id` (the FKs are
 * `nullOnDelete` as of the S6 migration, so the database enforces this however a watch comes to
 * be removed) and the signal keeps everything else. The lead loses the "found by" attribution it
 * no longer has a row for, and nothing else.
 *
 * The audit row records the counts, which is the only place they survive the delete.
 */
final class DeleteSignalWatchAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SignalWatch $watch, User $actor): void
    {
        DB::transaction(function () use ($watch, $actor): void {
            // Counted before the delete, because afterwards there is nothing left to count.
            $kept = $watch->signals()->where('status', SignalStatus::Converted)->count();
            $removable = $watch->signals()->where('status', '!=', SignalStatus::Converted)->count();

            $this->audit->log('signals.watch_deleted', $watch, [
                'location' => $watch->location,
                'cadence' => $watch->cadence->value,
                'signals_deleted' => $removable,
                // The converted signals that outlive the watch, because they are the provenance of
                // leads the team is still working.
                'signals_kept' => $kept,
                'runs_deleted' => $watch->runs()->count(),
            ], $actor);

            // Explicit, so a signal nobody wanted isn't left behind with a null watch. The
            // converted ones are left for the FK to detach.
            $watch->signals()->where('status', '!=', SignalStatus::Converted)->delete();

            $watch->delete();
        });
    }
}
