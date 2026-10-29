<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;

/**
 * The external providers a lead can be discovered from, behind the
 * {@see LeadSourceProvider} contract (ADR 0008). All three ship in the MVP: Companies House
 * (Phase 7), Google Places and web search (Phase 7b, the latter backed by Serper — ADR 0010).
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum LeadSource: string
{
    case CompaniesHouse = 'companies_house';
    case GooglePlaces = 'google_places';
    case WebSearch = 'web_search';

    public function label(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'Companies House',
            self::GooglePlaces => 'Google Places',
            self::WebSearch => 'Web search',
        };
    }

    /**
     * A one-line description of what the provider is good for, shown in the discovery picker.
     */
    public function description(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'UK official company registry — free, authoritative company records.',
            self::GooglePlaces => 'Local and small businesses by location and category (strong for the UAE).',
            self::WebSearch => 'General web discovery across the open internet.',
        };
    }

    /**
     * Whether this provider is live in the current build. Providers that aren't yet implemented are
     * listed (so the UI can preview what's coming) but can't be selected for a run.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Relative authority of a provider's data, used when the same company is found by more than one
     * source: an official registry outranks a places directory, which outranks a web-search hit. The
     * higher-ranked source's attribution (name, source URL, raw payload) wins on a dedupe merge.
     */
    public function rank(): int
    {
        return match ($this) {
            self::CompaniesHouse => 3,
            self::GooglePlaces => 2,
            self::WebSearch => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    /**
     * The provider keys that can actually be run right now.
     *
     * @return list<string>
     */
    public static function availableValues(): array
    {
        return array_values(array_map(
            static fn (self $source): string => $source->value,
            array_filter(self::cases(), static fn (self $source): bool => $source->isAvailable()),
        ));
    }
}
