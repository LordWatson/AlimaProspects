<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Jobs;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Events\LeadEnrichmentUpdated;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Services\EnrichmentWaterfall;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one {@see EnrichmentRun} inside the team's context (PL3).
 *
 * **Where the money is reserved and settled**, and deliberately not in the Action that created the
 * run: a reservation taken at request time would be held for as long as the queue is deep, and
 * released by nobody if the worker died. Here it brackets the actual spending.
 *
 * Four things about the shape, each guarding a specific failure:
 *
 * - **`ShouldBeUnique` per run**, not per lead-and-scope. The Action already refuses a second
 *   request while one is in flight; this is the narrower guarantee that one run is not executed
 *   twice by two workers, which is the case a retry after a timeout creates.
 * - **The reservation is released in `finally`.** A job that throws has still held an allowance,
 *   and an allowance held by a dead job is an allowance a team never gets back.
 * - **`$tries = 1`.** A retry would re-run a waterfall that has already been paid for. Providers
 *   are retried *inside* their own adapters, which is where a retry is free; a failed run is
 *   something a member re-requests, deliberately, having been told.
 * - **A broadcast on the way in and the way out**, so a screen that opened mid-run sees the
 *   spinner rather than a result that appears from nowhere.
 */
final class RunEnrichmentWaterfallJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt. A retry would re-pay for a waterfall that has already run — the providers do
     * their own retrying, where it costs nothing.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Longer than the module's other jobs: a waterfall is several HTTP calls in series, each with
     * its own timeout, and the ceiling has to be the sum rather than one of them.
     *
     * @var int
     */
    public $timeout = 170;

    /**
     * @var int
     */
    public $uniqueFor = 600;

    public function __construct(
        public readonly int $teamId,
        public readonly int $runId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(
        TeamContext $teamContext,
        EnrichmentWaterfall $waterfall,
        CreditReserver $reserver,
    ): void {
        $teamContext->run($this->teamId, function () use ($waterfall, $reserver): void {
            $run = EnrichmentRun::query()->whereKey($this->runId)->first();

            if (! $run instanceof EnrichmentRun || ! $run->state->isInFlight()) {
                return; // already settled, or deleted with its lead
            }

            $lead = Lead::query()->with('contacts')->whereKey($run->lead_id)->first();
            $team = Team::query()->whereKey($this->teamId)->first();

            if (! $lead instanceof Lead || ! $team instanceof Team) {
                $this->settle($run, EnrichmentRunState::Refused, ['reason' => 'lead_gone'], 'enrichment.lead_gone');

                return;
            }

            $reservation = $reserver->reserve($team, $run->scope, $this->unitsFor($run, $lead));

            if (! $reservation->granted()) {
                // A ceiling is an ordinary answer, recorded on the run so the member can see it —
                // and recorded as a **refusal** rather than a failure, so a plan boundary does not
                // show up as a provider outage on the operations screen.
                $this->settle($run, EnrichmentRunState::Refused, [], $reservation->refusalCode);

                return;
            }

            $run->forceFill(['state' => EnrichmentRunState::Running, 'started_at' => Carbon::now()])->save();
            $this->announce($run);

            $summary = [];

            try {
                $summary = $waterfall->run($run, $lead, $team);

                $this->commit($reserver, $reservation, $run, $summary);
                $this->settle($run, EnrichmentRunState::Completed, $summary);
            } catch (Throwable $e) {
                // Whatever went wrong, the calls already made have been recorded and have to be
                // paid for; only the unspent part of the reservation goes back.
                $this->commit($reserver, $reservation, $run, $summary);
                $this->settle($run, EnrichmentRunState::Failed, $summary, 'enrichment.run_failed');

                throw $e;
            }
        });
    }

    /**
     * Settle the reservation against what the attempts say was actually spent.
     *
     * **From the attempts, not from the summary.** A run whose worker died between its last call
     * and its summary still owes for the calls it made, and the attempts are written as each call
     * returns.
     *
     * @param  array<string, mixed>  $summary
     */
    private function commit(CreditReserver $reserver, CreditReservation $reservation, EnrichmentRun $run, array $summary): void
    {
        $reserver->commit(
            $reservation,
            spentMicros: $run->spentMicros(),
            unitsUsed: (int) ($summary['contacts_created'] ?? $summary['emails_found'] ?? $summary['phones_found'] ?? $summary['emails_verified'] ?? 0),
        );
    }

    /**
     * How many units this run is about to ask for.
     *
     * An **upper bound**, because a reservation has to be taken before anybody knows what will be
     * found — the commit narrows it to what was used. Over-reserving briefly is the safe direction:
     * under-reserving is a team spending past its allowance.
     */
    private function unitsFor(EnrichmentRun $run, Lead $lead): int
    {
        return match ($run->scope->needsContacts()) {
            true => max(1, $lead->contacts->count()),
            false => max(1, (int) config('enrichment.max_people_per_run', 3)),
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function settle(EnrichmentRun $run, EnrichmentRunState $state, array $summary, ?string $errorCode = null): void
    {
        $run->forceFill([
            'state' => $state,
            'summary' => $summary,
            'error_code' => $errorCode,
            'finished_at' => Carbon::now(),
        ])->save();

        $this->announce($run);
    }

    private function announce(EnrichmentRun $run): void
    {
        event(new LeadEnrichmentUpdated(
            teamId: (int) $run->team_id,
            leadId: (int) $run->lead_id,
            runId: (int) $run->getKey(),
            scope: $run->scope,
            state: $run->state,
            summary: $run->summary ?? [],
        ));
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Enrichment run failed.', [
            'team_id' => $this->teamId,
            'enrichment_run_id' => $this->runId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
