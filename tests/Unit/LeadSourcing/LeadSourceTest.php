<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\LeadSource;

it('lists all provider keys', function (): void {
    expect(LeadSource::values())->toBe(['companies_house', 'google_places', 'web_search']);
});

it('marks only Companies House available in Phase 7', function (): void {
    expect(LeadSource::CompaniesHouse->isAvailable())->toBeTrue()
        ->and(LeadSource::GooglePlaces->isAvailable())->toBeFalse()
        ->and(LeadSource::WebSearch->isAvailable())->toBeFalse()
        ->and(LeadSource::availableValues())->toBe(['companies_house']);
});

it('has a label and description for each source', function (LeadSource $source): void {
    expect($source->label())->not->toBe('')
        ->and($source->description())->not->toBe('');
})->with(LeadSource::cases());
