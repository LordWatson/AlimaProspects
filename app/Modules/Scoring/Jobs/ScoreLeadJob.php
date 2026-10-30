<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Jobs;

use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Actions\ScoreLeadAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores one {@see Lead} by running {@see ScoreLeadAction} inside the team's context. Queued per
 * lead so a whole-team re-score fans out across workers.
 *
 * - Idempotent + `ShouldBeUnique` per (lead, ICP, force), so the enrichment and ICP-change triggers
 *   firing together can't score the same lead twice; the action also returns the existing score
 *   untouched when it is already newer than its inputs.
 * - The AI call runs inside the action, outside any DB transaction.
 * - Credit exhaustion / un-validatable output are handled inside the action (the score degrades to
 *   the rules alone), so they never burn retries. A transport failure bubbles and the queue retries
 *   with backoff.
 */
final class ScoreLeadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * Release the uniqueness lock after ten minutes so a crashed worker can't block a re-score.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long scoring call can't be dispatched twice.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 90, 180];

    public function __construct(
        public readonly int $teamId,
        public readonly int $leadId,
        public readonly ?int $icpId = null,
        public readonly bool $force = false,
    ) {}

    /**
     * A forced re-score is a distinct intent from an opportunistic one, so it isn't swallowed by a
     * queued automatic score for the same lead.
     */
    public function uniqueId(): string
    {
        return implode(':', [$this->leadId, $this->icpId ?? 'active', $this->force ? 'force' : 'auto']);
    }

    public function handle(ScoreLeadAction $action, TeamContext $teamContext): void
    {
        $teamContext->run($this->teamId, function () use ($action): void {
            $lead = Lead::query()->whereKey($this->leadId)->first();

            if (! $lead instanceof Lead) {
                return; // deleted, or merged away by enrichment de-duplication
            }

            $icp = $this->icpId === null
                ? null
                : Icp::query()->whereKey($this->icpId)->with('criteria')->first();

            // An ICP id that no longer resolves (deleted mid-flight) would silently fall back to
            // the active one, which is not what was asked for — skip instead.
            if ($this->icpId !== null && ! $icp instanceof Icp) {
                return;
            }

            $action->execute($lead, $icp, $this->force);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Lead scoring job failed.', [
            'team_id' => $this->teamId,
            'lead_id' => $this->leadId,
            'icp_id' => $this->icpId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
