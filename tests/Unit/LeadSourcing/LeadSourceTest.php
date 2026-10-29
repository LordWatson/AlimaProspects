<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\LeadSource;

it('lists all provider keys', function (): void {
    expect(LeadSource::values())->toBe(['companies_house', 'google_places', 'web_search']);
});

it('marks all three providers available (Phase 7b)', function (): void {
    expect(LeadSource::CompaniesHouse->isAvailable())->toBeTrue()
        ->and(LeadSource::GooglePlaces->isAvailable())->toBeTrue()
        ->and(LeadSource::WebSearch->isAvailable())->toBeTrue()
        ->and(LeadSource::availableValues())->toBe(['companies_house', 'google_places', 'web_search']);
});

it('has a label and description for each source', function (LeadSource $source): void {
    expect($source->label())->not->toBe('')
        ->and($source->description())->not->toBe('');
})->with(LeadSource::cases());
