<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * What one watchlist entry names (S10).
 *
 * The app branches on it in two places that matter, so it is an enum: a company's name goes into a
 * query as a phrase and a person's does not without a qualifier (a search for `"Jane Smith"` alone
 * returns every Jane Smith alive), and the matcher compares a company against `leads.company_name`
 * while a person is compared against a lead's **contacts**.
 */
enum WatchlistEntityType: string
{
    case Company = 'company';

    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Person => 'Person',
        };
    }

    /**
     * Whether a bare name of this kind is specific enough to search for on its own.
     *
     * A company name is: "Acme Engineering" is a company. A person's name is not — and the
     * consequence is that a person entry's queries always carry the signal type's own keywords,
     * never the name alone. Watching a named individual in the news is a reasonable thing for a
     * wealth manager to want and an unreasonable thing to do badly.
     */
    public function isSearchableAlone(): bool
    {
        return $this === self::Company;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
