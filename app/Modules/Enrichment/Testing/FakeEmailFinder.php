<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Testing;

use App\Modules\Enrichment\Contracts\EmailFinder;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;

/**
 * A deterministic {@see EmailFinder} for tests and local demo seeding (PL2).
 *
 * Deterministic rather than random on purpose: a waterfall test has to be able to say "the first
 * provider misses, the second finds it" and get the same answer twice. Queue outcomes in the order
 * they should happen; an empty queue is a miss, which is the honest default for a finder.
 *
 * It records every query it was asked, because half of what a waterfall test needs to prove is
 * that a provider was **not** called — after an earlier one succeeded, or because the query was
 * unanswerable and nobody should have paid to ask.
 */
final class FakeEmailFinder implements EmailFinder
{
    /** @var list<array{email: string|null, confidence: int|null, error: EnrichmentProviderException|null}> */
    private array $queue = [];

    /** @var list<PersonQuery> */
    public private(set) array $queries = [];

    public function __construct(
        private readonly string $key = 'fake_email_finder',
        private readonly bool $enabled = true,
        private readonly CostProfile $cost = new CostProfile,
    ) {}

    /**
     * Queue an address for the next call to find.
     */
    public function willFind(string $email, ?int $confidence = 90): self
    {
        $this->queue[] = ['email' => $email, 'confidence' => $confidence, 'error' => null];

        return $this;
    }

    /**
     * Queue a miss — the ordinary outcome, and the one a waterfall exists for.
     */
    public function willMiss(): self
    {
        $this->queue[] = ['email' => null, 'confidence' => null, 'error' => null];

        return $this;
    }

    /**
     * Queue a provider **failure**, which is a different thing from a miss: it throws.
     */
    public function willFail(?EnrichmentProviderException $exception = null): self
    {
        $this->queue[] = [
            'email' => null,
            'confidence' => null,
            'error' => $exception ?? EnrichmentProviderException::unavailable($this->key),
        ];

        return $this;
    }

    public function findEmail(PersonQuery $query): EnrichmentResult
    {
        $this->queries[] = $query;

        $next = array_shift($this->queue);

        if ($next !== null && $next['error'] instanceof EnrichmentProviderException) {
            throw $next['error'];
        }

        if ($next === null || $next['email'] === null) {
            return EnrichmentResult::miss($this->key, $this->cost->forOutcome(false));
        }

        return new EnrichmentResult(
            provider: $this->key,
            value: $next['email'],
            confidence: $next['confidence'],
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
        return ProviderCapability::EmailFind;
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
