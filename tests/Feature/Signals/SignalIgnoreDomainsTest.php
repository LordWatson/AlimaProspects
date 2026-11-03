<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('signals.serper.provider', 'serper');
    config()->set('signals.serper.api_key', 'test-key');
    config()->set('signals.serper.base_url', 'https://google.serper.dev');
    // No test may touch the real network.
    Http::preventStrayRequests();
});

/**
 * @param  list<string>  $ignore
 */
function watchIgnoring(array $ignore): SignalWatch
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
        ->create([
            'team_id' => $team->getKey(),
            'locations' => ['Dubai'],
            'ignore_domains' => $ignore,
        ]));
}

/**
 * The queries that watch would run. Since S7 the builder takes the definitions as an argument
 * rather than loading them (ADR 0018), so a caller has to say which types it is building for - here,
 * whatever the watch is attached to.
 *
 * @return list<SignalSourceQuery>
 */
function queriesFor(SignalWatch $watch): array
{
    return app(SignalQueryBuilder::class)->build($watch, $watch->activeDefinitions()->all())->queries;
}

it('carries a watch’s own ignore list onto every query it builds', function (): void {
    $watch = watchIgnoring(['rival.ae']);

    $queries = queriesFor($watch);

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        // Carried on the query rather than read from config by the client, so the client holds no
        // notion of which team asked and can't apply one team's list to another's results.
        expect($query->ignoreDomains)->toBe(['rival.ae']);
    }
});

it('normalises whatever the team typed into a bare host', function (): void {
    // A team types what they see: a mixed-case host, a pasted URL, a `www.` prefix, a blank row.
    $watch = watchIgnoring([
        'www.Rival.ae',
        'https://other-rival.com/news/page',
        '  ',
        'RIVAL.ae',
    ]);

    $queries = queriesFor($watch);

    expect($queries[0]->ignoreDomains)->toBe(['rival.ae', 'other-rival.com']);
});

it('leaves the list empty when the watch has none', function (): void {
    $watch = watchIgnoring([]);

    expect(queriesFor($watch)[0]->ignoreDomains)->toBe([]);
});

it('drops results from a team’s own ignored domain as well as the global list', function (): void {
    config()->set('signals.ignore_domains', ['bayut.com']);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                [
                    'title' => 'A genuine Dubai funding round',
                    'link' => 'https://gulfbusiness.com/real-event',
                    'snippet' => 'A Dubai company raised a round.',
                    'source' => 'Gulf Business',
                ],
                [
                    // The client's competitor: only that client knows to exclude it.
                    'title' => 'Rival agency blog post',
                    'link' => 'https://rival.ae/blog/post',
                    'snippet' => 'Marketing copy.',
                    'source' => 'Rival',
                ],
                [
                    // A property portal, dropped by the global config list.
                    'title' => 'Property listing',
                    'link' => 'https://bayut.com/listing',
                    'snippet' => 'For sale.',
                    'source' => 'Bayut',
                ],
            ],
        ]),
    ]);

    $items = app(SignalSourceProvider::class)->fetch(new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        ignoreDomains: ['rival.ae'],
    ));

    expect($items)->toHaveCount(1)
        ->and($items[0]->link)->toBe('https://gulfbusiness.com/real-event');
});

it('still applies the global list when a watch adds none', function (): void {
    config()->set('signals.ignore_domains', ['bayut.com']);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                [
                    'title' => 'Property listing',
                    'link' => 'https://bayut.com/listing',
                    'snippet' => 'For sale.',
                    'source' => 'Bayut',
                ],
            ],
        ]),
    ]);

    $items = app(SignalSourceProvider::class)->fetch(new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
    ));

    expect($items)->toBe([]);
});

// --- Matching the outlet's name (S11) -----------------------------------------------------------

it('drops a result by the outlet’s name, not only by its host', function (): void {
    config()->set('signals.ignore_domains', []);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                [
                    'title' => 'A genuine Dubai funding round',
                    'link' => 'https://gulfbusiness.com/real-event',
                    'snippet' => 'A Dubai company raised a round.',
                    'source' => 'Gulf Business',
                ],
                [
                    // Behind a Google redirect, the host is `google.com` for **every** item, so a
                    // host-only list cannot express "not this publisher" about the items most in
                    // need of it - and adding `google.com` would silently drop a large share of
                    // everything. The outlet's name is the only handle there is.
                    'title' => 'Another Dubai round',
                    'link' => 'https://google.com/goto?url=OPAQUE-TOKEN',
                    'snippet' => 'Also a round.',
                    'source' => 'Khaleej Times',
                ],
            ],
        ]),
    ]);

    $items = app(SignalSourceProvider::class)->fetch(new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        // What a team actually types when they mean an outlet rather than a domain.
        ignoreDomains: ['Khaleej Times'],
    ));

    expect($items)->toHaveCount(1)
        ->and($items[0]->source)->toBe('Gulf Business');
});

it('compares an outlet name as a slug, so spacing and case do not matter', function (): void {
    config()->set('signals.ignore_domains', ['  KHALEEJ   times ']);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [[
                'title' => 'A Dubai round',
                'link' => 'https://google.com/goto?url=TOKEN',
                'snippet' => 'A round.',
                'source' => 'Khaleej Times',
            ]],
        ]),
    ]);

    $items = app(SignalSourceProvider::class)->fetch(new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
    ));

    expect($items)->toBe([]);
});

it('never drops an item merely because the provider named no outlet', function (): void {
    config()->set('signals.ignore_domains', ['Khaleej Times']);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [[
                'title' => 'A Dubai round',
                'link' => 'https://gulfbusiness.com/round',
                'snippet' => 'A round.',
                // No `source` at all.
            ]],
        ]),
    ]);

    $items = app(SignalSourceProvider::class)->fetch(new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        region: SearchRegion::Ae,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
    ));

    // "We were not told who published this" is not the same statement as "this publisher is
    // excluded", and treating it as one would drop every item whose provider omitted the field.
    expect($items)->toHaveCount(1);
});
