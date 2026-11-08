<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Data\ExtractedFacts;
use App\Modules\Enrichment\Data\InferredContact;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\CompanyNormaliser;
use App\Modules\Enrichment\Services\EmailPatternInferrer;
use App\Modules\Enrichment\Services\EmailVerifier;
use App\Modules\Enrichment\Services\LeadDeduplicator;
use App\Modules\Enrichment\Services\LeadReadiness;
use App\Modules\Enrichment\Services\WebsiteTextGatherer;
use App\Modules\LeadSourcing\Contracts\CompanyWebsiteResolver;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enriches a single discovered {@see Lead} (Phase 8): normalises its company fields, extracts
 * structured facts from its public web text via the AI (`lead.enrich_extract`, cheap tier), derives
 * and email-verifies contacts, records per-field provenance + a data-freshness stamp, flags personal
 * data for compliance, and de-duplicates it against the team's other leads keeping the best source
 * per field.
 *
 * Four things widen how much it can find, each conditional and config-gated:
 *
 * 1. **Website resolution** — a lead with no website (every Companies House record, many Places ones)
 *    would otherwise have nothing to enrich. Before giving up, it asks {@see CompanyWebsiteResolver}
 *    for a candidate homepage and trusts it only if the fetched page is about this company
 *    ({@see self::siteConfirmsCompany()}) — a search returns competitors and directories for the
 *    same words, so an unconfirmed site is discarded rather than pinned to the wrong company.
 * 2. **Multi-page gather** — {@see WebsiteTextGatherer} reads the homepage plus the conventional
 *    contact/about pages (where emails live) and extracts over the lot in one call.
 * 3. **The discovered phone** — a company switchboard number a provider stored at discovery
 *    (Google Places) becomes a contact when the page states none.
 * 4. **Inferred contacts** — when the page names people but states no routable email,
 *    {@see EmailPatternInferrer} guesses one address per person from the domain, keeps the ones the
 *    domain can route to, and stores them flagged `is_inferred` with a capped confidence — a guess
 *    (no SMTP probe, ADR 0008), so it earns nothing toward readiness and is flagged personal.
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
        private readonly WebsiteTextGatherer $gatherer,
        private readonly CompanyWebsiteResolver $websiteResolver,
        private readonly EmailVerifier $verifier,
        private readonly EmailPatternInferrer $inferrer,
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

        // (1) Resolve a homepage for a website-less lead — best-effort, confirmed below before it is
        // trusted. Never fails the run: the resolver returns null for a disabled/unconfigured
        // provider or any failure.
        $resolvedWebsite = null;

        if ($website === null && (bool) config('enrichment.resolve_websites', true)) {
            $candidate = $this->websiteResolver->resolve($lead->company_name, $this->leadPlace($lead));

            if ($candidate !== null) {
                $website = $candidate;
                $resolvedWebsite = $candidate;
            }
        }

        // --- External work: fetch public web text + extract facts (outside any transaction) ---
        $facts = null;
        $cost = 0;
        $verifications = [];
        /** @var list<InferredContact> $inferred */
        $inferred = [];

        // (2) Gather the homepage plus the conventional contact/about pages in one pass.
        $webText = $this->gatherer->gather($website);

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

            // (1) A resolved site is trusted only if the page is about this company. If it isn't, the
            // search found a competitor or a directory — drop the site and its facts rather than pin
            // a wrong domain. The extract was still paid for, so `$cost` is kept (the ledger is honest).
            if ($resolvedWebsite !== null && ! $this->siteConfirmsCompany($lead, $facts, $resolvedWebsite)) {
                Log::channel('lead_sourcing')->info('Resolved website rejected — company did not match.', [
                    'team_id' => $lead->team_id,
                    'lead_id' => $lead->getKey(),
                    'candidate' => $resolvedWebsite,
                ]);

                $facts = null;
                $website = null;
                $resolvedWebsite = null;
            }

            if ($facts !== null) {
                // Verify any extracted emails (DNS, still outside any transaction).
                foreach ($facts->emails as $email) {
                    $verifications[$email] = $this->verifier->verify($email);
                }

                // (4) Infer a contact per named person when the page stated no routable email.
                if ($this->shouldInfer($facts, $verifications)) {
                    $inferred = $this->inferContacts($facts, $website, $verifications);
                }
            }
        }

        // --- Writes ---
        $survivor = DB::transaction(function () use ($lead, $normalisation, $facts, $verifications, $inferred, $cost, $website, $resolvedWebsite): Lead {
            // (1) Set the confirmed resolved website first — with its own provenance — so `applyFacts`
            // sees it filled and leaves it, and the final forceFill keeps it rather than re-applying
            // the null the normaliser computed before resolution ran.
            if ($resolvedWebsite !== null) {
                $lead->website = $resolvedWebsite;
                $normalisation['website'] = $resolvedWebsite;
                $sources = $lead->field_sources;
                $sources['website'] = SourcedValue::fromWebResolved($resolvedWebsite);
                $lead->field_sources = $sources;
            }

            $hasPersonalData = $this->applyFacts($lead, $facts, $verifications, $website);
            // (4) Persist the inferred contacts (pre-verified above; no DNS inside the transaction).
            $hasPersonalData = $this->applyInferred($lead, $inferred, $verifications) || $hasPersonalData;

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
            // No facts (no site, or a resolved site we rejected), but a provider may have given us a
            // switchboard number at discovery — keep it as a phone-only contact so the lead isn't
            // left with nothing to reach.
            $this->phoneOnlyContact($lead, $lead->phone, $lead->source_url, SourcedValue::METHOD_PROVIDER);

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

        // The phone the page stated, or — failing that — the switchboard number a provider gave us at
        // discovery (Google Places). Either way it hangs on the first contact only.
        $phone = $facts->phone ?? $lead->phone;

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
                    'phone' => $first ? $phone : null,
                    'email_verification_status' => $verification->status,
                    'email_confidence' => $verification->confidence,
                    'is_role_based' => $verification->isRoleBased,
                    'is_personal' => $verification->isPersonal,
                    'is_inferred' => false,
                    'source' => SourcedValue::METHOD_AI_EXTRACT,
                    'source_url' => $website,
                    'method' => SourcedValue::METHOD_AI_EXTRACT,
                    'collected_at' => now(),
                ],
            );

            $first = false;
        }

        // A phone number but no email to hang it on → a phone-only contact. The method reflects where
        // the number came from: the page (ai_extract) or the discovery provider (provider).
        if ($emails === []) {
            $this->phoneOnlyContact(
                $lead,
                $phone,
                $website ?? $lead->source_url,
                $facts->phone !== null ? SourcedValue::METHOD_AI_EXTRACT : SourcedValue::METHOD_PROVIDER,
            );
        }

        return $hasPersonal;
    }

    /**
     * Upsert the lead's one phone-only contact (email null) when there is a number but no email to
     * hang it on. A no-op when `$phone` is null. Not personal data — a switchboard line is the
     * company's, not a named individual's.
     */
    private function phoneOnlyContact(Lead $lead, ?string $phone, ?string $sourceUrl, string $method): void
    {
        if ($phone === null || trim($phone) === '') {
            return;
        }

        LeadContact::query()->updateOrCreate(
            ['team_id' => $lead->team_id, 'lead_id' => $lead->getKey(), 'email' => null],
            [
                'phone' => $phone,
                'is_role_based' => false,
                'is_personal' => false,
                'is_inferred' => false,
                'source' => $method,
                'source_url' => $sourceUrl,
                'method' => $method,
                'collected_at' => now(),
            ],
        );
    }

    /**
     * Whether to infer contact emails (4): the step is enabled, the page named people, and nothing
     * it stated is a routable email. A page that already gave a routable address is not the problem
     * inference solves, so we don't spend a guess on it.
     *
     * @param  array<string, EmailVerificationResult>  $verifications
     */
    private function shouldInfer(ExtractedFacts $facts, array $verifications): bool
    {
        if (! (bool) config('enrichment.infer_emails', true) || $facts->people === []) {
            return false;
        }

        foreach ($verifications as $verification) {
            if ($verification->status === EmailVerificationStatus::MxFound) {
                return false; // we already have somewhere routable to write
            }
        }

        return true;
    }

    /**
     * Build and verify inferred contacts for the people on the site, keeping only the guesses the
     * company domain can actually route to. Runs outside the writes transaction (it does DNS).
     *
     * @param  array<string, EmailVerificationResult>  $verifications
     * @return list<InferredContact>
     */
    private function inferContacts(ExtractedFacts $facts, ?string $website, array &$verifications): array
    {
        $domain = $this->domainOf($website);

        if ($domain === null) {
            return [];
        }

        $kept = [];

        foreach ($this->inferrer->infer($domain, $facts->people) as $candidate) {
            $verification = $this->verifier->verify($candidate->email);

            // An inferred address is only worth keeping if the domain accepts mail — otherwise it is
            // a guess at a mailbox on a domain that can't receive one.
            if ($verification->status !== EmailVerificationStatus::MxFound) {
                continue;
            }

            $verifications[$candidate->email] = $verification;
            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * Persist the pre-verified inferred contacts, flagged `is_inferred` with a capped confidence.
     * Returns whether any were stored (all are personal data — a named person's address). Uses the
     * verifications computed in {@see self::inferContacts()}; never does DNS inside the transaction.
     *
     * @param  list<InferredContact>  $inferred
     * @param  array<string, EmailVerificationResult>  $verifications
     */
    private function applyInferred(Lead $lead, array $inferred, array $verifications): bool
    {
        if ($inferred === []) {
            return false;
        }

        $max = (int) config('enrichment.max_contacts_per_lead', 10);
        $cap = (int) config('enrichment.inferred_email_confidence', 25);
        $count = $lead->contacts()->count();
        $stored = false;

        foreach ($inferred as $candidate) {
            if ($count >= $max) {
                break;
            }

            $verification = $verifications[$candidate->email] ?? null;

            if ($verification === null) {
                continue; // must have been verified outside the transaction
            }

            LeadContact::query()->updateOrCreate(
                ['team_id' => $lead->team_id, 'lead_id' => $lead->getKey(), 'email' => $candidate->email],
                [
                    'name' => $candidate->name,
                    'title' => $candidate->title,
                    'phone' => null,
                    'email_verification_status' => $verification->status,
                    // A guess, not a found address: never let it read as confidently as one.
                    'email_confidence' => min($verification->confidence, $cap),
                    'is_role_based' => false,
                    'is_personal' => true,
                    'is_inferred' => true,
                    'source' => SourcedValue::METHOD_AI_INFERRED,
                    'source_url' => $lead->website,
                    'method' => SourcedValue::METHOD_AI_INFERRED,
                    'collected_at' => now(),
                ],
            );

            $stored = true;
            $count++;
        }

        return $stored;
    }

    /**
     * The place to disambiguate a website search by — the lead's locality, then region, then country.
     */
    private function leadPlace(Lead $lead): ?string
    {
        foreach ([$lead->locality, $lead->region, $lead->country] as $part) {
            if ($part !== null && trim($part) !== '') {
                return trim($part);
            }
        }

        return null;
    }

    /**
     * Whether a resolved website is actually this company's: a confirmation, not a search, because a
     * web search returns competitors and directories for the words that name a business. It compares
     * the lead's distinctive name tokens against the page's own company name and the domain, and is
     * deliberately willing to reject a real site (it fails toward *not* pinning a wrong one) — the
     * threshold is `enrichment.resolve_match_threshold`.
     */
    private function siteConfirmsCompany(Lead $lead, ExtractedFacts $facts, string $website): bool
    {
        $leadTokens = $this->nameTokens($lead->company_name);

        if ($leadTokens === []) {
            return false; // nothing distinctive to match on — refuse rather than guess
        }

        $haystack = ' '.implode(' ', $this->nameTokens((string) $facts->companyName))
            .' '.Str::lower((string) $this->domainOf($website)).' ';

        $hits = 0;
        foreach ($leadTokens as $token) {
            if (str_contains($haystack, $token)) {
                $hits++;
            }
        }

        $threshold = (float) config('enrichment.resolve_match_threshold', 0.5);

        return $hits > 0 && ($hits / count($leadTokens)) >= $threshold;
    }

    /**
     * A company name reduced to its distinctive tokens: the bare name key (legal forms removed),
     * split on the slug separator, keeping tokens of three characters or more and de-duplicated.
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $bare = CompanyNameKey::bare($name);

        if ($bare === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode('-', $bare),
            static fn (string $token): bool => strlen($token) >= 3,
        )));
    }

    /**
     * The bare registrable domain of a URL, lower-cased and without a leading `www.`, or null.
     */
    private function domainOf(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $host = parse_url(Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return Str::lower(Str::replaceStart('www.', '', $host));
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
