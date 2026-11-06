<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalItemNormaliser;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cluster the signals a team already has (S11), so the feed reads the same on the day this ships as
 * it will on the day after.
 *
 * Without it, clustering only applies to signals found from now on: a team opens the feed to
 * yesterday's eight cards about one acquisition and the feature looks like it does not work. It also
 * fills `content_hash`, which the migration deliberately left null — deriving it needs
 * {@see SignalItemNormaliser}, and a migration that calls application code is a migration that
 * breaks the day that code changes.
 *
 * **And it repoints `dedupe_hash` on the rows that came in behind a redirector**, which is the half
 * that is easy to miss (found in review). The migration copied `url_hash` into `dedupe_hash` for
 * every existing row, which was right — that is what the old unique index enforced — but for a
 * `google.com/goto?url=<token>` link that hash is a hash of the *token*. The first run after this
 * phase ships computes a content-based identity for the same article, sees no match, and re-admits
 * it once as a new signal, paying for a classification again. Run this before the next watch and
 * that does not happen. Where repointing would collide with a row that already holds the content
 * hash, the row is **left alone and counted**: those two rows are a duplicate pair that predates
 * this phase, and merging a team's signals is not a decision a backfill should take quietly.
 *
 * Five properties, each of them load-bearing:
 *
 * - **Newest 90 days only** (`--days`). Clustering an archive nobody will look at costs the same as
 *   clustering the feed and buys nothing, and an older signal is past its retention window anyway.
 * - **Idempotent.** Every key is recomputed from the stored columns and found-or-created, so running
 *   it twice is the same as running it once, and running it after a week of normal operation simply
 *   finds the clusters the runs already made.
 * - **It never merges a converted signal away.** A converted signal is the provenance of a real
 *   lead — its `lead_id` is the only copy of that fact — so it is left out entirely and stays its
 *   own card. Folding it into a cluster whose primary is a different article would hide the lead
 *   behind one that never made it.
 * - **Chunked, and per team.** The whole point of the tenancy scope is that a cluster never
 *   collects two teams' signals, so the loop is over teams and every query names the id.
 * - **`--dry-run` changes nothing** and reports what it would do. Clustering is reversible in
 *   principle and awkward in practice; seeing the damage first is cheap.
 *
 * Logs counts only — never a headline, an outlet or a name.
 */
final class ClusterSignalsBackfillCommand extends Command
{
    protected $signature = 'signals:cluster-backfill
        {--dry-run : Report what would be clustered without writing anything}
        {--days=90 : How far back to go, by publication date}
        {--chunk=500 : Signals per chunk}';

    protected $description = 'Group existing signals into clusters and fill their content hashes';

    public function __construct(
        private readonly SignalClusterer $clusterer,
        private readonly SignalItemNormaliser $normaliser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $chunk = max(50, (int) $this->option('chunk'));
        $since = now()->subDays($days);

        $clustered = 0;
        $hashed = 0;
        $repointed = 0;
        $collided = 0;
        // `$touched` is this team's pass and is emptied after each reconcile; `$everyCluster` is
        // the whole run's, because the report at the end has to count what it did rather than
        // what the last team happened to leave behind (which is zero, every time — found by
        // running it).
        /** @var array<int, true> $touched */
        $touched = [];
        /** @var array<int, true> $everyCluster */
        $everyCluster = [];
        // A dry run writes nothing, so it has no cluster ids to count — but it can still answer
        // "how many events is that?", by applying the same own-week-then-previous-week join in
        // memory. Reporting `0 clusters` while promising to group eight signals was a sentence that
        // read like a bug (found by running it).
        /** @var array<string, true> $wouldCluster */
        $wouldCluster = [];

        $teams = $this->teams($since);

        foreach ($teams as $teamId) {
            // Oldest first, and that is the same requirement the run has: a cluster key is looked
            // up against its own ISO week and the week before, so a story that broke on a Sunday
            // and was covered on the Monday is one event only if the Sunday article is filed first.
            Signal::query()
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('published_at', '>=', $since)
                // A converted signal keeps its own card: see the class docblock.
                ->where('status', '!=', SignalStatus::Converted->value)
                ->whereNull('signal_cluster_id')
                ->orderBy('published_at')
                ->orderBy('id')
                ->select([
                    'id', 'team_id', 'signal_definition_id', 'event_key', 'status',
                    'headline', 'source_name', 'subject_company_name', 'published_at',
                    'content_hash', 'dedupe_hash', 'source_url', 'confidence',
                ])
                ->chunkById($chunk, function ($signals) use ($teamId, $dryRun, &$clustered, &$hashed, &$repointed, &$collided, &$touched, &$everyCluster, &$wouldCluster): void {
                    foreach ($signals as $signal) {
                        $hashed += $this->fillContentHash($signal, $dryRun);

                        [$moved, $clash] = $this->repointDedupeHash($signal, $dryRun);
                        $repointed += $moved;
                        $collided += $clash;

                        $fingerprint = $this->clusterer->subjectFingerprint($signal->subject_company_name);
                        $published = $signal->published_at;

                        if ($fingerprint === '' || $published === null) {
                            continue;
                        }

                        $clustered++;

                        if ($dryRun) {
                            $candidates = $this->clusterer->candidateKeysFor(
                                $signal->event_key,
                                $fingerprint,
                                $published,
                            );

                            // Its own week, else the week before — the order `ensure()` takes them
                            // in, so the count is the one the real run would produce.
                            $joined = false;

                            foreach ($candidates as $candidate) {
                                if (isset($wouldCluster[$teamId.':'.$candidate])) {
                                    $joined = true;

                                    break;
                                }
                            }

                            if (! $joined && $candidates !== []) {
                                $wouldCluster[$teamId.':'.$candidates[0]] = true;
                            }

                            continue;
                        }

                        $cluster = $this->clusterer->ensure(
                            teamId: $teamId,
                            eventKey: $signal->event_key,
                            definitionId: $signal->signal_definition_id,
                            fingerprint: $fingerprint,
                            publishedAt: $published,
                        );

                        if (! $cluster instanceof SignalCluster) {
                            continue;
                        }

                        Signal::query()
                            ->withoutGlobalScopes()
                            ->whereKey($signal->getKey())
                            ->update(['signal_cluster_id' => $cluster->getKey()]);

                        // Ids, not models: a backfill over a busy install would otherwise hold
                        // every cluster it has ever touched in memory for the whole run.
                        $touched[(int) $cluster->getKey()] = true;
                        $everyCluster[(int) $cluster->getKey()] = true;
                    }
                });

            // Reconciled once this team's members are all filed, not as each one lands: a
            // cluster's primary is chosen from the members it has, and picking one half-way
            // through would be a choice made from an incomplete set and then overwritten.
            //
            // **Per team rather than at the very end**, so a crash part-way through a long
            // backfill leaves the teams it finished correct rather than leaving every cluster it
            // created with no primary and a `sources_count` of zero (found in review).
            $this->reconcile($touched);
            $touched = [];
        }

        $clusters = $dryRun ? count($wouldCluster) : count($everyCluster);

        $context = [
            'teams' => count($teams),
            'clustered' => $clustered,
            'content_hashes' => $hashed,
            'dedupe_hashes_repointed' => $repointed,
            'dedupe_hash_collisions' => $collided,
            'clusters' => $clusters,
            'days' => $days,
            'dry_run' => $dryRun,
        ];

        Log::channel('signals')->info('Signals cluster backfill finished.', $context);

        $this->info(sprintf(
            '%s %d signal(s) into %d cluster(s), and filled %d content hash(es).',
            $dryRun ? 'Would group' : 'Grouped',
            $clustered,
            $clusters,
            $hashed,
        ));

        if ($repointed > 0 || $collided > 0) {
            $this->info(sprintf(
                '%s %d redirected signal(s) onto their content identity (%d left alone as an existing duplicate).',
                $dryRun ? 'Would repoint' : 'Repointed',
                $repointed,
                $collided,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Move a redirected row's `dedupe_hash` onto its content identity.
     *
     * The migration gave every existing row `dedupe_hash = url_hash`, which for a redirector link
     * is a hash of an opaque token. Left alone, the first run after this phase computes the content
     * identity for the same article, matches nothing, and re-admits it once.
     *
     * Returns `[moved, collided]`. A collision means another row already holds that content
     * identity for this team — the two rows are a duplicate pair that predates this phase, and
     * merging a team's signals is not a decision a backfill should take quietly, so the row is left
     * as it is and counted.
     *
     * @return array{0: int, 1: int}
     */
    private function repointDedupeHash(Signal $signal, bool $dryRun): array
    {
        if (! $this->normaliser->isRedirector($signal->source_url)) {
            return [0, 0];
        }

        $content = $signal->content_hash
            ?? $this->normaliser->contentHash($signal->headline, $signal->source_name);

        if ($content === null || $content === $signal->dedupe_hash) {
            return [0, 0];
        }

        $taken = Signal::query()
            ->withoutGlobalScopes()
            ->where('team_id', (int) $signal->team_id)
            ->where('dedupe_hash', $content)
            ->whereKeyNot($signal->getKey())
            ->exists();

        if ($taken) {
            return [0, 1];
        }

        if (! $dryRun) {
            Signal::query()
                ->withoutGlobalScopes()
                ->whereKey($signal->getKey())
                ->update(['dedupe_hash' => $content]);
        }

        return [1, 0];
    }

    /**
     * Reconcile the clusters this pass touched, by id.
     *
     * @param  array<int, true>  $ids
     */
    private function reconcile(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        SignalCluster::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_keys($ids))
            ->chunkById(200, function ($clusters): void {
                foreach ($clusters as $cluster) {
                    $this->clusterer->reconcile($cluster);
                }
            });
    }

    /**
     * Derive and store the content hash where a row has none.
     *
     * Returns 1 when a hash was (or would have been) written, so the caller can report how much of
     * the backfill was this half of it.
     */
    private function fillContentHash(Signal $signal, bool $dryRun): int
    {
        if ($signal->content_hash !== null) {
            return 0;
        }

        $hash = $this->normaliser->contentHash($signal->headline, $signal->source_name);

        if ($hash === null) {
            return 0;
        }

        if (! $dryRun) {
            Signal::query()
                ->withoutGlobalScopes()
                ->whereKey($signal->getKey())
                ->update(['content_hash' => $hash]);
        }

        return 1;
    }

    /**
     * The teams with signals in the window, read once.
     *
     * **Without the tenant scope on purpose** — this is a system job across every team, reachable
     * only from the console — which is exactly why the loop then names each `team_id` explicitly:
     * removing the scope and then writing a query that relied on it is how a cluster would come to
     * hold two tenants' signals.
     *
     * @return list<int>
     */
    private function teams(DateTimeInterface $since): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('signals')
            ->where('published_at', '>=', $since)
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
