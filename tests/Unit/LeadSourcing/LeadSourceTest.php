<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\LeadSource;

it('lists every source a lead can have', function (): void {
    expect(LeadSource::values())->toBe(['companies_house', 'google_places', 'web_search', 'import']);
});

it('marks all three providers available, and the import source not', function (): void {
    expect(LeadSource::CompaniesHouse->isAvailable())->toBeTrue()
        ->and(LeadSource::GooglePlaces->isAvailable())->toBeTrue()
        ->and(LeadSource::WebSearch->isAvailable())->toBeTrue()
        // `import` isn't a provider — it is how a team's own CSV gets in — so it can never be
        // picked for a discovery run, even though it is a perfectly good thing to filter a list by.
        ->and(LeadSource::Import->isAvailable())->toBeFalse()
        ->and(LeadSource::availableValues())->toBe(['companies_house', 'google_places', 'web_search']);
});

it('ranks an import above every provider', function (): void {
    // Not because a spreadsheet beats a registry, but because it is the team's own record of who
    // this company is: a later discovery hit must fill in the gaps, never rename the company they
    // typed (see DiscoverLeadsJob::mergeInto).
    expect(LeadSource::Import->rank())->toBeGreaterThan(LeadSource::CompaniesHouse->rank())
        ->and(LeadSource::CompaniesHouse->rank())->toBeGreaterThan(LeadSource::GooglePlaces->rank())
        ->and(LeadSource::GooglePlaces->rank())->toBeGreaterThan(LeadSource::WebSearch->rank());
});

it('has a label and description for each source', function (LeadSource $source): void {
    expect($source->label())->not->toBe('')
        ->and($source->description())->not->toBe('');
})->with(LeadSource::cases());
