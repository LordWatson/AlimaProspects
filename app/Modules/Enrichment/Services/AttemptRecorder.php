<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Contracts\EnrichmentProvider;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Models\EnrichmentAttempt;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Writes one row per vendor call (PL3) — the only thing that writes `enrichment_attempts`.
 *
 * It exists as its own class for one reason: **recording must never be the thing that fails a
 * run.** The attempt row is written after the money has already left, so an exception here would
 * lose the record of a charge we have incurred — the worst possible place to throw. Every write is
 * wrapped, and a failure is logged and swallowed.
 *
 * That is a deliberate inversion of the usual rule. Normally a write that silently fails is a bug;
 * here, failing loudly would cost the member their result as well as the money.
 *
 * Nothing it logs or stores is a contact detail: ids, a provider key, a status word and a figure.
 * The addresses are on the contact, which has a retention policy; an attempt row does not.
 */
final class AttemptRecorder
{
    /**
     * Record a call that was actually made.
     */
    public function record(
        EnrichmentRun $run,
        Lead $lead,
        ?LeadContact $contact,
        EnrichmentProvider $provider,
        AttemptOutcome $outcome,
        ?int $latencyMs = null,
        int $costMicros = 0,
        ?string $statusReturned = null,
        ?string $errorCode = null,
    ): void {
        $this->write([
            'team_id' => $run->team_id,
            'enrichment_run_id' => $run->getKey(),
            'lead_id' => $lead->getKey(),
            'contact_id' => $contact?->getKey(),
            'capability' => $provider->capability(),
            'provider' => $provider->key(),
            'outcome' => $outcome,
            'status_returned' => $statusReturned,
            'latency_ms' => $latencyMs,
            // A skip cannot have cost anything, whatever a caller passes: the guarantee that
            // `enrichment_attempts` sums to a real bill is worth more than trusting every caller.
            'cost_micros' => $outcome->wasCalled() ? max(0, $costMicros) : 0,
            'error_code' => $errorCode,
            // `created_at` is **not** passed: it is not fillable, Eloquent sets it, and
            // mass-assigning a guarded attribute throws under `shouldBeStrict()` — which this
            // class then swallows, leaving an empty attempts table and no sign of why. The swallow
            // is right; feeding it an avoidable exception is not.
        ]);
    }

    /**
     * Record a call we chose **not** to make: a paused provider, a rate limit, or a question with
     * nothing to go on.
     *
     * Worth a row even though it cost nothing, because the absence of one is indistinguishable from
     * a provider nobody configured — and "why did this find nothing" is the question the admin
     * panel exists to answer.
     */
    public function skipped(
        EnrichmentRun $run,
        Lead $lead,
        ?LeadContact $contact,
        ProviderCapability $capability,
        string $provider,
        AttemptOutcome $outcome,
    ): void {
        $this->write([
            'team_id' => $run->team_id,
            'enrichment_run_id' => $run->getKey(),
            'lead_id' => $lead->getKey(),
            'contact_id' => $contact?->getKey(),
            'capability' => $capability,
            'provider' => $provider,
            'outcome' => $outcome,
            'cost_micros' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            EnrichmentAttempt::query()->create($attributes);
        } catch (\Throwable $e) {
            // Swallowed on purpose — see the class note. Logged by id so an operator can find the
            // run whose cost is now under-recorded.
            Log::channel('lead_sourcing')->error('Could not record an enrichment attempt.', [
                'team_id' => $attributes['team_id'] ?? null,
                'enrichment_run_id' => $attributes['enrichment_run_id'] ?? null,
                'provider' => $attributes['provider'] ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
