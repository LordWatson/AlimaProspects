<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Services\SerperCompanyWebsiteResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('lead-sourcing.providers.web_search.provider', 'serper');
    config()->set('lead-sourcing.providers.web_search.api_key', 'test-key');
    config()->set('lead-sourcing.providers.web_search.base_url', 'https://google.serper.dev');
});

// Resolve the concrete service directly: the base TestCase binds the contract to a null fake so no
// other test can reach the network, but here we are testing the real Serper-backed resolver.
function resolver(): SerperCompanyWebsiteResolver
{
    return app(SerperCompanyWebsiteResolver::class);
}

function fakeSerper(array $organic): void
{
    Http::fake([
        'google.serper.dev/*' => Http::response(['organic' => $organic]),
    ]);
}

it('returns the origin of the first company-owned result', function (): void {
    fakeSerper([
        ['link' => 'https://en.wikipedia.org/wiki/Acme'],         // skipped: reference site
        ['link' => 'https://www.acme.co.uk/about?ref=serp'],      // kept: origin only
    ]);

    expect(resolver()->resolve('Acme Ltd', 'London'))->toBe('https://www.acme.co.uk');
});

it('skips social, directory and free-mail hosts', function (): void {
    fakeSerper([
        ['link' => 'https://www.linkedin.com/company/acme'],
        ['link' => 'https://acme.example/'],
    ]);

    expect(resolver()->resolve('Acme'))->toBe('https://acme.example');
});

it('sends the company name (and location) as the query', function (): void {
    fakeSerper([['link' => 'https://acme.example/']]);

    resolver()->resolve('Acme Ltd', 'London');

    Http::assertSent(fn ($request): bool => ($request['q'] ?? '') === 'Acme Ltd London'
        && $request->hasHeader('X-API-KEY'));
});

it('returns null when nothing looks like a company site', function (): void {
    fakeSerper([['link' => 'https://www.yell.com/acme']]);

    expect(resolver()->resolve('Acme'))->toBeNull();
});

it('returns null for an empty name without calling the API', function (): void {
    Http::fake();

    expect(resolver()->resolve('  '))->toBeNull();

    Http::assertNothingSent();
});

it('returns null (and never throws) when the provider fails', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response('nope', 500)]);

    expect(resolver()->resolve('Acme'))->toBeNull();
});

it('is unavailable when no api key is configured', function (): void {
    config()->set('lead-sourcing.providers.web_search.api_key', '');
    Http::fake();

    expect(resolver()->resolve('Acme'))->toBeNull();

    Http::assertNothingSent();
});
