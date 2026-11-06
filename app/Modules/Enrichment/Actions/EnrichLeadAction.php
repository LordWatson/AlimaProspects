<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Data\ExtractedFacts;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\CompanyNormaliser;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\Enrichment\Services\LeadReadiness;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
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
 * in a terminal enrichment state is left untouched unless `$force` is set. On success it fires
 * {@see LeadEnriched} `afterCommit` for the surviving lead, which is what triggers Scoring (Phase
 * 9). Fetched web content is
 * wrapped in {@see UntrustedContent} — treated as data, never instructions. AI credit exhaustion and
 * malformed output are terminal (mark Failed, no retry); any other error bubbles for the queue.
 */
final class EnrichLeadAction
{
    /**
     * The {@see LeadSource::rank()} at or below which a stored company name is **provisional** — a
     * page title or an article's subject rather than a name the source asserted. See
     * {@see self::correctProvisionalName()}.
     */
    private const int PROVISIONAL_NAME_RANK = 1;

    public function __construct(
        private readonly AiClient $ai,
        private readonly WebContentFetcher $fetcher,
        private readonly EmailVerifier $verifier,
        private readonly CompanyNormaliser $normaliser,
        private readonly LeadDeduplicator $deduplicator,
        private readonly LeadReadiness $readiness,
        private readonly CapabilityGate $capabilities,
    ) {}

    /**
     * Enrich the lead and return the surviving record (de-duplication may merge it into another).
     */
    public function execute(Lead $lead, bool $force = false): Lead
    {
        if (! $force && $lead->enrichment_status->isTerminal()) {
            return $lead;
        }

        // **The capability check, in the Action rather than in middleware** (Phase 19b, ADR 0015).
        // This path is reachable from a queued job and from the scheduler, so there is no request
        // to hang middleware on — ADR 0012's argument, unchanged. The route middleware beside it is
        // the courtesy that gives a member a sentence; this is what makes the refusal true.
        //
        // The lead is returned untouched rather than marked failed: a plan without enrichment is
        // not an enrichment that went wrong, and leaving `enrichment_status` as it was means the
        // lead enriches normally the day the team upgrades.
        $team = Team::query()->find($lead->team_id);

        if ($team instanceof Team && ! $this->capabilities->allows($team, Capability::Enrichment)) {
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

            $survivor = $this->deduplicator->deduplicate($lead);

            // How ready the lead is to be contacted (Q4) — computed **after** de-duplication, on
            // the survivor, because a merge moves contacts across and fills gaps from the loser, so
            // the figure taken before it would be of a record that no longer exists. `load`, not
            // `loadMissing`: the merge has just changed the contacts underneath us.
            $survivor->forceFill([
                'readiness' => $this->readiness->for($survivor->load('contacts')),
                'readiness_at' => now(),
            ])->save();

            // Scoring picks this up to grade the lead against the active ICP. afterCommit so the
            // listener never reads uncommitted state, and only ever for the surviving lead.
            DB::afterCommit(static fn () => event(LeadEnriched::for($survivor)));

            return $survivor;
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
        $fill('locations', $facts->locations);

        $this->correctProvisionalName($lead, $facts, $sources, $website);

        $lead->field_sources = $sources;

        return $this->syncContacts($lead, $facts, $verifications, $website);
    }

    /**
     * Replace a **provisional** company name with the one the company's own homepage states (Q1).
     *
     * `company_name` is the one field enrichment may overwrite rather than fill, because for the
     * weakest sources it was never a company name to begin with: a `web_search` lead's name is a
     * **page title** ("Best Plumbers in Dubai | Reviews") and a `signal` lead's is a subject lifted
     * from an article. The extraction we have already paid for reads the company's own site and
     * states the name properly, so dropping it — which is what happened until Q1 — left a page
     * title on the row for ever, and left de-duplication comparing titles.
     *
     * The rule is {@see LeadSource::rank()}'s, not a new one: only the bottom tier is overwritten.
     * Companies House gives the registered name and Places the business's own listing; a member who
     * typed a name outranks everything. Nothing here can **blank** a name.
     *
     * The dedupe key moves with the name when the key was derived from it, or the lead would keep a
     * key pointing at a name it no longer has and never de-duplicate again. A number- or
     * domain-keyed lead — nearly all of them — is untouched.
     *
     * @param  array<string, SourcedValue>  $sources
     */
    private function correctProvisionalName(Lead $lead, ExtractedFacts $facts, array &$sources, ?string $website): void
    {
        $extracted = trim((string) $facts->companyName);

        if ($extracted === '' || $lead->source->rank() > self::PROVISIONAL_NAME_RANK) {
            return;
        }

        if ($extracted === trim((string) $lead->company_name)) {
            return;
        }

        $renameKey = CompanyNameKey::isNameDerived($lead->dedupe_key);

        $lead->company_name = $extracted;
        $sources['company_name'] = SourcedValue::fromAiExtract($website);

        if ($renameKey) {
            $lead->dedupe_key = CompanyNameKey::dedupeKeyFor($lead->source, $extracted);
        }
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
