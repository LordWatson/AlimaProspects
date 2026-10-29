<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Listeners;

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records that a lead's data was filled in (Phase 10). Subjected to the lead itself, so a lead
 * re-enriched a dozen times carries **one** "Enriched" entry whose timestamp is the last time it
 * happened — which is the question a timeline is being asked ("how fresh is this?"), not a dozen
 * near-identical rows pushing the interesting history off the page.
 */
final class RecordEnrichmentActivity implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    /** The event is dispatched afterCommit already; this holds even if a caller later isn't. */
    public bool $afterCommit = true;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly RecordLeadActivityAction $record,
    ) {}

    public function handle(LeadEnriched $event): void
    {
        $this->teamContext->run($event->teamId, function () use ($event): void {
            $lead = Lead::query()->withCount('contacts')->find($event->leadId);

            if (! $lead instanceof Lead) {
                return; // merged away or deleted since — nothing to put a timeline entry on
            }

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::Enriched,
                subject: $lead,
                properties: [
                    'contacts' => (int) $lead->getAttribute('contacts_count'),
                    // Whether the lead now has a website is the single most useful thing to show,
                    // because it is what the rest of enrichment hangs off.
                    'has_website' => $lead->website !== null,
                ],
                actor: null,
                occurredAt: $lead->enriched_at,
            );
        });
    }

    /**
     * The timeline is a read model: losing an entry costs the team some history, never any data, so
     * a give-up is logged for someone to notice rather than escalated.
     */
    public function failed(LeadEnriched $event, Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Could not record the enrichment timeline entry.', [
            'team_id' => $event->teamId,
            'lead_id' => $event->leadId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
