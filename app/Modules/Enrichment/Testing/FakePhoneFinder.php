<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Testing;

use App\Modules\Enrichment\Contracts\PhoneFinder;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Enums\PhoneType;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A deterministic {@see PhoneFinder} for tests and local demo seeding (PL2).
 *
 * Carries the {@see PhoneType} with each queued number, because the type is the part the product
 * branches on — a switchboard is a company fact and a mobile is a person's, and a test that could
 * not distinguish them could not test the compliance path at all.
 */
final class FakePhoneFinder implements PhoneFinder
{
    /** @var list<array{value: string|null, type: PhoneType|null, error: EnrichmentProviderException|null}> */
    private array $queue = [];

    /** @var list<PersonQuery> */
    public private(set) array $queries = [];

    public function __construct(
        private readonly string $key = 'fake_phone_finder',
        private readonly bool $enabled = true,
        private readonly CostProfile $cost = new CostProfile,
    ) {}

    public function willFind(string $phone, PhoneType $type = PhoneType::Mobile): self
    {
        $this->queue[] = ['value' => $phone, 'type' => $type, 'error' => null];

        return $this;
    }

    public function willMiss(): self
    {
        $this->queue[] = ['value' => null, 'type' => null, 'error' => null];

        return $this;
    }

    public function willFail(?EnrichmentProviderException $exception = null): self
    {
        $this->queue[] = [
            'value' => null,
            'type' => null,
            'error' => $exception ?? EnrichmentProviderException::unavailable($this->key),
        ];

        return $this;
    }

    public function findPhone(PersonQuery $query): EnrichmentResult
    {
        $this->queries[] = $query;

        $next = array_shift($this->queue);

        if ($next === null || $next['value'] === null) {
            if ($next !== null && $next['error'] instanceof EnrichmentProviderException) {
                throw $next['error'];
            }

            return EnrichmentResult::miss($this->key, $this->cost->forOutcome(false));
        }

        return new EnrichmentResult(
            provider: $this->key,
            value: $next['value'],
            confidence: 90,
            phoneType: $next['type'] ?? PhoneType::Unknown,
            costMicros: $this->cost->forOutcome(true),
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
        return ProviderCapability::PhoneFind;
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
