<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Data;

use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Compliance\Services\PersonalDataLocator;
use Spatie\LaravelData\Data;

/**
 * What one team holds about one person (docs/compliance.md §3.5) — the answer to a data-subject
 * access request, and the input to an erasure.
 *
 * Built by {@see PersonalDataLocator}. Three things it reports that a bare list of rows would not:
 *
 * - **`truncated`** — the search hit its cap. A request about one person that returns two hundred
 *   rows is a search that needs narrowing, and saying so is better than quietly answering half the
 *   question.
 * - **`suppressed`** — whether this identity is already on the do-not-contact list. Usually the
 *   first thing a subject actually wants to know, and the first thing the team should check before
 *   promising anything.
 * - **`matchedOn`** — whether the identity was recognised as an address, a number or a name,
 *   because a name match is a list of candidates and an address match is not.
 */
final class SubjectDataReport extends Data
{
    /**
     * @param  list<SubjectRecord>  $records
     */
    public function __construct(
        /** The identity searched for, normalised — an address lower-cased, a number as digits. */
        public readonly string $query,
        /** `email`, `phone` or `name`. */
        public readonly string $matchedOn,
        public readonly array $records,
        public readonly bool $truncated,
        /** Whether this identity is already on the do-not-contact list, at either scope. */
        public readonly bool $suppressed,
    ) {}

    /**
     * How many records were found in each category, every category present. A category with
     * nothing in it comes back as `0` rather than missing, so the screen can say "no messages"
     * instead of rendering a gap.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (SubjectDataCategory::cases() as $category) {
            $counts[$category->value] = 0;
        }

        foreach ($this->records as $record) {
            $counts[$record->category->value]++;
        }

        return $counts;
    }

    public function total(): int
    {
        return count($this->records);
    }

    /**
     * How many of the found records an erasure would actually remove. Shown on the confirmation,
     * because "erase 12 records" when two of them are a retained do-not-contact entry would be a
     * promise the product cannot keep.
     */
    public function erasableCount(): int
    {
        return count(array_filter($this->records, static fn (SubjectRecord $record): bool => $record->erasable));
    }
}
