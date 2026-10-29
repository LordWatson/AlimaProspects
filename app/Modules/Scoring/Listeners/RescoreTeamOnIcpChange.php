<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Listeners;

use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-scores a team's leads when the definition they were scored against changes (Phase 9): the
 * active ICP's criteria or weights were edited, or a different ICP was made active.
 *
 * Itself queued — a team can hold many leads, and fanning out a job per lead must not happen inside
 * the request that saved the ICP. Leads are walked in chunks (`scoring.rescore_chunk`) so memory
 * stays flat however large the team is. Each job is forced, because the scores are stale by
 * definition: the ICP they were measured against has moved.
 *
 * When the team is left with **no** active ICP (`activeIcpId` is null), there is nothing to score
 * against, so nothing is dispatched. Existing scores stay attached to the ICP that produced them —
 * they are history, not current judgement, and the screens only surface the active ICP's score.
 * Deleting them would also throw away the members' feedback.
 */
final class RescoreTeamOnIcpChange implements ShouldQueue
{
    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var list<int>
     */
    public $backoff = [30, 90, 180];

    /**
     * Kept below the queue's `retry_after` (90s). Only dispatching happens here, so the chunk walk
     * is fast however many leads a team holds.
     *
     * @var int
     */
    public $timeout = 85;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(IcpUpdated $event): void
    {
        if ($event->activeIcpId === null) {
            Log::channel('lead_sourcing')->info('No re-score queued: the team has no active ICP.', [
                'team_id' => $event->teamId,
            ]);

            return;
        }

        $icpId = $event->activeIcpId;
        $chunk = max(1, (int) config('scoring.rescore_chunk', 200));
        $queued = 0;

        $this->teamContext->run($event->teamId, function () use ($event, $icpId, $chunk, &$queued): void {
            Lead::query()
                ->select('id')
                ->orderBy('id')
                ->chunkById($chunk, function (Collection $leads) use ($event, $icpId, &$queued): void {
                    foreach ($leads as $lead) {
                        ScoreLeadJob::dispatch($event->teamId, (int) $lead->getKey(), $icpId, force: true);
                        $queued++;
                    }
                });
        });

        Log::channel('lead_sourcing')->info('Re-score queued after an ICP change.', [
            'team_id' => $event->teamId,
            'icp_id' => $icpId,
            'leads' => $queued,
        ]);
    }

    /**
     * A retry is safe — the per-lead jobs are unique and the action is idempotent — but a team that
     * silently never got re-scored would be invisible, so record the failure.
     */
    public function failed(IcpUpdated $event, Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Re-score fan-out failed after an ICP change.', [
            'team_id' => $event->teamId,
            'icp_id' => $event->activeIcpId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
