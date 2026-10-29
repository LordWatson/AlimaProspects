<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Listeners;

use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a lead's grade on its timeline (Phase 10). Subjected to the score row, which Scoring
 * keeps one of per (lead, ICP) and re-scores in place — so the timeline entry has exactly the same
 * lifetime as the thing it describes, and a re-grade updates the entry rather than stacking a new
 * one on top.
 */
final class RecordScoringActivity implements ShouldQueue
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

    public function handle(LeadScored $event): void
    {
        $this->teamContext->run($event->teamId, function () use ($event): void {
            $lead = Lead::query()->find($event->leadId);
            $score = LeadScore::query()->with('icp:id,name')->find($event->scoreId);

            if (! $lead instanceof Lead || ! $score instanceof LeadScore) {
                return;
            }

            $this->record->handle(
                lead: $lead,
                type: LeadActivityType::Scored,
                subject: $score,
                properties: [
                    'grade' => $event->grade,
                    'score' => $event->score,
                    // Named so the entry still reads sensibly after the team renames or retires the
                    // profile the lead was graded against.
                    'icp' => $score->icp?->name,
                ],
                actor: null,
                occurredAt: $score->scored_at,
            );
        });
    }

    /**
     * The timeline is a read model: losing an entry costs the team some history, never any data, so
     * a give-up is logged for someone to notice rather than escalated.
     */
    public function failed(LeadScored $event, Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Could not record the scoring timeline entry.', [
            'team_id' => $event->teamId,
            'lead_id' => $event->leadId,
            'score_id' => $event->scoreId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
