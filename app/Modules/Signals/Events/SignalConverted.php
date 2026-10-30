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
 * Fired (after commit) when a signal has become a standard `Lead` (S4), so an open feed can drop
 * the card and point at the lead, and so a later module — a digest, reporting — can count
 * conversions without querying for them.
 *
 * `attached` says whether the lead was *created* or the signal was linked to a company the team
 * already had: the two read very differently to the member who clicked, and only the first is a new
 * lead for reporting. Carries ids and that one flag — never the signal's text or the person's name
 * (CLAUDE.md logging rules; the payload goes over the wire to every open tab). Reuses the
 * `teams.{id}` channel.
 */
final class SignalConverted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $signalId,
        public readonly int $leadId,
        public readonly bool $attached,
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
        return 'signals.converted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'signal_id' => $this->signalId,
            'lead_id' => $this->leadId,
            'attached' => $this->attached,
        ];
    }
}
