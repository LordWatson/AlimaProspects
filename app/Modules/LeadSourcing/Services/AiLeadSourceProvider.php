<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\AiPlatform\Exceptions\UnknownPromptException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Scoring\Services\DeterministicScorer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Web search, read by a model (ADR 0035). It runs the run's search angles through
 * {@see WebSearchProvider}, hands the real results to `lead_sourcing.ai_discovery`, and keeps only
 * the companies the model can point to a result for.
 *
 * It used to name companies from what the model remembered, with nothing to check a name against:
 * a fabricated company was a fabricated prospect, and the failure looked exactly like a real lead.
 * **There is no recall path left.** What the model adds over a plain web search is a reading of
 * each result — is this a company or an article, what trade is it in, would it buy from this
 * business — and the two fields no index fills, a trade and (where the result states one) a
 * headcount, which are what `Scoring\Services\DeterministicScorer` grades on.
 *
 * Four things about it that are not negotiable:
 *
 * 1. **A named company must trace to a result.** Each company cites the number of the result it
 *    came from; a citation that was not offered is dropped and counted, and the website stored is
 *    the cited result's own address, never text the model wrote.
 * 2. **It is still the least authoritative source there is** ({@see LeadSource::rank()} = 0). It is
 *    a reading of a page, so every other source corrects its fields on a merge and Enrichment
 *    treats the name it stored as provisional.
 * 3. **No named individual, ever** (ADR 0025). The schema has no person on it.
 * 4. **A failure fails the run.** Unlike {@see DiscoveryQueryShaper}, where AI improves on a
 *    deterministic query, here the AI *is* the source: an exhausted allowance or a transport error
 *    becomes a {@see LeadSourcingException} rather than an empty list that reads as "nobody
 *    matches you". Search results that contain no fitting company are an honest empty list.
 */
final class AiLeadSourceProvider implements LeadSourceProvider
{
    public const string PROMPT_KEY = 'lead_sourcing.ai_discovery';

    public function __construct(
        private readonly AiClient $ai,
        private readonly WebSearchProvider $web,
        private readonly DiscoveryPayloadBuilder $payload,
    ) {}

    public function source(): LeadSource
    {
        return LeadSource::Ai;
    }

    public function discover(DiscoveryQuery $query): array
    {
        $terms = trim($query->terms);

        if ($terms === '') {
            return [];
        }

        // Capped independently of the run's limit, because here the count is an **output-token**
        // cost: every extra company is another object the model writes. The run's own limit still
        // applies, and the job slices to it afterwards.
        $wanted = max(1, min($query->limit, (int) config('lead-sourcing.providers.ai.max_results', 20)));

        // The real results the model will read. The shaper's web angles where there are any; the
        // member's own terms otherwise. More results than companies wanted, because a good share
        // of any result page is articles and lists the model is told to skip.
        $results = $this->web->discover(new DiscoveryQuery(
            source: LeadSource::WebSearch,
            terms: $query->queries[0] ?? $terms,
            location: $query->location,
            limit: (int) config('lead-sourcing.providers.ai.max_search_results', 30),
            excludeTerms: $query->excludeTerms,
            queries: array_slice($query->queries, 1),
        ));

        if ($results === []) {
            return [];
        }

        try {
            $response = $this->ai->structured(self::PROMPT_KEY, [
                // The team in context, filtered explicitly; no context means no profile.
                'seller' => $this->payload->businessBlock(BusinessProfile::current()),
                // Wrapped even though it is first-party-ish: the terms are either a member's free
                // text or a previous model's output, and neither is an instruction to this one.
                'terms' => UntrustedContent::from($terms),
                'location' => UntrustedContent::from($query->location ?? 'anywhere — no location was given'),
                'customer_description' => UntrustedContent::from($query->customerDescription ?? 'not stated'),
                'exclude_terms' => UntrustedContent::from(
                    $query->excludeTerms === [] ? 'none' : implode(', ', $query->excludeTerms),
                ),
                // Other people's web pages: data, never instructions.
                'results' => UntrustedContent::from(implode("\n", array_map(
                    static fn (SourcedLead $result, int $i): string => sprintf(
                        '%d. %s — %s — %s',
                        $i + 1,
                        Str::limit((string) ($result->raw['title'] ?? $result->companyName), 150, ''),
                        (string) $result->domain(),
                        Str::limit(Str::squish((string) ($result->raw['snippet'] ?? '')), 300, ''),
                    ),
                    $results,
                    array_keys($results),
                ))),
                'limit' => (string) $wanted,
            ]);
        } catch (AiCreditExceededException) {
            // Terminal, and its own message: "you have used up your AI allowance" and "you have used
            // up your lead allowance" are different things to do something about.
            throw LeadSourcingException::aiAllowanceExhausted();
        } catch (UnknownPromptException $e) {
            // **Terminal, and a different kind of failure from an outage.** The prompt is seeded
            // reference data, so this says the row is not in this database, which no retry can
            // change. The fix is `db:seed --class=AiPromptTemplateSeeder`, so say so in the log.
            Log::channel('lead_sourcing')->error('AI lead discovery is not configured: the prompt is not seeded.', [
                'prompt_key' => self::PROMPT_KEY,
                'error_code' => $e->errorCode(),
            ]);

            throw LeadSourcingException::providerMisconfigured($this->source()->value);
        } catch (AiPlatformException $e) {
            // The class and the code, never the message: a transport error can quote back part of the
            // request, and the request contains the team's own description of its customers.
            Log::channel('lead_sourcing')->warning('AI lead discovery failed.', [
                'icp_id' => $query->icpId,
                'exception' => $e::class,
                'error_code' => $e->errorCode(),
            ]);

            // Retryable: the queue re-runs it. An invalid response arrives here too, after the
            // client has already retried it once against the schema.
            throw LeadSourcingException::providerFailed($this->source()->value);
        }

        $companies = $response->get('companies', []);

        if (! is_array($companies)) {
            return [];
        }

        $leads = [];
        $seen = [];
        $ungrounded = 0;

        foreach ($companies as $company) {
            if (! is_array($company)) {
                continue;
            }

            // The grounding, enforced here rather than trusted to the prompt: a company that cites
            // a result it was never given is the model recalling, and is not stored.
            $index = $company['source_index'] ?? null;
            $result = is_int($index) ? ($results[$index - 1] ?? null) : null;

            if (! $result instanceof SourcedLead) {
                $ungrounded++;

                continue;
            }

            $lead = $this->mapCompany($company, $result, $response->promptVersion, $response->model);

            if (! $lead instanceof SourcedLead) {
                continue;
            }

            // Two companies read out of one result, or one company found by two results, is a real
            // failure mode. Collapsing on the identity key the job de-duplicates by keeps one rule.
            $key = $lead->dedupeKey();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $leads[] = $lead;

            if (count($leads) >= $wanted) {
                break;
            }
        }

        Log::channel('lead_sourcing')->info('AI lead discovery completed.', [
            'icp_id' => $query->icpId,
            'prompt_version' => $response->promptVersion,
            'model' => $response->model,
            'cost_micros' => $response->costMicros,
            'cache_hit' => $response->cacheHit,
            'results_read' => count($results),
            'companies' => count($leads),
            // Worth watching: a model that starts naming from memory shows up here first.
            'ungrounded_dropped' => $ungrounded,
        ]);

        return $leads;
    }

    public function costMicros(int $leadsReturned): int
    {
        // The **search** spend only. The model call's cost is already recorded, per team and per
        // model, on the `ai_requests` ledger — which is where `PlatformRollup` reads Claude spend
        // from — so repeating it here would count one request twice in the platform margin.
        return $this->web->costMicros($leadsReturned);
    }

    /**
     * Map one company the model picked into a {@see SourcedLead}. Identity comes from the search
     * result it cited — the website and the source URL are the result's own — and only the reading
     * (a trading name, a trade, a place) comes from the model.
     *
     * @param  array<array-key, mixed>  $company
     */
    private function mapCompany(array $company, SourcedLead $result, int $promptVersion, string $model): ?SourcedLead
    {
        $name = $this->text($company['company_name'] ?? null);

        if ($name === null) {
            return null;
        }

        return new SourcedLead(
            source: LeadSource::Ai,
            companyName: $name,
            website: $result->website,
            industry: $this->text($company['industry'] ?? null),
            employeeRange: $this->employeeRange($company['employee_range'] ?? null),
            locality: $this->text($company['locality'] ?? null),
            region: $this->text($company['region'] ?? null),
            country: $this->country($company['country'] ?? null),
            sourceUrl: $result->sourceUrl,
            // The claim and the page it was read from, kept together: a lead from this source
            // exists because this prompt version of this model read that result that way.
            raw: [
                'claim' => $company,
                'result' => array_intersect_key($result->raw, array_flip(['title', 'link', 'snippet', 'query'])),
                'snippet' => $result->raw['snippet'] ?? null,
                'prompt_key' => self::PROMPT_KEY,
                'prompt_version' => $promptVersion,
                'model' => $model,
            ],
        );
    }

    /**
     * An ISO 3166-1 alpha-2 country code, or null.
     *
     * Dropped rather than coerced, because `leads.country` is a two-character column and
     * {@see SourcedLead} fills it by truncation: "United Kingdom" asked for would be stored as "UN",
     * which matches no geography criterion and reads as a country to anyone looking at the row.
     */
    private function country(mixed $value): ?string
    {
        $country = $this->text($value);

        return $country !== null && preg_match('/^[a-z]{2}$/i', $country) === 1
            ? Str::upper($country)
            : null;
    }

    /**
     * A plausible headcount band ("11-50", "200-500", "1,000+", "50"), or null.
     *
     * Dropped rather than stored when it is not one: the schema types this field as a free string
     * with no bound, and a run of broken JSON once bled into it, cleared schema validation and
     * overflowed the 64-char column. A wrong headcount also mis-grades the lead
     * ({@see DeterministicScorer} reads it for the Company-size criterion), so "not stated" is the
     * honest value. The company is kept.
     */
    private function employeeRange(mixed $value): ?string
    {
        $range = $this->text($value);

        return $range !== null
            && preg_match('/^\d[\d,]*(\s*[-–]\s*\d[\d,]*|\+)?\s*(employees?|staff|people|fte)?$/i', $range) === 1
            ? $range
            : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
