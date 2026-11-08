<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Events;

use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Jobs\RunEnrichmentWaterfallJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An enrichment run changed state, broadcast so the lead screen updates while
 * {@see RunEnrichmentWaterfallJob} works (PL3).
 *
 * **It carries counts and never contact details.** The payload is a run id, a state and how many
 * things were found; the client re-reads the lead to get them. That is not a round-trip we failed
 * to optimise away — a broadcast fans out to every member of the team with the screen open, and a
 * revealed email in the payload would be a paid reveal delivered to people who did not pay for it
 * and may not have the lead open at all.
 *
 * Reuses the `teams.{id}` channel already authorised in `routes/channels.php`, which is also what
 * keeps the tenancy check in one place.
 */
final class LeadEnrichmentUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $summary  counts only — never a value a member has to pay for
     */
    public function __construct(
        public readonly int $teamId,
        public readonly int $leadId,
        public readonly int $runId,
        public readonly EnrichmentScope $scope,
        public readonly EnrichmentRunState $state,
        public readonly array $summary = [],
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
        return 'lead-enrichment.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'run_id' => $this->runId,
            'scope' => $this->scope->value,
            'state' => $this->state->value,
            'in_flight' => $this->state->isInFlight(),
            // Counts, so a client can say "found 2 people" without being handed the people.
            'summary' => array_filter(
                $this->summary,
                static fn (mixed $value): bool => is_int($value) || is_string($value) || is_bool($value),
            ),
        ];
    }
}
