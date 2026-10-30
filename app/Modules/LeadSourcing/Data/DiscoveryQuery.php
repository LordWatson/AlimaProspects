<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Services\DiscoveryQueryShaper;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The resolved, provider-agnostic query a discovery run executes. Built once by
 * {@see DiscoveryQueryShaper} from the member's input, the business profile and the active ICP,
 * stored on the run's `params`, and handed to the provider.
 *
 * `excludeTerms` is the half that keeps the results honest: the trade the team is itself in, plus any
 * competitor it has named. Providers that support negative operators pass them to the API; everywhere
 * else {@see LeadCandidateFilter} drops matching results after the fact. Without it a search for a
 * team's customers reliably also returns its competitors, who rank well for exactly the words that
 * describe the work.
 */
final class DiscoveryQuery extends Data
{
    /** Keep the negative list short — a provider's query length is finite and so is its usefulness. */
    private const int MAX_EXCLUSIONS = 8;

    /**
     * @param  list<string>  $industries  ICP industry signals used to bias/seed the search
     * @param  list<string>  $excludeTerms  words marking a result as the wrong kind of company
     * @param  string|null  $customerDescription  who this search is for, in one line, shown to the team
     * @param  bool  $shaped  whether an AI pass wrote `terms`, or the deterministic fallback did
     */
    public function __construct(
        public LeadSource $source,
        public string $terms,
        public ?string $location = null,
        public int $limit = 25,
        public ?int $icpId = null,
        public array $industries = [],
        public array $excludeTerms = [],
        public ?string $customerDescription = null,
        public bool $shaped = false,
    ) {}

    /**
     * Derive a query from the active ICP **deterministically** — the fallback used when AI shaping is
     * switched off, has no profile to work from, or fails. Free-text search terms come from the
     * explicit input if given, otherwise from the ICP's industry signals; location likewise from
     * input or the ICP's geography signals; the ICP's exclusions always become negative terms.
     *
     * Note what this cannot do: it can only search the words already in the ICP. If those name the
     * team's own trade, this will faithfully search for the team's own competitors — which is exactly
     * what used to happen. {@see DiscoveryQueryShaper} exists to invert seller into buyer first; this
     * stays as the predictable floor beneath it.
     */
    public static function fromIcp(
        LeadSource $source,
        ?Icp $icp,
        int $limit,
        ?string $terms = null,
        ?string $location = null,
    ): self {
        $industries = $icp instanceof Icp ? self::valuesOfType($icp, CriterionType::Industry) : [];
        $geographies = $icp instanceof Icp ? self::valuesOfType($icp, CriterionType::Geography) : [];
        $exclusions = $icp instanceof Icp ? self::valuesOfType($icp, CriterionType::Exclusion) : [];

        $resolvedTerms = self::clean($terms) ?? implode(' ', $industries);
        $resolvedLocation = self::clean($location) ?? ($geographies[0] ?? null);

        return new self(
            source: $source,
            terms: Str::limit(trim($resolvedTerms), 200, ''),
            location: $resolvedLocation,
            limit: $limit,
            icpId: $icp instanceof Icp ? (int) $icp->getKey() : null,
            industries: $industries,
            excludeTerms: self::normaliseExclusions($exclusions),
            shaped: false,
        );
    }

    /**
     * The same query with AI-shaped terms in place of the deterministic ones. The shaper's negative
     * terms are merged on top of the ICP's own exclusions rather than replacing them: a member who
     * typed an exclusion means it regardless of what the model came up with.
     *
     * @param  list<string>  $excludeTerms
     */
    public function withShapedTerms(string $terms, ?string $customerDescription, array $excludeTerms): self
    {
        return new self(
            source: $this->source,
            terms: Str::limit(trim($terms), 200, ''),
            location: $this->location,
            limit: $this->limit,
            icpId: $this->icpId,
            industries: $this->industries,
            excludeTerms: self::normaliseExclusions([...$this->excludeTerms, ...$excludeTerms]),
            customerDescription: self::clean($customerDescription),
            shaped: true,
        );
    }

    /**
     * Rebuild from a run's stored `params` (so the job can re-derive the query without the ICP).
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromParams(LeadSource $source, array $params): self
    {
        $description = $params['customer_description'] ?? null;

        return new self(
            source: $source,
            terms: (string) ($params['terms'] ?? ''),
            location: isset($params['location']) && is_string($params['location']) ? $params['location'] : null,
            limit: (int) ($params['limit'] ?? 25),
            icpId: isset($params['icp_id']) ? (int) $params['icp_id'] : null,
            industries: self::strings($params['industries'] ?? []),
            excludeTerms: self::strings($params['exclude_terms'] ?? []),
            customerDescription: is_string($description) ? self::clean($description) : null,
            shaped: (bool) ($params['shaped'] ?? false),
        );
    }

    /**
     * The storable params for the run row.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'terms' => $this->terms,
            'location' => $this->location,
            'limit' => $this->limit,
            'icp_id' => $this->icpId,
            'industries' => $this->industries,
            'exclude_terms' => $this->excludeTerms,
            'customer_description' => $this->customerDescription,
            'shaped' => $this->shaped,
        ];
    }

    /**
     * The positive-signal values of a given criterion type from an ICP's loaded criteria.
     *
     * @return list<string>
     */
    private static function valuesOfType(Icp $icp, CriterionType $type): array
    {
        return array_values($icp->criteria
            ->filter(static fn (IcpCriterion $c): bool => $c->type === $type)
            ->map(static fn (IcpCriterion $c): string => trim($c->value))
            ->filter(static fn (string $v): bool => $v !== '')
            ->all());
    }

    /**
     * Trim, drop blanks, de-duplicate case-insensitively and cap the negative list.
     *
     * A one- or two-character exclusion is dropped: as a negative search term it would strip almost
     * every result, and as a substring filter it would reject nearly any company name.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function normaliseExclusions(array $values): array
    {
        $seen = [];
        $unique = [];

        foreach ($values as $value) {
            $trimmed = trim($value);

            if (mb_strlen($trimmed) < 3) {
                continue;
            }

            $key = mb_strtolower($trimmed);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = Str::limit($trimmed, 60, '');

            if (count($unique) >= self::MAX_EXCLUSIONS) {
                break;
            }
        }

        return $unique;
    }

    /**
     * Coerce a loosely-typed stored value into a list of non-empty strings.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(
            (array) $value,
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
    }

    private static function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
