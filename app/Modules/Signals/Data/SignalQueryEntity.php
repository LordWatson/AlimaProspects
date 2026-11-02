<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\SignalWatchlistEntry;

/**
 * One named account a run is searching for, flattened out of its watchlist entry (S10).
 *
 * The builder takes these rather than the entries themselves for the same reason it takes a
 * {@see SignalQuerySpec} rather than a watch: a query is built from names and a type, and nothing
 * about building one needs a saved row. It also means a preview of an entity watch is possible
 * later without the builder changing.
 */
final readonly class SignalQueryEntity
{
    /**
     * @param  list<string>  $names  The member's spelling first, then any aliases.
     */
    public function __construct(
        public WatchlistEntityType $type,
        public array $names,
        /** The entry this came from, so a signal can say which account matched. Null in a preview. */
        public ?int $entryId = null,
    ) {}

    public static function fromEntry(SignalWatchlistEntry $entry): self
    {
        return new self(
            type: $entry->entity_type,
            names: $entry->searchNames(),
            entryId: (int) $entry->getKey(),
        );
    }

    /**
     * Whether a bare name is specific enough to search for on its own.
     *
     * A company's is; a person's is not, and the queries built for one differ accordingly.
     */
    public function searchableAlone(): bool
    {
        return $this->type->isSearchableAlone();
    }
}
