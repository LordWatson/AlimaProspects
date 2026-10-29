<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Jobs;

use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Events\DiscoveryRunProgressed;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
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
 * Runs one discovery for a team (Phase 7). It marks the run in-progress, calls the selected
 * {@see LeadSourceProvider} (external — outside any transaction),
 * persists the returned company-level {@see Lead}s (de-duplicated per team on `dedupe_key`), records
 * the counts + metered cost on the run, and broadcasts progress on the team's private channel.
 *
 * - Idempotent + `ShouldBeUnique` per run, so a double dispatch can't run the same discovery twice.
 *   A retry that finds the run already terminal no-ops; re-persisting the same leads updates in
 *   place rather than duplicating.
 * - The provider call runs OUTSIDE any DB transaction (CLAUDE.md external-call rule); persistence
 *   runs in a short transaction.
 * - A provider failure bubbles so the queue retries with backoff; {@see self::failed()} marks the
 *   run Failed on final failure.
 */
final class DiscoverLeadsJob implements ShouldBeUnique, ShouldQueue
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
     * Release the uniqueness lock after ten minutes so a crashed worker can't block a re-run.
     *
     * @var int
     */
    public $uniqueFor = 600;

    /**
     * Kept below the queue's `retry_after` (90s) so a long run can't be dispatched twice.
     *
     * @var int
     */
    public $timeout = 85;

    /**
     * @var list<int>
     */
    public $backoff = [30, 90, 180];

    public function __construct(public readonly int $teamId, public readonly int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(LeadSourceProviderRegistry $registry, TeamContext $teamContext): void
    {
        $teamContext->run($this->teamId, function () use ($registry): void {
            $run = $this->startRun();

            // Already finished (idempotent retry) or gone — nothing to do.
            if (! $run instanceof DiscoveryRun) {
                return;
            }

            $this->broadcast($run, DiscoveryRunStatus::Running, 15, 'Searching '.$run->source->label());

            $provider = $registry->for($run->source);
            $query = DiscoveryQuery::fromParams($run->source, $run->params ?? []);

            // --- External work: query the provider (outside any transaction) ---
            try {
                $leads = $provider->discover($query);
            } catch (LeadSourcingException $e) {
                // A misconfiguration / unavailable provider can never succeed — fail the run now
                // rather than letting it bubble and burn the queue's retries. A transient failure
                // (provider_failed) bubbles so the queue retries with backoff.
                if ($e->isRetryable()) {
                    throw $e;
                }

                $this->markFailed($run, $e);

                return;
            }

            // Enforce the run's size here too — never rely solely on the provider to honour the cap.
            $leads = array_slice($leads, 0, max(0, $run->requested_limit));

            $this->broadcast($run, DiscoveryRunStatus::Running, 60, 'Saving the companies we found', count($leads));

            $created = $this->persistLeads($run, $leads);

            DB::transaction(function () use ($run, $leads, $created, $provider): void {
                $run->forceFill([
                    'status' => DiscoveryRunStatus::Completed,
                    'leads_found' => count($leads),
                    'leads_created' => $created,
                    'cost_micros' => $provider->costMicros(count($leads)),
                    'error_message' => null,
                    'completed_at' => now(),
                ])->save();
            });

            Log::channel('lead_sourcing')->info('Discovery run completed.', [
                'team_id' => $this->teamId,
                'run_id' => $run->getKey(),
                'source' => $run->source->value,
                'leads_found' => count($leads),
                'leads_created' => $created,
                'cost_micros' => $provider->costMicros(count($leads)),
            ]);

            $this->broadcast($run, DiscoveryRunStatus::Completed, 100, 'Discovery complete', count($leads));

            // Hand off to enrichment (Phase 8): a listener fans out a job per new lead. Fired after
            // the run is settled so enrichment only ever sees persisted leads.
            event(new DiscoveryRunCompleted($this->teamId, (int) $run->getKey()));
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('lead_sourcing')->error('Discovery run job failed.', [
            'team_id' => $this->teamId,
            'run_id' => $this->runId,
            'exception' => $exception->getMessage(),
        ]);

        app(TeamContext::class)->run($this->teamId, function () use ($exception): void {
            $run = DiscoveryRun::query()
                ->where('team_id', $this->teamId)
                ->whereKey($this->runId)
                ->first();

            if ($run instanceof DiscoveryRun && ! $run->status->isTerminal()) {
                $this->markFailed($run, $exception);
            }
        });
    }

    /**
     * Claim the run for this attempt: move Pending → Running and stamp `started_at`. Returns null
     * when the run is missing or already terminal (an idempotent retry after it finished).
     */
    private function startRun(): ?DiscoveryRun
    {
        return DB::transaction(function (): ?DiscoveryRun {
            $run = DiscoveryRun::query()
                ->where('team_id', $this->teamId)
                ->lockForUpdate()
                ->find($this->runId);

            if (! $run instanceof DiscoveryRun || $run->status->isTerminal()) {
                return null;
            }

            $run->forceFill([
                'status' => DiscoveryRunStatus::Running,
                'started_at' => $run->started_at ?? now(),
            ])->save();

            return $run;
        });
    }

    /**
     * Persist the discovered leads, de-duplicated per team on `dedupe_key` (the Phase 7 placeholder;
     * full de-duplication is Phase 8). Returns how many were newly created (vs. updated in place).
     *
     * @param  list<SourcedLead>  $leads
     */
    private function persistLeads(DiscoveryRun $run, array $leads): int
    {
        $created = 0;

        DB::transaction(function () use ($run, $leads, &$created): void {
            foreach ($leads as $sourced) {
                $attributes = $sourced->toLeadAttributes();
                $dedupeKey = (string) $attributes['dedupe_key'];
                unset($attributes['dedupe_key']);

                $lead = Lead::query()
                    ->where('team_id', $this->teamId)
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if ($lead instanceof Lead) {
                    $this->mergeInto($lead, $sourced, $attributes);

                    continue;
                }

                Lead::query()->create([
                    ...$attributes,
                    'team_id' => $this->teamId,
                    'dedupe_key' => $dedupeKey,
                    'discovery_run_id' => $run->getKey(),
                ]);

                $created++;
            }
        });

        return $created;
    }

    /**
     * Merge a re-discovered {@see SourcedLead} into an existing {@see Lead} without losing provenance.
     * The original discoverer (`discovery_run_id`) is always kept (set only on create). Identity +
     * attribution fields (`source`, `company_name`, `source_url`, `raw`) are only overwritten when the
     * new source is at least as authoritative as the one on record ({@see LeadSource::rank()}); a
     * lower-ranked source (e.g. a web-search hit re-finding a Companies House lead) may only fill
     * fields that are still null. No field is ever nulled.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mergeInto(Lead $lead, SourcedLead $sourced, array $attributes): void
    {
        $authoritative = $sourced->source->rank() >= $lead->source->rank();
        $attributionKeys = ['source', 'company_name', 'source_url', 'raw'];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue; // never null an existing value
            }

            $existing = $lead->getAttribute($key);

            if ($authoritative) {
                $lead->setAttribute($key, $value); // same-or-higher rank refreshes the record
            } elseif ($existing === null && ! in_array($key, $attributionKeys, strict: true)) {
                $lead->setAttribute($key, $value); // weaker source only fills non-attribution gaps
            }
        }

        $lead->save();
    }

    private function markFailed(DiscoveryRun $run, Throwable $exception): void
    {
        DB::transaction(function () use ($run): void {
            $run->forceFill([
                'status' => DiscoveryRunStatus::Failed,
                'error_message' => 'We couldn’t complete this discovery run. Please try again.',
                'completed_at' => now(),
            ])->save();
        });

        $this->broadcast($run, DiscoveryRunStatus::Failed, 100, 'Discovery failed', $run->leads_found);
    }

    private function broadcast(DiscoveryRun $run, DiscoveryRunStatus $status, int $percent, string $step, int $leadsFound = 0): void
    {
        event(new DiscoveryRunProgressed($this->teamId, (int) $run->getKey(), $status, $percent, $step, $leadsFound));
    }
}
