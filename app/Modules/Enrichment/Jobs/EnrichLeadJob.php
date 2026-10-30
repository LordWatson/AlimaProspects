<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Jobs;

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Actions\EnrichLeadAction;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enriches one discovered {@see Lead} (Phase 8) by running {@see EnrichLeadAction} inside the team's
 * context. Queued per lead so a large discovery run fans out across workers.
 *
 * - Idempotent + `ShouldBeUnique` per lead, so a double dispatch can't enrich the same lead twice;
 *   the action also no-ops on a lead already in a terminal enrichment state.
 * - External work (fetch + AI + DNS) runs inside the action, outside any DB transaction.
 * - AI credit exhaustion / malformed output are handled terminally inside the action; any other
 *   error bubbles so the queue retries with backoff, and {@see self::failed()} marks the lead Failed.
 */
final class EnrichLeadJob implements ShouldBeUnique, ShouldQueue
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
     * Release the uniqueness lock after ten minutes so a crashed worker can't block a re-enrich.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long enrich can't be dispatched twice.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 90, 180];

    public function __construct(public readonly int $teamId, public readonly int $leadId) {}

    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    public function handle(EnrichLeadAction $action, TeamContext $teamContext): void
    {
        $teamContext->run($this->teamId, function () use ($action): void {
            $lead = Lead::query()->whereKey($this->leadId)->first();

            if ($lead instanceof Lead) {
                $action->execute($lead);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Lead enrichment job failed.', [
            'team_id' => $this->teamId,
            'lead_id' => $this->leadId,
            'exception' => $exception->getMessage(),
        ]);

        app(TeamContext::class)->run($this->teamId, function (): void {
            $lead = Lead::query()->whereKey($this->leadId)->first();

            if ($lead instanceof Lead && ! $lead->enrichment_status->isTerminal()) {
                DB::transaction(fn () => $lead->forceFill(['enrichment_status' => EnrichmentStatus::Failed])->save());
            }
        });
    }
}
