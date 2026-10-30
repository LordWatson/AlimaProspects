<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\WebSearchProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('lead-sourcing.providers.web_search.provider', 'serper');
    config()->set('lead-sourcing.providers.web_search.api_key', 'test-key');
    config()->set('lead-sourcing.providers.web_search.base_url', 'https://google.serper.dev');
});

function webQuery(int $limit = 25): DiscoveryQuery
{
    return new DiscoveryQuery(source: LeadSource::WebSearch, terms: 'marketing agencies', location: 'London', limit: $limit);
}

it('maps organic results into leads and de-duplicates by domain within a response', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'Acme Ltd - Home', 'link' => 'https://acme.com/', 'snippet' => '...'],
                ['title' => 'Acme Ltd - About', 'link' => 'https://acme.com/about', 'snippet' => '...'],
                ['title' => 'Beta | Agency', 'link' => 'https://beta.io', 'snippet' => '...'],
                ['title' => '', 'link' => 'https://skip.me'], // skipped: no title
            ],
        ]),
    ]);

    $leads = app(WebSearchProvider::class)->discover(webQuery());

    expect($leads)->toHaveCount(2)
        ->and($leads[0]->companyName)->toBe('Acme Ltd')
        ->and($leads[0]->website)->toBe('https://acme.com')
        ->and($leads[0]->dedupeKey())->toBe('domain:acme.com')
        ->and($leads[1]->companyName)->toBe('Beta')
        ->and($leads[1]->dedupeKey())->toBe('domain:beta.io');
});

it('sends the query with the api key header', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => []])]);

    app(WebSearchProvider::class)->discover(webQuery());

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/search')
            && $request->hasHeader('X-API-KEY')
            && ($request['q'] ?? '') === 'marketing agencies London';
    });
});

it('reports a per-query cost', function (): void {
    config()->set('lead-sourcing.providers.web_search.cost_micros_per_request', 3_000);

    expect(app(WebSearchProvider::class)->costMicros(10))->toBe(3_000);
});

it('fails clearly when the configured driver is not implemented', function (): void {
    config()->set('lead-sourcing.providers.web_search.provider', 'brave');
    Http::fake();

    expect(fn () => app(WebSearchProvider::class)->discover(webQuery()))
        ->toThrow(LeadSourcingException::class);

    Http::assertNothingSent();
});

it('throws a domain exception when the search API fails', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response('error', 500)]);

    expect(fn () => app(WebSearchProvider::class)->discover(webQuery()))
        ->toThrow(LeadSourcingException::class);
});

it('throws (and sends nothing) when the api key is missing', function (): void {
    config()->set('lead-sourcing.providers.web_search.api_key', '');
    Http::fake();

    expect(fn () => app(WebSearchProvider::class)->discover(webQuery()))
        ->toThrow(LeadSourcingException::class);

    Http::assertNothingSent();
});

it('skips aggregator and social domains', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'Acme | LinkedIn', 'link' => 'https://www.linkedin.com/company/acme'],
                ['title' => 'Acme on Facebook', 'link' => 'https://facebook.com/acme'],
                ['title' => 'Acme Ltd', 'link' => 'https://acme.com'],
            ],
        ]),
    ]);

    $leads = app(WebSearchProvider::class)->discover(webQuery());

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->domain())->toBe('acme.com');
});

it('stores the site origin as the website and the result page as the source URL', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'Acme Ltd - Services', 'link' => 'https://acme.com/services/seo'],
            ],
        ]),
    ]);

    $leads = app(WebSearchProvider::class)->discover(webQuery());

    expect($leads[0]->website)->toBe('https://acme.com')
        ->and($leads[0]->sourceUrl)->toBe('https://acme.com/services/seo');
});

it('reports zero cost for a no-op run', function (): void {
    expect(app(WebSearchProvider::class)->costMicros(0))->toBe(0);
});

it('pushes the query exclusions down to the API as negative terms', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => []])]);

    $query = new DiscoveryQuery(
        source: LeadSource::WebSearch,
        terms: 'boutique hotels',
        location: 'Manchester',
        excludeTerms: ['graphic design', 'branding agency'],
    );

    app(WebSearchProvider::class)->discover($query);

    // This is the one provider that can refuse competitors at the API rather than after the fact,
    // which buys back the result slots they would otherwise occupy.
    Http::assertSent(fn ($request): bool => ($request['q'] ?? '')
        === 'boutique hotels Manchester -"graphic design" -"branding agency"');
});

it('caps the negative terms so the operators cannot crowd out the query', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => []])]);

    $query = new DiscoveryQuery(
        source: LeadSource::WebSearch,
        terms: 'hotels',
        excludeTerms: ['one', 'two', 'three', 'four', 'five', 'six', 'seven'],
    );

    app(WebSearchProvider::class)->discover($query);

    Http::assertSent(function ($request): bool {
        $q = (string) ($request['q'] ?? '');

        return substr_count($q, '-"') === 5 && ! str_contains($q, '-"six"');
    });
});

it('strips quotes from an exclusion so it cannot break out of the operator', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => []])]);

    $query = new DiscoveryQuery(
        source: LeadSource::WebSearch,
        terms: 'hotels',
        excludeTerms: ['say "design" loudly'],
    );

    app(WebSearchProvider::class)->discover($query);

    // The exclusions reach here from a model response, so an unescaped quote would close the
    // operator early and leave the rest of the term as a positive search phrase.
    Http::assertSent(fn ($request): bool => ($request['q'] ?? '')
        === 'hotels -"say design loudly"');
});
