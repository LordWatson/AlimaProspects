<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalClusterer;
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
 * **The clusters the deleted signals belonged to are repaired in the same transaction.** A
 * cluster is one event and is keyed by `(team, event_key, subject fingerprint, ISO week)` — not by
 * watch — so two watches covering the same acquisition share one, and taking a watch away removes
 * some of its members without making the cluster wrong to exist. The delete below is a *query*
 * delete, so it fires no model events and nothing downstream notices: left alone, the surviving
 * cluster keeps the `sources_count` it had, which `SignalResource` reads straight through, and
 * loses its `primary_signal_id` entirely if the primary happened to be one of the removed rows.
 * The card then says "6 outlets" over three articles, or `Signal::representatives()` falls back to
 * showing every member and one event becomes several cards. `signals:prune` would put it right the
 * next morning; a team that has just pressed delete and is looking at the feed should not have to
 * wait for that. Same reasoning, and the same helper shape, as the DSAR erasure path.
 *
 * The audit row records the counts, which is the only place they survive the delete.
 */
final class DeleteSignalWatchAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignalClusterer $clusterer,
    ) {}

    public function handle(SignalWatch $watch, User $actor): void
    {
        DB::transaction(function () use ($watch, $actor): void {
            // Counted before the delete, because afterwards there is nothing left to count.
            $kept = $watch->signals()->where('status', SignalStatus::Converted)->count();
            $removable = $watch->signals()->where('status', '!=', SignalStatus::Converted)->count();

            $this->audit->log('signals.watch_deleted', $watch, [
                'locations' => $watch->locations ?? [],
                'regions' => $watch->regions ?? [],
                'cadence' => $watch->cadence->value,
                'signals_deleted' => $removable,
                // The converted signals that outlive the watch, because they are the provenance of
                // leads the team is still working.
                'signals_kept' => $kept,
                'runs_deleted' => $watch->runs()->count(),
            ], $actor);

            // Read before the delete, because afterwards there is no way back to them. Only the
            // rows that are actually going: a converted signal keeps its cluster membership.
            /** @var list<int> $clusterIds */
            $clusterIds = $watch->signals()
                ->where('status', '!=', SignalStatus::Converted)
                ->whereNotNull('signal_cluster_id')
                ->distinct()
                ->pluck('signal_cluster_id')
                ->all();

            // Explicit, so a signal nobody wanted isn't left behind with a null watch. The
            // converted ones are left for the FK to detach.
            $watch->signals()->where('status', '!=', SignalStatus::Converted)->delete();

            $this->repairClusters((int) $watch->team_id, $clusterIds);

            $watch->delete();
        });
    }

    /**
     * Put each affected cluster back in step with the members it has left.
     *
     * A cluster with **nothing** left is deleted rather than reconciled: its `subject_fingerprint`
     * is derived from the rows that have gone, so the row would survive with nothing left to
     * explain it. One with members left is reconciled, which recounts its outlets and picks a new
     * `primary_signal_id` when the old one was among the deleted.
     *
     * Scoped by `team_id` explicitly even though the caller is already tenant-scoped, because that
     * is what makes the query safe to read at a glance.
     *
     * @param  list<int>  $clusterIds
     */
    private function repairClusters(int $teamId, array $clusterIds): void
    {
        if ($clusterIds === []) {
            return;
        }

        $clusters = SignalCluster::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $clusterIds)
            ->get();

        foreach ($clusters as $cluster) {
            $remaining = Signal::query()
                ->where('team_id', $teamId)
                ->where('signal_cluster_id', $cluster->getKey())
                ->count();

            if ($remaining === 0) {
                $cluster->delete();

                continue;
            }

            $this->clusterer->reconcile($cluster);
        }
    }
}
