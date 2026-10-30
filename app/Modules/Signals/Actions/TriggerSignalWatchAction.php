<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Run now" for a watch (ADR 0011, S3): validates the watch is active, enforces a per-watch rate
 * limit (once every 15 minutes, so a demo click can't spend a run on every press), then opens a
 * **queued run row** and dispatches {@see RunSignalWatchJob}. `ShouldBeUnique` on the job is the
 * second guard against a concurrent run.
 *
 * **Why the row is opened here and not left to the job.** The screen that pressed the button needs
 * something to show the moment it redirects back, and until a worker picks the job up there is
 * nothing to show — which is how "Run now" came to look like it had done nothing at all. Returning
 * a `queued` run means the card can say so immediately, and it stays accurate when the queue is
 * backed up or stopped: *queued* is then the truth, not a spinner that will never resolve.
 * {@see RunSignalWatchAction::claimOrCreateRun()} already claims a non-terminal run for the watch
 * rather than opening a second one, so the worker adopts this row and flips it to `running`.
 */
final class TriggerSignalWatchAction
{
    private const int THROTTLE_SECONDS = 900; // once per 15 minutes per watch

    public function handle(SignalWatch $watch): SignalRun
    {
        if (! $watch->is_active) {
            throw SignalsException::watchInactive();
        }

        $key = 'signals:run-now:'.$watch->getKey();

        // Atomic check-and-hit so two quick clicks can't both slip through.
        $run = RateLimiter::attempt(
            $key,
            maxAttempts: 1,
            callback: fn (): SignalRun => $this->open($watch),
            decaySeconds: self::THROTTLE_SECONDS,
        );

        // `attempt()` returns false when the limiter refuses; anything else means it never ran.
        if (! $run instanceof SignalRun) {
            throw SignalsException::runThrottled(RateLimiter::availableIn($key));
        }

        return $run;
    }

    /**
     * Open (or adopt) the queued run and dispatch the worker once the row is committed.
     */
    private function open(SignalWatch $watch): SignalRun
    {
        return DB::transaction(function () use ($watch): SignalRun {
            // A run still waiting for a worker is reused rather than stacked on, so a team that
            // presses the button either side of a slow queue doesn't collect orphan rows.
            $existing = SignalRun::query()
                ->where('team_id', $watch->team_id)
                ->where('signal_watch_id', $watch->getKey())
                ->whereIn('status', [SignalRunStatus::Queued->value, SignalRunStatus::Running->value])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $run = $existing ?? SignalRun::query()->create([
                'team_id' => $watch->team_id,
                'signal_watch_id' => $watch->getKey(),
                'status' => SignalRunStatus::Queued,
            ]);

            // Never dispatch from inside the transaction: the worker would look for a row that
            // isn't committed yet and open a second one.
            RunSignalWatchJob::dispatch((int) $watch->team_id, (int) $watch->getKey())->afterCommit();

            return $run;
        });
    }
}
