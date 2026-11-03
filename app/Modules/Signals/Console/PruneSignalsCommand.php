<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The Signals retention sweep (S6, ADR 0011 / `docs/compliance.md` §5a): hard-delete signals the
 * team has had long enough to act on and hasn't.
 *
 * The windows come from {@see SignalRetention}, which is also what tells the feed "deletes in N
 * days" — so the promise on screen and the rule the sweep applies are the same rule. Dismissed
 * signals go after 90 days (the team said no, and the reason is already in the audit log, which is
 * what tuning reads); unactioned ones after 180 (a six-month-old funding round is not a trigger
 * event any more, and keeping a named person's details because nobody clicked is exactly the
 * retention a data-minimisation policy exists to prevent).
 *
 * **Converted signals are never swept.** `SignalRetention::sweptStatuses()` doesn't return that
 * status and `eligible()` refuses to build a query for it, so there is no way to write a caller
 * here that deletes one.
 *
 * **Since S11 it sweeps three tables.** `signal_rejections` goes on the same 30-day clock the run
 * honours a rejection for ({@see SignalRetention::eligibleRejections()}), and a `signal_clusters`
 * row goes when it has no signals left — a cluster has no window of its own, so emptying is its
 * only expiry, and that is what stops a subject fingerprint outliving every row it came from.
 *
 * The sweep is **idempotent** (a second run finds nothing to do), **chunked** (a year of a busy
 * team's signals is not one `DELETE ... WHERE`), runs **without the tenant scope on purpose** — it
 * is a system job across every team, reachable only from the console — and logs **counts only**,
 * never a headline or a name.
 */
final class PruneSignalsCommand extends Command
{
    /**
     * `--dry-run` reports what it would delete and deletes nothing. Retention is irreversible, so
     * it should be possible to see the damage before doing it (the plan's own risk note).
     */
    protected $signature = 'signals:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete dismissed and unactioned signals past their retention window';

    /** Rows per delete, so one sweep can't hold a long lock or exhaust memory. */
    private const int CHUNK = 500;

    public function __construct(
        private readonly SignalRetention $retention,
        private readonly SignalClusterer $clusterer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = [];

        foreach ($this->retention->sweptStatuses() as $status) {
            $counts[$status->value] = $this->prune($status, $dryRun);
        }

        // S11's two tables, swept **after** the signals, in that order for a reason: a cluster is
        // eligible once its last signal has gone, so sweeping it first would leave behind exactly
        // the clusters this run was about to empty.
        //
        // The repair runs **before** the delete, and between them they cover the two states a
        // sweep can leave a cluster in: short of its primary (give it a new one) or short of
        // every member (take the row).
        $repaired = $this->repairClusters($dryRun);
        $rejections = $this->pruneRejections($dryRun);
        $clusters = $this->pruneClusters($dryRun);

        $context = [
            'dismissed_deleted' => $counts[SignalStatus::Dismissed->value] ?? 0,
            'unactioned_deleted' => $counts[SignalStatus::New->value] ?? 0,
            'rejections_deleted' => $rejections,
            'clusters_deleted' => $clusters,
            'clusters_repaired' => $repaired,
            'dismissed_days' => $this->retention->windowFor(SignalStatus::Dismissed),
            'unactioned_days' => $this->retention->windowFor(SignalStatus::New),
            'dry_run' => $dryRun,
        ];

        // Counts and windows only — never a headline, a name or a team's content.
        Log::channel('signals')->info('Signals retention sweep finished.', $context);

        // Two sentences rather than one long list: the signals are what an operator came to read,
        // and S11's two housekeeping tables are a footnote to them.
        $this->info(sprintf(
            '%s %d dismissed and %d unactioned signal(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $context['dismissed_deleted'],
            $context['unactioned_deleted'],
        ));

        $this->info(sprintf(
            '%s %d rejection memory(ies) and %d empty cluster(s), and %s %d cluster(s) a new primary.',
            $dryRun ? 'Would also delete' : 'Also deleted',
            $rejections,
            $clusters,
            $dryRun ? 'would give' : 'gave',
            $repaired,
        ));

        return self::SUCCESS;
    }

    /**
     * Give a new primary to every cluster that lost one to the sweep (S11).
     *
     * Not a deletion, which is why it is the one step `--dry-run` reports without doing: a cluster
     * short of its primary shows all of its members as separate cards until this runs, and that is
     * a visible fault rather than a silent one — but it is still a fault, and leaving it to the
     * next run that happens to touch the cluster would mean never, for a story nobody writes about
     * again.
     *
     * Chunked like the deletes, and the reconcile is read-through-then-write, so a member converted
     * between the two simply becomes the primary the next pass picks.
     */
    private function repairClusters(bool $dryRun): int
    {
        $eligible = $this->retention->clustersNeedingAPrimary();

        if ($dryRun) {
            return (clone $eligible)->count();
        }

        $repaired = 0;
        $after = 0;

        while (true) {
            /** @var list<SignalCluster> $clusters */
            $clusters = (clone $eligible)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get()
                ->all();

            if ($clusters === []) {
                break;
            }

            foreach ($clusters as $cluster) {
                $after = (int) $cluster->getKey();
                $this->clusterer->reconcile($cluster);
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * Delete the rejection memories past their window (S11).
     *
     * Same chunk-then-delete-through-the-constraint shape as {@see self::prune()}: the guard is
     * re-applied at the `DELETE`, so a memory a run refreshed between the read and the write is not
     * deleted out from under it.
     */
    private function pruneRejections(bool $dryRun): int
    {
        $eligible = $this->retention->eligibleRejections();

        if ($dryRun) {
            return (clone $eligible)->count();
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $eligible)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $removed = (clone $eligible)->whereIn('id', $ids)->delete();

            if ($removed === 0) {
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }

    /**
     * Delete clusters with no signals left (S11).
     *
     * A cluster has no window of its own — it is not a record about a person or an article, it is
     * the fact that several articles were one event — so it goes when the last of them does. That
     * is also what keeps `subject_fingerprint` from outliving every row it was derived from, which
     * is the only reason this sweep is a compliance matter rather than tidiness.
     */
    private function pruneClusters(bool $dryRun): int
    {
        $eligible = $this->retention->orphanedClusters();

        if ($dryRun) {
            return (clone $eligible)->count();
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $eligible)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $removed = (clone $eligible)->whereIn('id', $ids)->delete();

            if ($removed === 0) {
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }

    /**
     * Delete every signal in `$status` past its window, in chunks.
     *
     * The ids are read and then deleted **through the same constraint**, not by id alone. Between
     * the read and the delete a member can convert or restore a signal, and a plain
     * `whereIn('id', …)` would delete it anyway — taking the provenance of a brand-new lead with
     * it. Re-applying the status and age guards at the `DELETE` closes that window, and means the
     * "converted is never deleted" guarantee is enforced where the rows actually go.
     */
    private function prune(SignalStatus $status, bool $dryRun): int
    {
        $eligible = $this->retention->eligible($status);

        if ($eligible === null) {
            return 0; // Never swept — nothing to do, and nothing a caller can force.
        }

        if ($dryRun) {
            // One count, not a loop: nothing is being removed, so there is no shrinking set to
            // chunk through — and a chunked dry run would either under-report or never finish.
            return (clone $eligible)->count();
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $eligible)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $removed = (clone $eligible)->whereIn('id', $ids)->delete();

            if ($removed === 0) {
                // Every row in this chunk was acted on by a member in the meantime. Stop rather
                // than spin: the next nightly sweep will pick up whatever is still eligible.
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }
}
