<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Enums;

use App\Modules\LeadSourcing\Contracts\LeadSourceProvider;

/**
 * The external providers a lead can be discovered from, behind the
 * {@see LeadSourceProvider} contract (ADR 0008). Only
 * {@see self::CompaniesHouse} ships in Phase 7; Google Places and web search are added in Phase 7b
 * and slot in here without touching the discovery pipeline.
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
        return $this === self::CompaniesHouse;
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
