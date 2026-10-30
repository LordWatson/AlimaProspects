<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Events;

use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Progress for a running background check, broadcast on the team's private channel so the review
 * screen can show a live status while {@see RunBackgroundCheckJob}
 * works. Carries only non-sensitive status/step data — never the fetched content or the findings.
 */
final class BackgroundCheckProgressed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly BackgroundCheckStatus $status,
        public readonly int $percent,
        public readonly string $step,
        public readonly ?int $version = null,
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
        return 'background-check.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->status->value,
            'percent' => $this->percent,
            'step' => $this->step,
            'version' => $this->version,
        ];
    }
}
