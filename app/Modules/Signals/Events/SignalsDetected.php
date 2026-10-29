<?php

declare(strict_types=1);

namespace App\Modules\Signals\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired (after commit) when a signal run has persisted one or more new signals, so the feed can
 * raise a "N new signals" toast on the team's private channel. Carries only the run id and the
 * count — never signal records. Also a plain event other modules may listen to later (e.g. a weekly
 * digest). Reuses the `teams.{id}` channel.
 */
final class SignalsDetected implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $runId,
        public readonly int $count,
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
        return 'signals.detected';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'count' => $this->count,
        ];
    }
}
