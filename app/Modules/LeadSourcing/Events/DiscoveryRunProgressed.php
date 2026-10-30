<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Events;

use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Progress for a running discovery, broadcast on the team's private channel so the run screen shows
 * a live status while {@see DiscoverLeadsJob} works. Carries only non-sensitive status/step/count
 * data — never lead records. Reuses the `teams.{id}` channel already authorised in routes/channels.php.
 */
final class DiscoveryRunProgressed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $runId,
        public readonly DiscoveryRunStatus $status,
        public readonly int $percent,
        public readonly string $step,
        public readonly int $leadsFound = 0,
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
        return 'discovery-run.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'percent' => $this->percent,
            'step' => $this->step,
            'leads_found' => $this->leadsFound,
        ];
    }
}
