<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give every existing signal a heat (S12).
 *
 * Two jobs, and both are one-offs an operator runs rather than anything scheduled. On the day this
 * phase ships, every signal a team already has carries `heat = null` — the feed's default order
 * puts those last, so an existing team would open their feed on an empty-looking top and their
 * whole backlog at the bottom. And when somebody retunes `signals.scoring.weights`, the stored
 * scores are the *old* weights' answers until something re-scores them.
 *
 * Deliberately **not scheduled**. A nightly re-score would silently re-rank a team's feed every
 * morning for reasons nobody could see, which is the same objection the tuning advisor exists to
 * answer: a product that quietly retunes itself cannot be debugged. Re-ranking is an operator's
 * act, and `--dry-run` is there because it is a visible one.
 *
 * Chunked, per team, and scoped explicitly on every query — it runs without the tenant scope,
 * which is precisely why each statement names the team. Logs counts only.
 */
final class RescoreSignalsCommand extends Command
{
    protected $signature = 'signals:rescore
        {--dry-run : Report what would be scored without writing anything}
        {--all : Re-score every signal, not only the ones that have never been scored}
        {--team= : One team id, rather than every team}
        {--chunk=500 : Signals per chunk}';

    protected $description = 'Score existing signals so the feed can rank them by heat';

    /**
     * No injected scorer, deliberately.
     *
     * {@see SignalScorer} holds per-team caches — the amount distribution, the source-authority
     * rows, the active ICP — which is what makes scoring a thousand signals two extra queries
     * rather than two thousand. An injected instance would live as long as the console process, so
     * a second invocation in one process (a test, a worker running the command twice) would score
     * against caches built before the first run's own writes (found by `laravel-module-reviewer`).
     * One instance per team is the right lifetime: long enough to be cheap, short enough to be
     * true.
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $chunk = max(50, (int) $this->option('chunk'));
        $only = $this->option('team');
        $teamFilter = is_numeric($only) ? (int) $only : null;

        $scored = 0;
        $teams = $this->teams($teamFilter, $all);

        foreach ($teams as $teamId) {
            $scored += $this->scoreTeam($teamId, $all, $dryRun, $chunk);
        }

        $context = [
            'teams' => count($teams),
            'scored' => $scored,
            'all' => $all,
            'dry_run' => $dryRun,
        ];

        Log::channel('signals')->info('Signals rescore finished.', $context);

        $this->info(sprintf(
            '%s %d signal(s) across %d team(s).',
            $dryRun ? 'Would score' : 'Scored',
            $scored,
            count($teams),
        ));

        return self::SUCCESS;
    }

    /**
     * Score one team's signals.
     *
     * **One scorer instance for the whole team**, which is the difference between this finishing
     * and this hammering the database: the scorer reads the team's amount distribution and its
     * source-authority rows once and holds them, so a thousand signals are two extra queries
     * rather than two thousand.
     *
     * The definitions and the clusters are pre-loaded for the same reason. `preventLazyLoading` is
     * on outside production, so a forgotten one would throw rather than quietly cost a query per
     * row — but this command runs in production too, where it would simply be slow.
     */
    private function scoreTeam(int $teamId, bool $all, bool $dryRun, int $chunk): int
    {
        // A fresh scorer per team: see the constructor.
        $scorer = app(SignalScorer::class);

        /** @var array<int, SignalDefinition> $definitions */
        $definitions = SignalDefinition::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withTrashed()
            ->get()
            ->keyBy('id')
            ->all();

        /** @var array<int, SignalCluster> $clusters */
        $clusters = SignalCluster::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->keyBy('id')
            ->all();

        $scored = 0;

        Signal::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when(! $all, static fn ($query) => $query->whereNull('heat'))
            ->orderBy('id')
            ->chunkById($chunk, function ($signals) use ($scorer, $definitions, $clusters, $dryRun, &$scored): void {
                foreach ($signals as $signal) {
                    $scored++;

                    if ($dryRun) {
                        continue;
                    }

                    $score = $scorer->score(
                        $signal,
                        $signal->signal_definition_id === null ? null : ($definitions[(int) $signal->signal_definition_id] ?? null),
                        $signal->signal_cluster_id === null ? null : ($clusters[(int) $signal->signal_cluster_id] ?? null),
                    );

                    // A targeted update rather than a model save: nothing else about the row is
                    // being changed, and a `save()` would touch `updated_at` on every signal a
                    // team owns — which is the column the retention sweep and the API's
                    // `If-Modified-Since` behaviour both read.
                    Signal::query()
                        ->withoutGlobalScopes()
                        ->whereKey($signal->getKey())
                        ->update([
                            'heat' => $score->heat,
                            'score_breakdown' => json_encode($score->toBreakdown(), JSON_UNESCAPED_UNICODE),
                        ]);
                }
            });

        return $scored;
    }

    /**
     * The teams with signals worth scoring.
     *
     * Without the tenant scope on purpose — a system job across every team, reachable only from
     * the console — which is exactly why every query above names its `team_id`.
     *
     * @return list<int>
     */
    private function teams(?int $only, bool $all): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('signals')
            ->when($only !== null, static fn ($query) => $query->where('team_id', $only))
            ->when(! $all, static fn ($query) => $query->whereNull('heat'))
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
