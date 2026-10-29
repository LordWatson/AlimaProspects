<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Hourly scheduler (ADR 0011, S3): queues a {@see RunSignalWatchJob} for each active watch whose
 * cadence is due — Daily when `last_run_at` is older than 24h, Weekly when older than 7 days, or a
 * watch that has never run. Runs with no active team, so the `BelongsToTeam` global scope is a no-op
 * and every team's watches are considered; the job runs inside each watch's team context.
 * `ShouldBeUnique` on the job means a watch already running is not double-queued.
 */
final class DispatchDueSignalWatchesCommand extends Command
{
    protected $signature = 'signals:dispatch-due';

    protected $description = 'Queue signal-watch runs for active watches whose cadence is due.';

    public function handle(): int
    {
        $now = Carbon::now();
        $dispatched = 0;

        SignalWatch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($watches) use ($now, &$dispatched): void {
                foreach ($watches as $watch) {
                    if ($this->isDue($watch, $now)) {
                        RunSignalWatchJob::dispatch((int) $watch->team_id, (int) $watch->getKey());
                        $dispatched++;
                    }
                }
            });

        $this->info("Dispatched {$dispatched} signal watch run(s).");

        return self::SUCCESS;
    }

    private function isDue(SignalWatch $watch, Carbon $now): bool
    {
        if ($watch->last_run_at === null) {
            return true;
        }

        return $watch->last_run_at->lt($now->copy()->subHours($watch->cadence->staleAfterHours()));
    }
}
