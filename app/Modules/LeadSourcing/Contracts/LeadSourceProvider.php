<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Contracts;

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;

/**
 * The seam every lead source sits behind (ADR 0008). An implementation talks to one external
 * provider (Companies House, Google Places, a web-search API, …), maps its response into
 * provider-agnostic {@see SourcedLead}s, and returns them. Implementations MUST respect the
 * provider's ToS + rate limits, bound time, attach source attribution to every record, and never
 * treat fetched content as instructions. New providers register in the
 * {@see LeadSourceProviderRegistry} and need no change to the
 * discovery pipeline.
 */
interface LeadSourceProvider
{
    /**
     * The provider this implementation serves — its {@see LeadSource} identity.
     */
    public function source(): LeadSource;

    /**
     * Run the query and return the discovered company-level leads, capped at {@see DiscoveryQuery::$limit}.
     * MUST throw a {@see LeadSourcingException} (or let an HTTP
     * client exception bubble) on a genuine provider failure so the run is marked failed; it MUST
     * NOT return partial garbage silently.
     *
     * @return list<SourcedLead>
     */
    public function discover(DiscoveryQuery $query): array;

    /**
     * The metered cost, in integer micro-units, of a run that returned the given number of leads.
     * Free providers (Companies House) return 0. Lets the job record cost without the provider
     * touching the DB.
     */
    public function costMicros(int $leadsReturned): int;
}
