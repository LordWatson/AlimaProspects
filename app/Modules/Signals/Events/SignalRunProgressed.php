<?php

declare(strict_types=1);

namespace App\Modules\Signals\Events;

use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Progress for a running signal watch, broadcast on the team's private channel so the feed shows a
 * live status while {@see RunSignalWatchJob} works (stages: searching → analysing → saving → done).
 * Carries only non-sensitive status/step/count data — never signal records. Reuses the `teams.{id}`
 * channel already authorised in routes/channels.php, mirroring `DiscoveryRunProgressed`.
 *
 * **`watchId` is what makes the progress findable.** "Run now" dispatches a job and redirects; the
 * run row doesn't exist until the worker picks the job up, so the screen that asked for the run
 * cannot know the run id it is waiting for. Keyed by watch instead, a card shows its own progress
 * from the first broadcast onward — and a watch has at most one live run (`ShouldBeUnique`), so the
 * watch id is an unambiguous key.
 */
final class SignalRunProgressed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $watchId,
        public readonly int $runId,
        public readonly SignalRunStatus $status,
        public readonly int $percent,
        public readonly string $step,
        public readonly int $signalsCreated = 0,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('teams.'.$this->teamId)];
    }

    public function broadcastAs(): string
    {
        return 'signal-run.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'watch_id' => $this->watchId,
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'percent' => $this->percent,
            'step' => $this->step,
            'signals_created' => $this->signalsCreated,
        ];
    }
}
