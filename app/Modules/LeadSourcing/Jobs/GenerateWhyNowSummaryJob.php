<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Jobs;

use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Actions\GenerateWhyNowSummaryAction;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one lead's why-now summary inside the team's context (PL1).
 *
 * Queued because it is an AI call and the member who caused it — by converting a signal, running a
 * discovery, flagging a fact — should not wait for a sentence they did not ask for.
 *
 * `ShouldBeUnique` per lead, because every path that changes a lead's evidence dispatches this and
 * a discovery run that records four facts about one company must not buy four summaries. The hash
 * is checked **again here**, not only by the dispatcher: by the time a worker picks this up another
 * job may have generated the same sentence, and a lock released on completion is not a lock against
 * the next arrival.
 */
final class GenerateWhyNowSummaryJob implements ShouldBeUnique, ShouldQueue
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
     * Released after ten minutes so a crashed worker cannot block a lead's summary for ever.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Below the queue's `retry_after` (90s), so a slow model call cannot be dispatched twice.
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
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    public function handle(
        GenerateWhyNowSummaryAction $action,
        TeamContext $teamContext,
        LeadConfidence $confidence,
    ): void {
        $teamContext->run($this->teamId, function () use ($action, $confidence): void {
            $lead = Lead::query()->with('evidence')->whereKey($this->leadId)->first();

            if (! $lead instanceof Lead) {
                return; // deleted, or merged away by enrichment de-duplication
            }

            // The gate, checked here as well as at dispatch. Same evidence, same sentence, no call.
            if ($lead->why_now_evidence_hash === $confidence->for($lead)->evidenceHash) {
                return;
            }

            $action->handle($lead, Icp::query()->where('is_active', true)->first());
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Why-now summary job failed.', [
            'team_id' => $this->teamId,
            'lead_id' => $this->leadId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
