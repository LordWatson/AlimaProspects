<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a team's own description of itself into a search for its **customers**.
 *
 * This is the step Phase 7 deliberately left out ("AI query shaping can slot in later without
 * changing this shape"), and the gap had a cost: discovery searched the ICP's industry values
 * verbatim, those values were the team's own trade, and so a graphic-design studio was shown a list
 * of graphic-design studios — its competition, dressed as its pipeline. Inverting seller into buyer
 * is a judgement call, not a string operation, which is why it takes a model.
 *
 * Three rules govern when it runs:
 *
 * 1. **A member who typed terms gets those terms.** Explicit input is an instruction, not a hint.
 * 2. **One shaping call per run, before the fan-out** — the query is shaped per source (each provider
 *    accepts a different kind of query), but never inside the job, so a retry never re-bills it.
 * 3. **It degrades rather than fails.** Every AI error — transport, invalid response, unknown prompt
 *    and exhausted credits alike — falls back to {@see DiscoveryQuery::fromIcp()} and the run
 *    proceeds, because a deterministic search is worth more to the member than an error screen.
 *    Credit exhaustion is caught here too, and logged at info rather than warning: discovery is not
 *    an AI feature from the member's point of view, so it must not die when credits run out. Only
 *    non-AI failures (a bug, a dead database) bubble.
 */
final class DiscoveryQueryShaper
{
    public const string PROMPT_KEY = 'lead_sourcing.shape_query';

    public function __construct(
        private readonly AiClient $ai,
        private readonly DiscoveryPayloadBuilder $payload,
    ) {}

    /**
     * Build the query for one source. Returns the AI-shaped query where possible, the deterministic
     * one otherwise — the caller cannot tell the difference beyond {@see DiscoveryQuery::$shaped}.
     */
    public function shape(
        LeadSource $source,
        ?BusinessProfile $profile,
        ?Icp $icp,
        int $limit,
        ?string $terms = null,
        ?string $location = null,
    ): DiscoveryQuery {
        return $this->shapeAll([$source], $profile, $icp, $limit, $terms, $location)[$source->value];
    }

    /**
     * Build the query for every selected source from **one** model call (prompt v2).
     *
     * Who the customer is does not depend on which provider is asked — only the phrasing does — and
     * v1 paid a reasoning call per provider to re-derive the same answer three times.
     *
     * @param  list<LeadSource>  $sources
     * @return array<string, DiscoveryQuery> keyed by the source's value
     */
    public function shapeAll(
        array $sources,
        ?BusinessProfile $profile,
        ?Icp $icp,
        int $limit,
        ?string $terms = null,
        ?string $location = null,
    ): array {
        $baselines = [];

        foreach ($sources as $source) {
            $baselines[$source->value] = DiscoveryQuery::fromIcp($source, $icp, $limit, $terms, $location);
        }

        $first = reset($baselines);

        if ($first === false || ! $this->shouldShape($terms, $profile)) {
            return $baselines;
        }

        $context = ['sources' => array_keys($baselines), 'icp_id' => $first->icpId];

        try {
            $response = $this->ai->structured(self::PROMPT_KEY, [
                'business_profile' => $this->payload->businessBlock($profile),
                'icp' => $this->payload->icpBlock($icp),
                'provider_briefs' => implode("\n\n", array_map(
                    fn (LeadSource $source): string => $source->label().":\n".$this->payload->providerBrief($source),
                    $sources,
                )),
                'location' => $first->location ?? 'anywhere — no location given',
                'limit' => (string) $limit,
            ]);
        } catch (AiCreditExceededException $e) {
            // Out of credits is a billing state, not a discovery failure. Run the deterministic
            // search; the member keeps their leads and sees the credit warning in the AI surfaces
            // that are actually about AI.
            Log::channel('lead_sourcing')->info('Query shaping skipped — AI credits exhausted.', $context);

            return $baselines;
        } catch (AiPlatformException $e) {
            // The class and code, not the message: a transport error can quote back part of the
            // request, and the request contains the team's description of its own business.
            Log::channel('lead_sourcing')->warning('Query shaping failed — falling back to the ICP terms.', [
                ...$context,
                'exception' => $e::class,
                'error_code' => $e->errorCode(),
            ]);

            return $baselines;
        }

        $description = $this->stringOf($response->get('customer_description'));
        $excludes = $this->stringsOf($response->get('exclude_terms'));
        $webQueries = $this->stringsOf($response->get('web_queries'));
        $register = is_array($response->get('companies_house')) ? $response->get('companies_house') : [];

        Log::channel('lead_sourcing')->info('Queries shaped for discovery.', [
            ...$context,
            'prompt_version' => $response->promptVersion,
            'model' => $response->model,
            'cost_micros' => $response->costMicros,
            'cache_hit' => $response->cacheHit,
            // Logged, never shown to a prospect and never stored on the run. Clamped because it is
            // model prose derived from the team's own profile text, and a log line is not the place
            // for an unbounded one.
            'reasoning' => Str::limit((string) $this->stringOf($response->get('reasoning')), 500),
        ]);

        $shaped = [];

        foreach ($baselines as $key => $baseline) {
            // An empty field is a real answer: the model was asked to return nothing rather than
            // guess at the team's own trade, so we do not know who buys from this business. The
            // Action turns empty terms into `nothingToSearch`, which asks the member for terms.
            // Reinstating the ICP's own industry here is precisely the bug.
            $shaped[$key] = match ($baseline->source) {
                LeadSource::WebSearch => $baseline->withShapedTerms(
                    $webQueries[0] ?? '',
                    $description,
                    $excludes,
                    queries: array_slice($webQueries, 1),
                ),
                LeadSource::GooglePlaces => $baseline->withShapedTerms(
                    (string) $this->stringOf($response->get('google_places')),
                    $description,
                    $excludes,
                ),
                LeadSource::CompaniesHouse => $baseline->withShapedTerms(
                    (string) $this->stringOf($register['name_terms'] ?? null),
                    $description,
                    $excludes,
                    sicCodes: $this->stringsOf($register['sic_codes'] ?? null),
                ),
                // The AI source reads real search results (ADR 0035), so it carries the web angles
                // to search with as well as the brief describing what to pick out of them.
                default => $baseline->withShapedTerms(
                    (string) $this->stringOf($response->get('ai_brief')),
                    $description,
                    $excludes,
                    queries: $webQueries,
                ),
            };
        }

        return $shaped;
    }

    /**
     * Whether to spend a model call on this run.
     *
     * No profile means nothing to invert — the model would be guessing from the ICP alone, which is
     * the untrustworthy half. Explicit terms mean the member has already answered the question.
     */
    private function shouldShape(?string $terms, ?BusinessProfile $profile): bool
    {
        if (! (bool) config('lead-sourcing.query_shaping.enabled', true)) {
            return false;
        }

        if (trim((string) $terms) !== '') {
            return false;
        }

        return $profile instanceof BusinessProfile;
    }

    private function stringOf(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function stringsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
