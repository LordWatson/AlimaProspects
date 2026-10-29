<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Data\ExtractedFacts;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\CompanyNormaliser;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enriches a single discovered {@see Lead} (Phase 8): normalises its company fields, extracts
 * structured facts from its public web text via the AI (`lead.enrich_extract`, cheap tier), derives
 * and email-verifies contacts, records per-field provenance + a data-freshness stamp, flags personal
 * data for compliance, and de-duplicates it against the team's other leads keeping the best source
 * per field.
 *
 * External work (web fetch + AI + DNS) runs OUTSIDE any transaction (CLAUDE.md external-call rule);
 * the writes run in one short transaction, and de-duplication in another. Idempotent: a lead already
 * in a terminal enrichment state is left untouched unless `$force` is set. Fetched web content is
 * wrapped in {@see UntrustedContent} — treated as data, never instructions. AI credit exhaustion and
 * malformed output are terminal (mark Failed, no retry); any other error bubbles for the queue.
 */
final class EnrichLeadAction
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly WebContentFetcher $fetcher,
        private readonly EmailVerifier $verifier,
        private readonly CompanyNormaliser $normaliser,
        private readonly LeadDeduplicator $deduplicator,
    ) {}

    /**
     * Enrich the lead and return the surviving record (de-duplication may merge it into another).
     */
    public function execute(Lead $lead, bool $force = false): Lead
    {
        if (! $force && $lead->enrichment_status->isTerminal()) {
            return $lead;
        }

        DB::transaction(fn () => $lead->forceFill(['enrichment_status' => EnrichmentStatus::Enriching])->save());

        // Normalise first so the fetch uses a canonical website.
        $normalisation = $this->normaliser->normalize($lead);
        foreach ($normalisation as $field => $value) {
            $lead->setAttribute($field, $value);
        }

        $website = $lead->website;

        // --- External work: fetch public web text + extract facts (outside any transaction) ---
        $facts = null;
        $cost = 0;
        $verifications = [];

        $webText = $website !== null ? $this->fetcher->fetch($website) : '';

        if ($webText !== '') {
            try {
                $response = $this->ai->structured('lead.enrich_extract', [
                    // Scraped content is untrusted — wrapped so embedded instructions can't execute.
                    'web_text' => UntrustedContent::from($webText),
                ]);
            } catch (AiCreditExceededException|InvalidAiResponseException $e) {
                return $this->markFailed($lead, $e); // terminal: retrying won't help
            }

            $facts = ExtractedFacts::fromAi($response->data);
            $cost = $response->costMicros;

            // Verify any extracted emails (DNS, still outside any transaction).
            foreach ($facts->emails as $email) {
                $verifications[$email] = $this->verifier->verify($email);
            }
        }

        // --- Writes ---
        $survivor = DB::transaction(function () use ($lead, $normalisation, $facts, $verifications, $cost, $website): Lead {
            $hasPersonalData = $this->applyFacts($lead, $facts, $verifications, $website);

            $lead->forceFill([
                ...$normalisation,
                'enrichment_status' => EnrichmentStatus::Enriched,
                'status' => LeadStatus::Enriched,
                'enriched_at' => now(),
                'enrichment_cost_micros' => $lead->enrichment_cost_micros + $cost,
                'has_personal_data' => $lead->has_personal_data || $hasPersonalData,
            ])->save();

            return $this->deduplicator->deduplicate($lead);
        });

        Log::channel('lead_sourcing')->info('Lead enriched.', [
            'team_id' => $survivor->team_id,
            'lead_id' => $survivor->getKey(),
            'contacts' => $survivor->contacts()->count(),
            'cost_micros' => $cost,
            'has_personal_data' => $survivor->has_personal_data,
        ]);

        return $survivor;
    }

    /**
     * Apply extracted facts to the lead (filling only empty fields, with provenance) and upsert its
     * contacts with email-verification results. Returns whether any contact looks personal.
     *
     * @param  array<string, EmailVerificationResult>  $verifications
     */
    private function applyFacts(Lead $lead, ?ExtractedFacts $facts, array $verifications, ?string $website): bool
    {
        if ($facts === null) {
            return false;
        }

        /** @var array<string, SourcedValue> $sources */
        $sources = $lead->field_sources;

        $fill = function (string $field, mixed $value) use ($lead, &$sources, $website): void {
            if ($this->isEmpty($value) || ! $this->isEmpty($lead->getAttribute($field))) {
                return; // only fill a field the discovery record left empty
            }

            $lead->setAttribute($field, $value);
            $sources[$field] = SourcedValue::fromAiExtract($website);
        };

        $fill('website', $this->normaliser->canonicalUrl($facts->website));
        $fill('industry', $facts->industry);
        $fill('employee_range', $facts->employeeRange);
        $fill('technologies', $facts->technologies);

        $lead->field_sources = $sources;

        return $this->syncContacts($lead, $facts, $verifications, $website);
    }

    /**
     * Upsert one contact per extracted email (verified), attaching the phone to the first, or a
     * phone-only contact when there are no emails. De-duped per lead by email. Returns whether any
     * persisted contact is personal.
     *
     * @param  array<string, EmailVerificationResult>  $verifications
     */
    private function syncContacts(Lead $lead, ExtractedFacts $facts, array $verifications, ?string $website): bool
    {
        $max = (int) config('enrichment.max_contacts_per_lead', 10);
        if ($max <= 0) {
            return false;
        }

        $emails = array_slice($facts->emails, 0, $max);
        $hasPersonal = false;
        $first = true;

        foreach ($emails as $email) {
            $verification = $verifications[$email] ?? $this->verifier->verify($email);

            // Don't persist a syntactically invalid address — it's noise, and it must never become a
            // de-duplication signal.
            if ($verification->status === EmailVerificationStatus::InvalidSyntax) {
                continue;
            }

            $hasPersonal = $hasPersonal || $verification->isPersonal;

            LeadContact::query()->updateOrCreate(
                ['team_id' => $lead->team_id, 'lead_id' => $lead->getKey(), 'email' => $email],
                [
                    // The phone (if any) hangs on the first contact only.
                    'phone' => $first ? $facts->phone : null,
                    'email_verification_status' => $verification->status,
                    'email_confidence' => $verification->confidence,
                    'is_role_based' => $verification->isRoleBased,
                    'is_personal' => $verification->isPersonal,
                    'source' => SourcedValue::METHOD_AI_EXTRACT,
                    'source_url' => $website,
                    'method' => SourcedValue::METHOD_AI_EXTRACT,
                    'collected_at' => now(),
                ],
            );

            $first = false;
        }

        // A phone number but no email to hang it on → a phone-only contact.
        if ($facts->phone !== null && $emails === []) {
            LeadContact::query()->updateOrCreate(
                ['team_id' => $lead->team_id, 'lead_id' => $lead->getKey(), 'email' => null],
                [
                    'phone' => $facts->phone,
                    'source' => SourcedValue::METHOD_AI_EXTRACT,
                    'source_url' => $website,
                    'method' => SourcedValue::METHOD_AI_EXTRACT,
                    'collected_at' => now(),
                ],
            );
        }

        return $hasPersonal;
    }

    private function markFailed(Lead $lead, Throwable $exception): Lead
    {
        DB::transaction(fn () => $lead->forceFill(['enrichment_status' => EnrichmentStatus::Failed])->save());

        Log::channel('lead_sourcing')->warning('Lead enrichment failed.', [
            'team_id' => $lead->team_id,
            'lead_id' => $lead->getKey(),
            'exception' => $exception->getMessage(),
        ]);

        return $lead;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
