<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use Spatie\LaravelData\Data;

/**
 * The people one provider found at one company (PL2).
 *
 * Its own type rather than a list of {@see FoundPerson}, for the same reason {@see EnrichmentResult}
 * is: the cost and the attribution belong to the **call**, not to each person in it. A vendor that
 * bills per record and returns four people has one cost and four records, and a caller holding only
 * the list would have to invent the arithmetic.
 */
final class PeopleResult extends Data
{
    /**
     * @param  list<FoundPerson>  $people
     * @param  array<string, mixed>  $raw  never rendered, never logged — it names people
     */
    public function __construct(
        public string $provider,
        public array $people = [],
        public int $costMicros = 0,
        public array $raw = [],
    ) {}

    public static function miss(string $provider, int $costMicros = 0): self
    {
        return new self(provider: $provider, costMicros: $costMicros);
    }

    /**
     * Whether the provider found anybody worth storing.
     *
     * Counts only **named** people: a vendor that returns "Sales Team" has returned a role inbox,
     * and treating that as a hit would stop the waterfall one provider short of a real person.
     */
    public function found(): bool
    {
        return $this->named() !== [];
    }

    /**
     * The people who are actually people.
     *
     * @return list<FoundPerson>
     */
    public function named(): array
    {
        return array_values(array_filter(
            $this->people,
            static fn (FoundPerson $person): bool => $person->isNamed(),
        ));
    }
}
