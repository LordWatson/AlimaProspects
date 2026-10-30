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
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function handle(
        LeadSourceProviderRegistry $registry,
        TeamContext $teamContext,
        LeadCandidateFilter $filter,
    ): void {
        $teamContext->run($this->teamId, function () use ($registry, $filter): void {
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

            // What the provider actually returned, captured before filtering: the request was made
            // and billed whether or not we keep its results, so this — not the kept count — is what
            // the cost is metered on. Metering the kept count would show a run that excluded
            // everything as free.
            $returned = count($leads);

            // Refuse the wrong kind of company before anything is persisted: the team's own trade and
            // its named competitors rank well for exactly the words that describe its work, so they
            // come back from every provider. Only web search can exclude them at the API; this is
            // where the other two are held to the same promise.
            [$leads, $rejected] = $filter->apply($query, $leads);

            // Enforce the run's size here too — never rely solely on the provider to honour the cap.
            $leads = array_slice($leads, 0, max(0, $run->requested_limit));

            $this->broadcast($run, DiscoveryRunStatus::Running, 60, 'Saving the companies we found', count($leads));

            $created = $this->persistLeads($run, $leads);

            DB::transaction(function () use ($run, $leads, $created, $provider, $returned): void {
                $run->forceFill([
                    'status' => DiscoveryRunStatus::Completed,
                    'leads_found' => count($leads),
                    'leads_created' => $created,
                    'cost_micros' => $provider->costMicros($returned),
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
                'leads_excluded' => $rejected,
                'shaped_query' => $query->shaped,
                'cost_micros' => $provider->costMicros($returned),
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
     * Persist the discovered leads, de-duplicated per team on `dedupe_key`. Returns how many were
     * newly created (vs. merged into an existing row). Each lead is persisted in its own transaction
     * so one collision can't roll back the rest of the run's leads.
     *
     * @param  list<SourcedLead>  $leads
     */
    private function persistLeads(DiscoveryRun $run, array $leads): int
    {
        $created = 0;

        foreach ($leads as $sourced) {
            $attributes = $sourced->toLeadAttributes();
            $dedupeKey = (string) $attributes['dedupe_key'];
            unset($attributes['dedupe_key']);

            $created += $this->persistLead($run, $sourced, $attributes, $dedupeKey);
        }

        return $created;
    }

    /**
     * Persist one discovered lead, de-duplicated per team on `dedupe_key`. Returns 1 when a new row is
     * created, 0 when it merges into an existing one.
     *
     * Fan-out (Phase 8) dispatches a job per provider at once, so two runs frequently discover the
     * same company simultaneously. The unique index on `(team_id, dedupe_key)` is the backstop: we
     * merge when the row already exists, and if a concurrent run inserts it between our check and our
     * insert the create raises a {@see UniqueConstraintViolationException}, which we catch and turn
     * into a merge — so a race never duplicates a lead, fails the run, or silently drops a provider's
     * (paid) result. The create runs in its own transaction so the violation rolls back only that
     * insert (keeping the job safe on Postgres, where a poisoned transaction can't continue).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistLead(DiscoveryRun $run, SourcedLead $sourced, array $attributes, string $dedupeKey): int
    {
        if ($this->mergeIfExists($sourced, $attributes, $dedupeKey)) {
            return 0;
        }

        try {
            DB::transaction(function () use ($run, $attributes, $dedupeKey): void {
                Lead::query()->create([
                    ...$attributes,
                    'team_id' => $this->teamId,
                    'dedupe_key' => $dedupeKey,
                    'discovery_run_id' => $run->getKey(),
                ]);
            });

            return 1;
        } catch (UniqueConstraintViolationException) {
            // A concurrent run won the race between our check and insert — merge into its row instead.
            $this->mergeIfExists($sourced, $attributes, $dedupeKey);

            return 0;
        }
    }

    /**
     * Merge the sourced lead into the existing row for this `dedupe_key`, if one exists — locked for
     * the duration so two concurrent merges can't lose each other's writes. Returns true when a row
     * was found and merged.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mergeIfExists(SourcedLead $sourced, array $attributes, string $dedupeKey): bool
    {
        return (bool) DB::transaction(function () use ($sourced, $attributes, $dedupeKey): bool {
            $lead = Lead::query()
                ->where('team_id', $this->teamId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if (! $lead instanceof Lead) {
                return false;
            }

            $this->mergeInto($lead, $sourced, $attributes);

            return true;
        });
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
