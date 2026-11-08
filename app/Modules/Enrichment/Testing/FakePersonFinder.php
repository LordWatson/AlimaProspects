<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Testing;

use App\Modules\Enrichment\Contracts\PersonFinder;
use App\Modules\Enrichment\Data\CompanyQuery;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\FoundPerson;
use App\Modules\Enrichment\Data\PeopleResult;
use App\Modules\Enrichment\Data\PersonCriteria;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A deterministic {@see PersonFinder} for tests and local demo seeding (PL2).
 *
 * **It honours the criteria's `limit`**, which is the one behaviour a fake here must not fake away:
 * the limit is the money, and a test proving the orchestration respects it is worthless against a
 * fake that returned everything regardless. It records the criteria it was given for the same
 * reason.
 */
final class FakePersonFinder implements PersonFinder
{
    /** @var list<array{people: list<FoundPerson>, error: EnrichmentProviderException|null}> */
    private array $queue = [];

    /** @var list<array{company: CompanyQuery, criteria: PersonCriteria}> */
    public private(set) array $queries = [];

    public function __construct(
        private readonly string $key = 'fake_person_finder',
        private readonly bool $enabled = true,
        private readonly CostProfile $cost = new CostProfile,
    ) {}

    /**
     * @param  list<FoundPerson>  $people
     */
    public function willFind(array $people): self
    {
        $this->queue[] = ['people' => $people, 'error' => null];

        return $this;
    }

    public function willMiss(): self
    {
        $this->queue[] = ['people' => [], 'error' => null];

        return $this;
    }

    public function willFail(?EnrichmentProviderException $exception = null): self
    {
        $this->queue[] = [
            'people' => [],
            'error' => $exception ?? EnrichmentProviderException::unavailable($this->key),
        ];

        return $this;
    }

    public function findPeople(CompanyQuery $company, PersonCriteria $criteria): PeopleResult
    {
        $this->queries[] = ['company' => $company, 'criteria' => $criteria];

        $next = array_shift($this->queue);

        if ($next !== null && $next['error'] instanceof EnrichmentProviderException) {
            throw $next['error'];
        }

        // The limit is the whole cost control, so the fake respects it like a real vendor would.
        $people = array_slice($next['people'] ?? [], 0, max(0, $criteria->limit));

        return new PeopleResult(
            provider: $this->key,
            people: $people,
            costMicros: $this->cost->forOutcome($people !== []),
        );
    }

    public function callCount(): int
    {
        return count($this->queries);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function capability(): ProviderCapability
    {
        return ProviderCapability::PeopleFind;
    }

    public function describesCost(): CostProfile
    {
        return $this->cost;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
