<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;

it('round-trips through params', function (): void {
    $query = new DiscoveryQuery(
        source: LeadSource::CompaniesHouse,
        terms: 'marketing agency',
        location: 'London',
        limit: 40,
        icpId: 7,
        industries: ['marketing', 'advertising'],
    );

    $rebuilt = DiscoveryQuery::fromParams(LeadSource::CompaniesHouse, $query->toParams());

    expect($rebuilt->terms)->toBe('marketing agency')
        ->and($rebuilt->location)->toBe('London')
        ->and($rebuilt->limit)->toBe(40)
        ->and($rebuilt->icpId)->toBe(7)
        ->and($rebuilt->industries)->toBe(['marketing', 'advertising']);
});

it('prefers explicit terms over the ICP and has no terms with neither', function (): void {
    $explicit = DiscoveryQuery::fromIcp(LeadSource::CompaniesHouse, null, 25, 'coffee roasters', 'Dubai');
    expect($explicit->terms)->toBe('coffee roasters')
        ->and($explicit->location)->toBe('Dubai')
        ->and($explicit->icpId)->toBeNull();

    $empty = DiscoveryQuery::fromIcp(LeadSource::CompaniesHouse, null, 25);
    expect($empty->terms)->toBe('')
        ->and($empty->location)->toBeNull();
});

it('round-trips the exclusions and the shaped marker through params', function (): void {
    $query = (new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'hotels', location: 'Leeds'))
        ->withShapedTerms('boutique hotels', 'Independent hotels', ['graphic design']);

    $rebuilt = DiscoveryQuery::fromParams(LeadSource::WebSearch, $query->toParams());

    // The job re-derives the query from the stored params without the ICP, so anything the filter or
    // the provider needs has to survive the round trip.
    expect($rebuilt->terms)->toBe('boutique hotels')
        ->and($rebuilt->excludeTerms)->toBe(['graphic design'])
        ->and($rebuilt->customerDescription)->toBe('Independent hotels')
        ->and($rebuilt->shaped)->toBeTrue()
        ->and($rebuilt->location)->toBe('Leeds');
});

it('reads an old run’s params, which carry none of the new keys', function (): void {
    $rebuilt = DiscoveryQuery::fromParams(LeadSource::WebSearch, [
        'terms' => 'marketing',
        'location' => 'London',
        'limit' => 25,
        'icp_id' => 3,
        'industries' => ['Marketing'],
    ]);

    expect($rebuilt->excludeTerms)->toBe([])
        ->and($rebuilt->customerDescription)->toBeNull()
        ->and($rebuilt->shaped)->toBeFalse();
});

it('de-duplicates exclusions case-insensitively and caps the list', function (): void {
    $query = (new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'hotels'))
        ->withShapedTerms('hotels', null, [
            'Design', 'design', 'DESIGN',
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j',
        ]);

    expect($query->excludeTerms)->toBe(['Design']);
});
