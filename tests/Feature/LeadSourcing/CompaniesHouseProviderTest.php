<?php

declare(strict_types=1);

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('lead-sourcing.providers.companies_house.api_key', 'test-key');
    config()->set('lead-sourcing.providers.companies_house.base_url', 'https://api.company-information.service.gov.uk');
    config()->set('lead-sourcing.providers.companies_house.record_base_url', 'https://find-and-update.company-information.service.gov.uk');
});

function chQuery(int $limit = 25): DiscoveryQuery
{
    return new DiscoveryQuery(source: LeadSource::CompaniesHouse, terms: 'marketing agency', limit: $limit);
}

it('maps Companies House search results into sourced leads with attribution', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/*' => Http::response([
            'items' => [
                [
                    'title' => 'ACME MARKETING LTD',
                    'company_number' => '12345678',
                    'company_status' => 'active',
                    'company_type' => 'ltd',
                    'date_of_creation' => '2015-06-01',
                    'address' => ['locality' => 'London', 'postal_code' => 'EC1A 1BB', 'region' => 'Greater London', 'country' => 'United Kingdom'],
                ],
                ['title' => '', 'company_number' => '999'], // skipped: no usable name
            ],
            'total_results' => 2,
        ]),
    ]);

    $leads = app(CompaniesHouseProvider::class)->discover(chQuery());

    expect($leads)->toHaveCount(1);

    $lead = $leads[0];
    expect($lead->companyName)->toBe('ACME MARKETING LTD')
        ->and($lead->companyNumber)->toBe('12345678')
        ->and($lead->companyStatus)->toBe('active')
        ->and($lead->country)->toBe('GB')
        ->and($lead->locality)->toBe('London')
        ->and($lead->sourceUrl)->toBe('https://find-and-update.company-information.service.gov.uk/company/12345678')
        ->and($lead->dedupeKey())->toBe('no:12345678');
});

it('sends the query with basic auth and an items-per-page cap', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => []])]);

    app(CompaniesHouseProvider::class)->discover(chQuery(limit: 10));

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/search/companies')
            && str_contains($request->url(), 'q=marketing')
            && str_contains($request->url(), 'items_per_page=10')
            && $request->hasHeader('Authorization');
    });
});

it('is free (zero cost)', function (): void {
    expect(app(CompaniesHouseProvider::class)->costMicros(50))->toBe(0);
});

it('returns nothing when there are no search terms', function (): void {
    Http::fake();

    $leads = app(CompaniesHouseProvider::class)->discover(
        new DiscoveryQuery(source: LeadSource::CompaniesHouse, terms: '  ', limit: 25),
    );

    expect($leads)->toBe([]);
    Http::assertNothingSent();
});

it('throws a domain exception when the API fails', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response('nope', 500)]);

    expect(fn () => app(CompaniesHouseProvider::class)->discover(chQuery()))
        ->toThrow(LeadSourcingException::class);
});

it('does not trip the circuit breaker on a 4xx response', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response('bad request', 400)]);

    // Several client-error failures — these are our fault, so the breaker must stay closed.
    foreach (range(1, 6) as $ignored) {
        try {
            app(CompaniesHouseProvider::class)->discover(chQuery());
        } catch (LeadSourcingException) {
            // expected
        }
    }

    // The circuit is still closed (only 5xx/429/connection errors trip it).
    expect(app(CircuitBreaker::class)->allows('companies_house'))->toBeTrue();
});

it('throws when no API key is configured', function (): void {
    config()->set('lead-sourcing.providers.companies_house.api_key', '');
    Http::fake();

    expect(fn () => app(CompaniesHouseProvider::class)->discover(chQuery()))
        ->toThrow(LeadSourcingException::class);
    Http::assertNothingSent();
});

it('searches by SIC code, active companies and place when the query carries codes', function (): void {
    Http::fake([
        'api.company-information.service.gov.uk/advanced-search/*' => Http::response(['items' => [[
            'company_name' => 'LEEDS BLOCK MANAGEMENT LTD',
            'company_number' => '05186032',
            'company_status' => 'active',
            'registered_office_address' => ['locality' => 'Leeds', 'postal_code' => 'LS7 2AA'],
            'sic_codes' => ['68320'],
        ]]]),
    ]);

    $leads = app(CompaniesHouseProvider::class)->discover(new DiscoveryQuery(
        source: LeadSource::CompaniesHouse,
        terms: 'property management',
        location: 'Leeds',
        sicCodes: ['68320'],
    ));

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->companyName)->toBe('LEEDS BLOCK MANAGEMENT LTD')
        ->and($leads[0]->locality)->toBe('Leeds')
        ->and($leads[0]->sicCodes)->toBe(['68320']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/advanced-search/companies')
        && $request['sic_codes'] === '68320'
        && $request['company_status'] === 'active'
        && $request['location'] === 'Leeds');
});

it('falls back to the name search when the register has nothing under those codes', function (): void {
    Http::fake([
        // The advanced search answers "no companies found" with a 404, which is not a failure.
        'api.company-information.service.gov.uk/advanced-search/*' => Http::response([], 404),
        'api.company-information.service.gov.uk/search/*' => Http::response(['items' => [
            ['title' => 'ACME LETTINGS LTD', 'company_number' => '1', 'company_status' => 'active'],
        ]]),
    ]);

    $leads = app(CompaniesHouseProvider::class)->discover(new DiscoveryQuery(
        source: LeadSource::CompaniesHouse,
        terms: 'lettings',
        sicCodes: ['00000'],
    ));

    expect($leads)->toHaveCount(1)->and($leads[0]->companyName)->toBe('ACME LETTINGS LTD');
});

it('never returns a dissolved or insolvent company', function (): void {
    Http::fake(['api.company-information.service.gov.uk/*' => Http::response(['items' => [
        ['title' => 'GONE LTD', 'company_number' => '1', 'company_status' => 'dissolved'],
        ['title' => 'FAILING LTD', 'company_number' => '2', 'company_status' => 'liquidation'],
        ['title' => 'TRADING LTD', 'company_number' => '3', 'company_status' => 'active'],
    ]])]);

    $leads = app(CompaniesHouseProvider::class)->discover(chQuery());

    expect(array_map(static fn ($lead): string => $lead->companyName, $leads))->toBe(['TRADING LTD']);
});
