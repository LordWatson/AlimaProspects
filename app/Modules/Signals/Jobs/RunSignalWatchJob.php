<?php

declare(strict_types=1);

namespace App\Modules\Signals\Jobs;

use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Events\SignalRunProgressed;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one signal watch (ADR 0011, S3) via {@see RunSignalWatchAction}, inside the watch's
 * {@see TeamContext}. `ShouldBeUnique` per watch, so a scheduled run and a "run now" can't execute
 * the same watch twice at once; a retry reuses the same run row (the action claims a non-terminal
 * run). Serper/AI calls happen in the action, outside any transaction. A transient failure bubbles
 * so the queue retries with backoff; {@see self::failed()} marks the run Failed on final failure.
 */
final class RunSignalWatchJob implements ShouldBeUnique, ShouldQueue
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
     * Release the uniqueness lock after ten minutes so a crashed worker can't block the next run.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long run can't be dispatched twice (matching
     * the other AI jobs). Work is bounded — a handful of Serper queries and ≤10-item AI batches.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 120, 300];

    public function __construct(public readonly int $teamId, public readonly int $watchId) {}

    public function uniqueId(): string
    {
        return (string) $this->watchId;
    }

    public function handle(RunSignalWatchAction $action, TeamContext $teamContext): void
    {
        $teamContext->run($this->teamId, function () use ($action): void {
            Log::withContext(['team_id' => $this->teamId, 'signal_watch_id' => $this->watchId]);

            $watch = SignalWatch::query()->find($this->watchId);

            // The watch was deleted or paused since it was queued — nothing to do.
            if (! $watch instanceof SignalWatch || ! $watch->is_active) {
                return;
            }

            $action->handle($watch);
        });
    }

    public function failed(Throwable $exception): void
    {
        // Log the exception class, not its message: a generic failure's message can carry URLs or
        // query text, and the `signals` channel is IDs/counts only.
        Log::channel('signals')->error('Signal watch job failed.', [
            'team_id' => $this->teamId,
            'signal_watch_id' => $this->watchId,
            'exception' => $exception::class,
        ]);

        app(TeamContext::class)->run($this->teamId, function (): void {
            $run = SignalRun::query()
                ->where('signal_watch_id', $this->watchId)
                ->whereNotIn('status', [SignalRunStatus::Completed->value, SignalRunStatus::Failed->value])
                ->orderByDesc('id')
                ->first();

            if (! $run instanceof SignalRun) {
                return;
            }

            $run->forceFill([
                'status' => SignalRunStatus::Failed,
                'error' => 'We couldn’t complete this signal run. Please try again.',
                'finished_at' => now(),
            ])->save();

            event(new SignalRunProgressed($this->teamId, (int) $run->getKey(), SignalRunStatus::Failed, 100, 'Signals run failed'));
        });
    }
}
