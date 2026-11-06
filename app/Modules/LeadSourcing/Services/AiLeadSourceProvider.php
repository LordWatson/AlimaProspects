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
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asks the model itself which companies fit the team's customer description, instead of asking an
 * index what it holds. The only {@see LeadSourceProvider} with no HTTP of its own: the transport,
 * the retries, the credit allowance, the response validation and the per-team cost ledger all
 * belong to {@see AiClient} (ADR 0005), so there is nothing here but the prompt and the mapping.
 *
 * Why it exists, in one line: the index-backed providers return a **name and a website** and nothing
 * else, which leaves the ICP's Industry and Company size criteria Unknown and a discovered lead
 * graded on its geography alone (`Scoring\Services\DeterministicScorer`, rule 2). This source states a trade and
 * a headcount per company, so both halves of the hybrid score have something to judge.
 *
 * Three things about it that are not negotiable:
 *
 * 1. **It is the least authoritative source there is** ({@see LeadSource::rank()} = 0). A model can
 *    be fluently wrong about a company that does not exist, which no register or crawler can be, so
 *    every other source corrects its fields on a merge and Enrichment treats the name it stored as
 *    provisional. A lead from here is a lead to check, and the UI says which source found it.
 * 2. **No named individual, ever** (ADR 0025). The schema has no person on it — not a founder, not a
 *    contact — so there is nothing to filter out later. Company-level facts only.
 * 3. **A failure fails the run.** Unlike {@see DiscoveryQueryShaper}, where AI is an improvement over
 *    a deterministic query, here the AI *is* the source: there is no degraded answer to fall back to,
 *    so an exhausted allowance or a transport error becomes a {@see LeadSourcingException} and the
 *    member is told, rather than handed an empty list that looks like "no companies match you".
 */
final class AiLeadSourceProvider implements LeadSourceProvider
{
    public const string PROMPT_KEY = 'lead_sourcing.ai_discovery';

    public function __construct(private readonly AiClient $ai) {}

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

        try {
            $response = $this->ai->structured(self::PROMPT_KEY, [
                // Wrapped even though it is first-party-ish: the terms are either a member's free
                // text or a previous model's output, and neither is an instruction to this one.
                'terms' => UntrustedContent::from($terms),
                'location' => UntrustedContent::from($query->location ?? 'anywhere — no location was given'),
                'customer_description' => UntrustedContent::from($query->customerDescription ?? 'not stated'),
                'exclude_terms' => UntrustedContent::from(
                    $query->excludeTerms === [] ? 'none' : implode(', ', $query->excludeTerms),
                ),
                'limit' => (string) $wanted,
            ]);
        } catch (AiCreditExceededException) {
            // Terminal, and its own message: "you have used up your AI allowance" and "you have used
            // up your lead allowance" are different things to do something about.
            throw LeadSourcingException::aiAllowanceExhausted();
        } catch (UnknownPromptException $e) {
            // **Terminal, and a different kind of failure from an outage.** The prompt is seeded
            // reference data, so this says the row is not in this database, which no retry can
            // change. Lumping it in with a transport error below spent all three of the job's
            // attempts on it and told the member to try again shortly -- wrong advice, three times
            // over. The fix is `db:seed --class=AiPromptTemplateSeeder`, so say so in the log.
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
            // client has already retried it once against the schema — a second attempt is cheap
            // next to telling a member their AI search found nothing when it never ran.
            throw LeadSourcingException::providerFailed($this->source()->value);
        }

        $companies = $response->get('companies', []);

        if (! is_array($companies)) {
            return [];
        }

        Log::channel('lead_sourcing')->info('AI lead discovery completed.', [
            'icp_id' => $query->icpId,
            'prompt_version' => $response->promptVersion,
            'model' => $response->model,
            'cost_micros' => $response->costMicros,
            'cache_hit' => $response->cacheHit,
            'companies' => count($companies),
        ]);

        $leads = [];
        $seen = [];

        foreach ($companies as $company) {
            if (! is_array($company)) {
                continue;
            }

            $lead = $this->mapCompany($company, $response->promptVersion, $response->model);

            if (! $lead instanceof SourcedLead) {
                continue;
            }

            // The same company twice in one answer is a real failure mode of a generative list.
            // Collapsing on the identity key the job de-duplicates by keeps one rule, not two.
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

        return $leads;
    }

    public function costMicros(int $leadsReturned): int
    {
        // **Zero on purpose, and not because it is free.** The call's real cost is already recorded,
        // per team and per model, on the `ai_requests` ledger by the AI Platform — which is where
        // `PlatformRollup` reads Claude spend from. Repeating it on `discovery_runs.cost_micros`
        // would count one request twice in the platform margin, under two provider dimensions.
        return 0;
    }

    /**
     * Map one company object into a {@see SourcedLead}, keeping the model's own claim as the row's
     * provenance: this lead exists because this prompt version of this model said so, and a stored
     * reason is what lets a member judge it without re-running anything.
     *
     * @param  array<array-key, mixed>  $company
     */
    private function mapCompany(array $company, int $promptVersion, string $model): ?SourcedLead
    {
        $name = $this->text($company['company_name'] ?? null);

        if ($name === null) {
            return null;
        }

        return new SourcedLead(
            source: LeadSource::Ai,
            companyName: $name,
            website: $this->website($company['website'] ?? null),
            industry: $this->text($company['industry'] ?? null),
            employeeRange: $this->text($company['employee_range'] ?? null),
            locality: $this->text($company['locality'] ?? null),
            region: $this->text($company['region'] ?? null),
            country: $this->country($company['country'] ?? null),
            // No `source_url`: there is no page this came off. The source label is the attribution,
            // and `raw` carries the claim it was made on.
            raw: [
                'claim' => $company,
                'prompt_key' => self::PROMPT_KEY,
                'prompt_version' => $promptVersion,
                'model' => $model,
            ],
        );
    }

    /**
     * A bare http(s) homepage, or null. {@see SourcedLead} drops anything that is not http(s)
     * anyway; prefixing a scheme-less domain here is what lets the **domain** dedupe key form, so an
     * AI lead collapses with the web-search or Places record of the same company instead of becoming
     * a second row keyed by its name.
     */
    private function website(mixed $value): ?string
    {
        $website = $this->text($value);

        if ($website === null) {
            return null;
        }

        if (Str::startsWith(Str::lower($website), ['http://', 'https://'])) {
            return $website;
        }

        // A plausible bare host — with a path allowed, since "acme.com/about" is a domain the model
        // has added a page to rather than a value to throw away — and not a sentence about one.
        return preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $website) === 1 ? 'https://'.$website : null;
    }

    /**
     * An ISO 3166-1 alpha-2 country code, or null.
     *
     * Dropped rather than coerced, because {@see Lead::$country} is a two-character column and
     * {@see SourcedLead} fills it by truncation: "United Kingdom" asked for would be stored as "UN",
     * which matches no geography criterion and reads as a country to anyone looking at the row. The
     * prompt asks for the code; anything else is treated as not stated.
     */
    private function country(mixed $value): ?string
    {
        $country = $this->text($value);

        return $country !== null && preg_match('/^[a-z]{2}$/i', $country) === 1
            ? Str::upper($country)
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
