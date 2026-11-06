<?php

declare(strict_types=1);

namespace App\Modules\Signals\Jobs;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\SignalWatchlist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings every synced watchlist in step with its team's pipeline, nightly (S10).
 *
 * One job for the whole platform rather than one per team, the same shape as the retention sweep:
 * the work per list is a handful of indexed queries, and a job per team would be a fan-out whose
 * only purpose was to be counted.
 *
 * **`ShouldBeUnique`**, because the schedule and a manual "sync now" must not run over each other —
 * the action is idempotent, but two of them racing would both write `entries_count` and one would
 * be wrong by whatever the other did.
 *
 * Scheduled **before** the hourly watch dispatcher's night pass, so a lead added to the pipeline
 * today is being watched tomorrow morning rather than the morning after.
 */
final class SyncPipelineWatchlistsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * @var int
     */
    public $timeout = 600;

    /** Longer than a run of it could take, so a crashed worker cannot block the next night. */
    public int $uniqueFor = 1800;

    public function handle(SyncPipelineWatchlistAction $sync): void
    {
        $lists = 0;
        $added = 0;
        $removed = 0;

        SignalWatchlist::query()
            ->withoutGlobalScopes()
            ->where('source_kind', WatchlistSourceKind::Pipeline->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function (iterable $watchlists) use ($sync, &$lists, &$added, &$removed): void {
                foreach ($watchlists as $watchlist) {
                    $team = Team::query()->find($watchlist->team_id);

                    if (! $team instanceof Team) {
                        continue;
                    }

                    try {
                        $result = $sync->handle($team, $watchlist);
                    } catch (Throwable $e) {
                        // One team's broken list must not stop the other teams' from syncing: the
                        // same reasoning the nightly sweeps use. The failure is logged with ids.
                        Log::channel('signals')->error('Could not sync a pipeline watchlist.', [
                            'team_id' => (int) $watchlist->team_id,
                            'signal_watchlist_id' => (int) $watchlist->getKey(),
                            // The class and not the message: a `QueryException`'s message embeds the bound
                            // values, which on this table means a company name (CLAUDE.md §2 — log ids).
                            'exception' => $e::class,
                            'code' => $e->getCode(),
                        ]);

                        continue;
                    }

                    $lists++;
                    $added += $result['added'];
                    $removed += $result['removed'];
                }
            });

        Log::channel('signals')->info('Pipeline watchlists synced.', [
            'lists' => $lists,
            'added' => $added,
            'removed' => $removed,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('signals')->error('The pipeline watchlist sync failed.', [
            'exception' => $exception::class,
            'code' => $exception->getCode(),
        ]);
    }
}
