<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\GooglePlacesProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('lead-sourcing.providers.google_places.api_key', 'test-key');
    config()->set('lead-sourcing.providers.google_places.base_url', 'https://places.googleapis.com');
});

function placesQuery(int $limit = 25): DiscoveryQuery
{
    return new DiscoveryQuery(source: LeadSource::GooglePlaces, terms: 'coffee roasters', location: 'Dubai', limit: $limit);
}

function fakePlaces(): void
{
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [
                [
                    'id' => 'place-123',
                    'displayName' => ['text' => 'Acme Coffee Roasters', 'languageCode' => 'en'],
                    'formattedAddress' => '1 Trade St, Dubai, UAE',
                    'websiteUri' => 'https://acme.ae',
                    'googleMapsUri' => 'https://maps.google.com/?cid=123',
                    'types' => ['cafe', 'food'],
                    'addressComponents' => [
                        ['longText' => 'Dubai', 'shortText' => 'Dubai', 'types' => ['locality']],
                        ['longText' => 'United Arab Emirates', 'shortText' => 'AE', 'types' => ['country']],
                    ],
                ],
                ['displayName' => ['text' => ''], 'websiteUri' => 'https://skip.me'], // skipped: no name
            ],
        ]),
    ]);
}

it('maps Places results into sourced leads', function (): void {
    fakePlaces();

    $leads = app(GooglePlacesProvider::class)->discover(placesQuery());

    expect($leads)->toHaveCount(1);

    $lead = $leads[0];
    expect($lead->companyName)->toBe('Acme Coffee Roasters')
        ->and($lead->website)->toBe('https://acme.ae')
        ->and($lead->locality)->toBe('Dubai')
        ->and($lead->country)->toBe('AE')
        ->and($lead->companyType)->toBe('cafe')
        ->and($lead->sourceUrl)->toBe('https://maps.google.com/?cid=123')
        ->and($lead->dedupeKey())->toBe('domain:acme.ae');
});

it('sends a text search biased to the location with the api key header', function (): void {
    fakePlaces();

    app(GooglePlacesProvider::class)->discover(placesQuery());

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'places:searchText')
            && $request->hasHeader('X-Goog-Api-Key')
            && $request->hasHeader('X-Goog-FieldMask')
            && ($request['textQuery'] ?? '') === 'coffee roasters in Dubai';
    });
});

it('reports a per-request cost', function (): void {
    config()->set('lead-sourcing.providers.google_places.cost_micros_per_request', 32_000);

    expect(app(GooglePlacesProvider::class)->costMicros(20))->toBe(32_000);
});

it('throws a domain exception when the Places API fails', function (): void {
    Http::fake(['places.googleapis.com/*' => Http::response('error', 500)]);

    expect(fn () => app(GooglePlacesProvider::class)->discover(placesQuery()))
        ->toThrow(LeadSourcingException::class);
});

it('throws (and sends nothing) when the api key is missing', function (): void {
    config()->set('lead-sourcing.providers.google_places.api_key', '');
    Http::fake();

    expect(fn () => app(GooglePlacesProvider::class)->discover(placesQuery()))
        ->toThrow(LeadSourcingException::class);

    Http::assertNothingSent();
});

it('falls back to the maps url and keys website-less places by place id', function (): void {
    config()->set('lead-sourcing.providers.google_places.maps_url', 'https://maps.example/?id=');
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [
                ['id' => 'PID1', 'displayName' => ['text' => 'No-Site Cafe'], 'formattedAddress' => 'Dubai'],
            ],
        ]),
    ]);

    $leads = app(GooglePlacesProvider::class)->discover(placesQuery());

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->website)->toBeNull()
        ->and($leads[0]->sourceUrl)->toBe('https://maps.example/?id=PID1')
        ->and($leads[0]->dedupeKey())->toBe('ext:google_places:pid1');
});

it('reports zero cost for a no-op run', function (): void {
    expect(app(GooglePlacesProvider::class)->costMicros(0))->toBe(0);
});
