<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\LeadSourcing\Actions\StartDiscoveryRunAction;
use App\Modules\LeadSourcing\Enums\LeadSource;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The resolved, provider-agnostic query a discovery run executes. Built once by
 * {@see StartDiscoveryRunAction} from the member's input and the
 * active ICP, stored on the run's `params`, and handed to the provider. Deterministic — no AI in
 * Phase 7 (AI query shaping can slot in later without changing this shape).
 */
final class DiscoveryQuery extends Data
{
    /**
     * @param  list<string>  $industries  ICP industry signals used to bias/seed the search
     */
    public function __construct(
        public LeadSource $source,
        public string $terms,
        public ?string $location = null,
        public int $limit = 25,
        public ?int $icpId = null,
        public array $industries = [],
    ) {}

    /**
     * Derive a query from the active ICP: free-text search terms come from the explicit input if
     * given, otherwise from the ICP's industry signals; location likewise from input or the ICP's
     * geography signals. Keeps Phase 7 deterministic and transparent.
     */
    public static function fromIcp(
        LeadSource $source,
        ?Icp $icp,
        int $limit,
        ?string $terms = null,
        ?string $location = null,
    ): self {
        $industries = $icp instanceof Icp ? self::valuesOfType($icp, 'industry') : [];
        $geographies = $icp instanceof Icp ? self::valuesOfType($icp, 'geography') : [];

        $resolvedTerms = self::clean($terms) ?? implode(' ', $industries);
        $resolvedLocation = self::clean($location) ?? ($geographies[0] ?? null);

        return new self(
            source: $source,
            terms: Str::limit(trim($resolvedTerms), 200, ''),
            location: $resolvedLocation,
            limit: $limit,
            icpId: $icp instanceof Icp ? (int) $icp->getKey() : null,
            industries: $industries,
        );
    }

    /**
     * Rebuild from a run's stored `params` (so the job can re-derive the query without the ICP).
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromParams(LeadSource $source, array $params): self
    {
        /** @var list<string> $industries */
        $industries = array_values(array_filter(
            (array) ($params['industries'] ?? []),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        return new self(
            source: $source,
            terms: (string) ($params['terms'] ?? ''),
            location: isset($params['location']) && is_string($params['location']) ? $params['location'] : null,
            limit: (int) ($params['limit'] ?? 25),
            icpId: isset($params['icp_id']) ? (int) $params['icp_id'] : null,
            industries: $industries,
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
        ];
    }

    /**
     * The positive-signal values of a given criterion type from an ICP's loaded criteria.
     *
     * @return list<string>
     */
    private static function valuesOfType(Icp $icp, string $type): array
    {
        return array_values($icp->criteria
            ->filter(static fn (IcpCriterion $c): bool => $c->type->value === $type)
            ->map(static fn (IcpCriterion $c): string => trim($c->value))
            ->filter(static fn (string $v): bool => $v !== '')
            ->all());
    }

    private static function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
