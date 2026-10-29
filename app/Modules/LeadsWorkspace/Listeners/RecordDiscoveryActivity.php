<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Listeners;

use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens the timeline for every lead a completed discovery run found (Phase 10). Queued, because a
 * run may carry up to `lead_sourcing.defaults.max_limit` leads and the request that started it
 * shouldn't wait on their history being written.
 *
 * Each entry is subjected to the run, so a retried job updates the same row rather than writing
 * the lead's origin story twice.
 */
final class RecordDiscoveryActivity implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Generous next to the other two listeners: this one walks every lead in a run, which is capped
     * at `lead_sourcing.defaults.max_limit`.
     */
    public int $timeout = 180;

    /** The event is dispatched afterCommit already; this holds even if a caller later isn't. */
    public bool $afterCommit = true;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly RecordLeadActivityAction $record,
    ) {}

    public function handle(DiscoveryRunCompleted $event): void
    {
        $this->teamContext->run($event->teamId, function () use ($event): void {
            $run = DiscoveryRun::query()->find($event->runId);

            if (! $run instanceof DiscoveryRun) {
                return; // the run was pruned before the timeline caught up — nothing to record
            }

            Lead::query()
                ->where('discovery_run_id', $run->getKey())
                ->chunkById(200, function (Collection $leads) use ($run): void {
                    foreach ($leads as $lead) {
                        $this->record->handle(
                            lead: $lead,
                            type: LeadActivityType::Discovered,
                            subject: $run,
                            properties: ['source' => $lead->source->value],
                            // Nobody "did" this: the member asked for a run, the platform found the
                            // lead. The timeline attributes it to Alima.
                            actor: null,
                            occurredAt: $lead->collected_at ?? $lead->created_at,
                        );
                    }
                });
        });
    }

    /**
     * The timeline is a read model: losing an entry costs the team some history, never any data, so
     * a give-up is logged for someone to notice rather than escalated.
     */
    public function failed(DiscoveryRunCompleted $event, Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Could not record the discovery timeline entries.', [
            'team_id' => $event->teamId,
            'run_id' => $event->runId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
