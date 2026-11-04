<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalRetention;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * **Since S16 it sweeps every table the feature has.** Five more — `signal_previews`,
 * `signal_runs`, `signal_definition_spend`, `signal_playbook_runs`, `signal_source_stats` — plus
 * the watchlist entries whose lead has gone. Which window each one gets, and why they are not all
 * the same length, is in {@see SignalRetention} and `config/signals.php`; the short version is that
 * records about people get the shortest window the product can live with, cost history is kept in
 * arrears, and working state is swept when the thing it was about goes. `signal_inbound_events`
 * (S13b) is the seventh and already had its own.
 *
 * **Every sweep goes through one helper** ({@see self::sweep()}), and that is the fix for the one
 * bug this command has already had: reading ids and then deleting **by id alone** deleted rows a
 * member had acted on between the two statements. The guards are re-applied at the `DELETE`, once,
 * where every caller gets them — rather than in six copies of the same loop where the seventh
 * would be the one that forgot.
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
        $inbound = $this->pruneInboundEvents($dryRun);

        // S16's tables. **Playbook logs before signals would have been wrong**, which is why they
        // are here rather than above: the log cascades with its signal, so sweeping it first would
        // count rows the signal sweep was about to take anyway and report the same deletion twice.
        // The spend rows go before the runs for no ordering reason at all — they are independent —
        // but in the order somebody reads them.
        $previews = $this->sweep($this->retention->eligiblePreviews(), $dryRun);
        $playbookRuns = $this->sweep($this->retention->eligiblePlaybookRuns(), $dryRun);
        $sourceStats = $this->sweep($this->retention->eligibleSourceStats(), $dryRun);
        $spend = $this->sweep($this->retention->eligibleSpend(), $dryRun);
        $runs = $this->sweep($this->retention->eligibleRuns(), $dryRun);
        $entries = $this->pruneWatchlistEntries($dryRun);

        $context = [
            'dismissed_deleted' => $counts[SignalStatus::Dismissed->value] ?? 0,
            'unactioned_deleted' => $counts[SignalStatus::New->value] ?? 0,
            'rejections_deleted' => $rejections,
            'clusters_deleted' => $clusters,
            'clusters_repaired' => $repaired,
            'inbound_events_deleted' => $inbound,
            'previews_deleted' => $previews,
            'playbook_runs_deleted' => $playbookRuns,
            'source_stats_deleted' => $sourceStats,
            'spend_rows_deleted' => $spend,
            'runs_deleted' => $runs,
            'watchlist_entries_deleted' => $entries,
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
            '%s %d rejection memory(ies), %d empty cluster(s) and %d inbound event(s), and %s %d cluster(s) a new primary.',
            $dryRun ? 'Would also delete' : 'Also deleted',
            $rejections,
            $clusters,
            $inbound,
            $dryRun ? 'would give' : 'gave',
            $repaired,
        ));

        // A third sentence rather than a longer second one: S16's six tables are housekeeping and
        // cost history, and an operator reading this came for the signals.
        $this->info(sprintf(
            '%s %d preview(s), %d playbook log(s), %d source tally(ies), %d spend row(s), %d run(s) and %d orphaned watchlist entry(ies).',
            $dryRun ? 'Would also delete' : 'Also deleted',
            $previews,
            $playbookRuns,
            $sourceStats,
            $spend,
            $runs,
            $entries,
        ));

        return self::SUCCESS;
    }

    /**
     * Sweep inbound webhook events past their retention window (S13b).
     *
     * **Kept longer than the source reads them**, and the gap is the point:
     * `signals.sources.webhook.window_days` (7) is how long an event can still become a signal, and
     * this window (30) is how long an operator can still answer "what did they actually send us?"
     * about one that did. Deleting on the source's window would destroy the evidence at exactly the
     * moment somebody starts asking about it.
     *
     * The row is swept whatever became of it. A classified event already exists as a `Signal` with
     * its own retention, and the inbound row is only ever the record of the request — so removing it
     * never removes a signal, and `signals.payload` on the signal keeps what the classifier read.
     */
    private function pruneInboundEvents(bool $dryRun): int
    {
        $days = max(1, (int) config('signals.sources.webhook.retention_days', 30));

        return $this->sweep(
            SignalInboundEvent::query()
                ->withoutGlobalScopes()
                ->where('received_at', '<', now()->subDays($days)),
            $dryRun,
        );
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
     * Same chunk-then-delete-through-the-constraint shape as every other sweep here, which since
     * S16 is literally the same code: the guard is re-applied at the `DELETE`, so a memory a run
     * refreshed between the read and the write is not deleted out from under it.
     */
    private function pruneRejections(bool $dryRun): int
    {
        return $this->sweep($this->retention->eligibleRejections(), $dryRun);
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
        return $this->sweep($this->retention->orphanedClusters(), $dryRun);
    }

    /**
     * Sweep the watchlist entries whose lead has gone, and **put the lists' counters right** (S16,
     * `/code-review`).
     *
     * The one sweep in this command with a second write to make. `signal_watchlists.entries_count`
     * is a cached figure every other delete path maintains, and the generic {@see self::sweep()}
     * issues a bulk `DELETE` that touches no counter — so without this a list would report its
     * old size for ever. "For ever" is literal here rather than rhetorical: this sweep exists for
     * the **paused** synced list `SyncPipelineWatchlistAction` returns early on, which is precisely
     * the path that never recomputes.
     *
     * The parent ids are read **before** the delete, because afterwards there is no row to read
     * them off, and they are read with no tenant scope for the same reason the sweep has none.
     */
    private function pruneWatchlistEntries(bool $dryRun): int
    {
        $listIds = $this->retention
            ->orphanedWatchlistEntries()
            ->pluck('signal_watchlist_id')
            ->map(static fn (mixed $id): int => (int) $id)
            // `values()` before `all()`, for the reason the exporter's twin says.
            ->values()
            ->all();

        $deleted = $this->sweep($this->retention->orphanedWatchlistEntries(), $dryRun);

        if (! $dryRun && $deleted > 0) {
            SignalWatchlist::recountEntries($listIds);
        }

        return $deleted;
    }

    /**
     * Delete every row a query says is eligible, in chunks, **re-applying the query at the
     * `DELETE`** (S16).
     *
     * One helper rather than a loop per table, and the reason is the bug it prevents rather than
     * the lines it saves. Reading ids and then deleting by id alone is wrong on every table here:
     * between the two statements a member can convert a signal, a run can refresh a rejection
     * memory, a sync can adopt a watchlist entry — and a plain `whereIn('id', …)` would delete it
     * anyway. Passing the eligibility query *back into* the delete closes that window, and doing it
     * in one place means the next table to be swept gets it without anybody remembering.
     *
     * The chunk is ordered by id and the delete is counted: a chunk whose every row was acted on in
     * the meantime removes nothing, which stops the loop rather than spinning it. Whatever is still
     * eligible is picked up by the next nightly sweep.
     *
     * `--dry-run` is one `count()`, not a loop — nothing is being removed, so there is no shrinking
     * set to chunk through, and a chunked dry run would either under-report or never finish.
     *
     * @param  Builder<covariant Model>  $eligible
     */
    private function sweep(Builder $eligible, bool $dryRun): int
    {
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

        return $this->sweep($eligible, $dryRun);
    }
}
