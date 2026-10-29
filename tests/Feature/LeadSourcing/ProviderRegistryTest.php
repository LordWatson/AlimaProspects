<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use App\Modules\LeadSourcing\Services\GooglePlacesProvider;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Services\WebSearchProvider;

it('registers and resolves all three live providers via the service provider', function (): void {
    $registry = app(LeadSourceProviderRegistry::class);

    expect($registry->has(LeadSource::CompaniesHouse))->toBeTrue()
        ->and($registry->has(LeadSource::GooglePlaces))->toBeTrue()
        ->and($registry->has(LeadSource::WebSearch))->toBeTrue()
        ->and($registry->for(LeadSource::CompaniesHouse))->toBeInstanceOf(CompaniesHouseProvider::class)
        ->and($registry->for(LeadSource::GooglePlaces))->toBeInstanceOf(GooglePlacesProvider::class)
        ->and($registry->for(LeadSource::WebSearch))->toBeInstanceOf(WebSearchProvider::class);
});

it('reports the live providers as the only available sources', function (): void {
    expect(LeadSource::availableValues())->toBe(['companies_house', 'google_places', 'web_search']);
});
