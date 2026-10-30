<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;

/**
 * The backstop that holds "never show me my competitors" for the providers that cannot express it.
 * Companies House matches company names and Google Places matches categories; neither takes a
 * negative term, so without this the promise would only hold for web search.
 */
function candidate(string $name, ?string $website = null): SourcedLead
{
    return new SourcedLead(
        source: LeadSource::WebSearch,
        companyName: $name,
        website: $website,
    );
}

function filterWith(array $excludeTerms, array $leads): array
{
    $query = new DiscoveryQuery(
        source: LeadSource::WebSearch,
        terms: 'boutique hotels',
        excludeTerms: $excludeTerms,
    );

    return (new LeadCandidateFilter)->apply($query, $leads);
}

it('keeps everything when the query has no exclusions', function (): void {
    $leads = [candidate('Acme Design'), candidate('Beta Studio')];

    [$kept, $rejected] = filterWith([], $leads);

    expect($kept)->toHaveCount(2)->and($rejected)->toBe(0);
});

it('drops a candidate whose name matches an exclusion, case-insensitively', function (): void {
    $leads = [
        candidate('The Grand Hotel'),
        candidate('Bright Graphic Design Ltd'),
    ];

    [$kept, $rejected] = filterWith(['graphic design'], $leads);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('The Grand Hotel')
        ->and($rejected)->toBe(1);
});

it('drops a candidate whose domain gives away the trade its name hides', function (): void {
    // A competitor trading as something neutral still usually says what it does in its domain.
    $leads = [
        candidate('Studio Eight', 'https://studioeight-design.co.uk'),
        candidate('The Grand Hotel', 'https://thegrandhotel.test'),
    ];

    [$kept, $rejected] = filterWith(['design'], $leads);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('The Grand Hotel')
        ->and($rejected)->toBe(1);
});

it('counts every rejection, so a short run can be explained', function (): void {
    $leads = [
        candidate('Alpha Design'),
        candidate('Beta Design'),
        candidate('Gamma Hotel'),
    ];

    [$kept, $rejected] = filterWith(['design'], $leads);

    expect($kept)->toHaveCount(1)->and($rejected)->toBe(2);
});

it('never lets a 1–2 character exclusion through to strip the whole result set', function (): void {
    // DiscoveryQuery refuses to carry them: as a substring test "co" would reject almost any name.
    $query = new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'hotels')
        ->withShapedTerms('boutique hotels', null, ['co', 'x', 'design']);

    expect($query->excludeTerms)->toBe(['design']);

    [$kept, $rejected] = (new LeadCandidateFilter)->apply($query, [
        candidate('Coastal Hotel Group'),
        candidate('Apex Design'),
    ]);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('Coastal Hotel Group')
        ->and($rejected)->toBe(1);
});

it('keeps a company whose name merely starts with the excluded word', function (): void {
    // A plain substring test threw these away: "design" ate "Designer Outlet", "marketing" ate
    // "Marketplace". They are real customers of a design studio, not competitors.
    $leads = [
        candidate('Designer Outlet Ltd'),
        candidate('Marketplace Holdings'),
        candidate('Apex Design'),
    ];

    [$kept, $rejected] = filterWith(['design', 'marketing'], $leads);

    expect(array_map(static fn ($l): string => $l->companyName, $kept))
        ->toBe(['Designer Outlet Ltd', 'Marketplace Holdings'])
        ->and($rejected)->toBe(1);
});

it('matches a multi-word exclusion as a phrase in the name', function (): void {
    $leads = [
        candidate('Bright Graphic Design Ltd'),
        candidate('Graphic Novels Press'),
    ];

    [$kept, $rejected] = filterWith(['graphic design'], $leads);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('Graphic Novels Press')
        ->and($rejected)->toBe(1);
});

it('holds a short term back from the domain test, where a substring is usually chance', function (): void {
    // "art" inside "smartgroup.ae" is a coincidence; the name test still applies in full.
    $leads = [
        candidate('Smart Group', 'https://smartgroup.ae'),
        candidate('Art Collective'),
    ];

    [$kept, $rejected] = filterWith(['art'], $leads);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('Smart Group')
        ->and($rejected)->toBe(1);
});

it('respects word boundaries around non-ASCII names', function (): void {
    // An Arabic trading name is a first-class case for a UAE-market product, not an edge one.
    $leads = [
        candidate('شركة التصميم الجرافيكي'),
        candidate('فندق الخليج'),
    ];

    [$kept, $rejected] = filterWith(['التصميم'], $leads);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->companyName)->toBe('فندق الخليج')
        ->and($rejected)->toBe(1);
});
