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
