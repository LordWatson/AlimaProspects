<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Console;

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Services\InsightRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The insights retention sweep (Phase 14): delete insights past their window.
 *
 * The windows come from {@see InsightRetention}, which is also what tells the feed "clears in N
 * days" — so the promise on screen and the rule the sweep applies are the same rule.
 *
 * Idempotent (a second run finds nothing), chunked (a year of a busy team's insights is not one
 * `DELETE ... WHERE`), run **without the tenant scope on purpose** — it is a system job across
 * every team, reachable only from the console — and it logs **counts only**, never a headline or a
 * company name.
 */
final class PruneInsightsCommand extends Command
{
    /** Retention is irreversible, so it must be possible to see the damage before doing it. */
    protected $signature = 'insights:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete insights past their retention window';

    /** Rows per delete, so one sweep can't hold a long lock or exhaust memory. */
    private const int CHUNK = 500;

    public function __construct(private readonly InsightRetention $retention)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = [];

        foreach ($this->retention->sweptStatuses() as $status) {
            $counts[$status->value] = $this->prune($status, $dryRun);
        }

        $dismissed = $counts[InsightStatus::Dismissed->value] ?? 0;
        $unactioned = $counts[InsightStatus::New->value] ?? 0;

        Log::channel('insights')->info('Insights retention sweep finished.', [
            'dismissed_deleted' => $dismissed,
            'unactioned_deleted' => $unactioned,
            'dismissed_days' => $this->retention->windowFor(InsightStatus::Dismissed),
            'unactioned_days' => $this->retention->windowFor(InsightStatus::New),
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            '%s %d dismissed and %d unactioned insight(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $dismissed,
            $unactioned,
        ));

        return self::SUCCESS;
    }

    /**
     * Delete every insight in `$status` past its window, in chunks.
     *
     * The ids are read and then deleted **through the same constraint**, not by id alone: between
     * the read and the delete a member can dismiss an insight, and a plain `whereIn('id', …)`
     * would then delete a row under a window it no longer belongs to.
     */
    private function prune(InsightStatus $status, bool $dryRun): int
    {
        $eligible = $this->retention->eligible($status);

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
                // Every row in this chunk changed status in the meantime. Stop rather than spin;
                // the next nightly sweep picks up whatever is still eligible.
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }
}
