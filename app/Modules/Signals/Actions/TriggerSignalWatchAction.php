<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Run now" for a watch (ADR 0011, S3): validates the watch is active, enforces a per-watch rate
 * limit (once every 15 minutes, so a demo click can't spend a run on every press), then dispatches
 * {@see RunSignalWatchJob}. `ShouldBeUnique` on the job is the second guard against a concurrent run.
 */
final class TriggerSignalWatchAction
{
    private const int THROTTLE_SECONDS = 900; // once per 15 minutes per watch

    public function handle(SignalWatch $watch): void
    {
        if (! $watch->is_active) {
            throw SignalsException::watchInactive();
        }

        $key = 'signals:run-now:'.$watch->getKey();

        // Atomic check-and-hit so two quick clicks can't both slip through.
        $dispatched = RateLimiter::attempt(
            $key,
            maxAttempts: 1,
            callback: fn () => RunSignalWatchJob::dispatch((int) $watch->team_id, (int) $watch->getKey()),
            decaySeconds: self::THROTTLE_SECONDS,
        );

        if ($dispatched === false) {
            throw SignalsException::runThrottled(RateLimiter::availableIn($key));
        }
    }
}
