<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
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

    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
        'location' => 'Dubai',
        'event_types' => ['funding_round'],
        'ignore_domains' => $ignore,
    ]));
}

it('carries a watch’s own ignore list onto every query it builds', function (): void {
    $watch = watchIgnoring(['rival.ae']);

    $queries = app(SignalQueryBuilder::class)->build($watch);

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

    $queries = app(SignalQueryBuilder::class)->build($watch);

    expect($queries[0]->ignoreDomains)->toBe(['rival.ae', 'other-rival.com']);
});

it('leaves the list empty when the watch has none', function (): void {
    $watch = watchIgnoring([]);

    expect(app(SignalQueryBuilder::class)->build($watch)[0]->ignoreDomains)->toBe([]);
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

    $items = app(NewsSearchClient::class)->search(new SignalQuery(
        eventType: SignalEventType::FundingRound,
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

    $items = app(NewsSearchClient::class)->search(new SignalQuery(
        eventType: SignalEventType::FundingRound,
        q: 'Dubai startup raises funding',
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
    ));

    expect($items)->toBe([]);
});
