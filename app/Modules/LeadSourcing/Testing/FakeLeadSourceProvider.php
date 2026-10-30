<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Testing;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use Throwable;

/**
 * A configurable in-memory {@see LeadSourceProvider} for tests, so the whole discovery pipeline
 * (action → job → persistence → progress) runs without touching the network. Register it on the
 * {@see LeadSourceProviderRegistry} in place of the real provider.
 */
final class FakeLeadSourceProvider implements LeadSourceProvider
{
    /** @var list<SourcedLead> */
    private array $leads = [];

    private ?Throwable $failure = null;

    private int $costMicros = 0;

    private ?DiscoveryQuery $lastQuery = null;

    public function __construct(private readonly LeadSource $source = LeadSource::CompaniesHouse) {}

    /**
     * Queue the leads a discovery call should return (capped to the query limit, as a real provider).
     *
     * @param  list<SourcedLead>  $leads
     */
    public function willReturn(array $leads): self
    {
        $this->leads = $leads;

        return $this;
    }

    /**
     * Make the next discovery call throw (to exercise the job's failure path).
     */
    public function willFail(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function withCostMicros(int $costMicros): self
    {
        $this->costMicros = $costMicros;

        return $this;
    }

    public function source(): LeadSource
    {
        return $this->source;
    }

    public function discover(DiscoveryQuery $query): array
    {
        $this->lastQuery = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_slice($this->leads, 0, $query->limit);
    }

    public function costMicros(int $leadsReturned): int
    {
        return $this->costMicros;
    }

    public function lastQuery(): ?DiscoveryQuery
    {
        return $this->lastQuery;
    }
}
