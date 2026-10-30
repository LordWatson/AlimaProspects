<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\LeadSource;

it('lists every source a lead can have', function (): void {
    expect(LeadSource::values())->toBe(['companies_house', 'google_places', 'web_search', 'import', 'signal']);
});

it('marks the three providers available, and the two non-providers not', function (): void {
    expect(LeadSource::CompaniesHouse->isAvailable())->toBeTrue()
        ->and(LeadSource::GooglePlaces->isAvailable())->toBeTrue()
        ->and(LeadSource::WebSearch->isAvailable())->toBeTrue()
        // Neither `import` nor `signal` is a provider — one is how a team's own CSV gets in, the
        // other how a signal they chose to act on does — so neither can ever be picked for a
        // discovery run, even though both are perfectly good things to filter a list by.
        ->and(LeadSource::Import->isAvailable())->toBeFalse()
        ->and(LeadSource::Signal->isAvailable())->toBeFalse()
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

it('ranks a signal at the bottom, with web search', function (): void {
    // A converted signal's company fields are what an article said and what the member typed into
    // the convert form in a hurry. Every other source may correct them on a merge; a signal may
    // only fill their gaps (ConvertSignalToLeadAction).
    expect(LeadSource::Signal->rank())->toBe(LeadSource::WebSearch->rank())
        ->and(LeadSource::Signal->rank())->toBeLessThan(LeadSource::GooglePlaces->rank())
        ->and(LeadSource::Signal->rank())->toBeLessThan(LeadSource::CompaniesHouse->rank())
        ->and(LeadSource::Signal->rank())->toBeLessThan(LeadSource::Import->rank());
});

it('has a label and description for each source', function (LeadSource $source): void {
    expect($source->label())->not->toBe('')
        ->and($source->description())->not->toBe('');
})->with(LeadSource::cases());
