<?php

declare(strict_types=1);

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Exceptions\SourceQueryRejected;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Serper `/search` as a signal source (S13a, ADR 0023).
 *
 * **The payloads here are the shapes the live spike actually returned**, including the one whose
 * `date` field held the string "Exciting news" and the 400 the account tier answers a `site:` query
 * with. That is deliberate: the S12 review's lesson was that the tests which passed for the wrong
 * reason were the ones whose double was gentler than the product, and an endpoint's handling is only
 * worth asserting against what the endpoint does.
 */
beforeEach(function (): void {
    config()->set('signals.serper.provider', 'serper');
    config()->set('signals.serper.api_key', 'test-key');
    config()->set('signals.serper.base_url', 'https://google.serper.dev');
    Http::preventStrayRequests();
    Cache::flush();
});

function webSearchQuery(string $q = 'London company announces new office'): SignalSourceQuery
{
    return new SignalSourceQuery(
        eventKey: SignalPresetCatalogue::FUNDING_ROUND,
        q: $q,
        region: SearchRegion::Gb,
        gl: 'gb',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        kind: SignalSourceKind::WebSearch,
    );
}

function webSearchSource(): SignalSourceProvider
{
    return app(SignalSourceRegistry::class)->for(SignalSourceKind::WebSearch);
}

it('posts to /search and reads the organic array', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'searchParameters' => ['q' => 'x'],
            'organic' => [
                [
                    'title' => 'Arrive Expands UK Presence with Launch of New Office',
                    'link' => 'https://www.parking.net/parking-news/arrive/expands-uk',
                    'displayedLink' => 'parking.net › parking-news › arrive',
                    'snippet' => 'Arrive has opened a new London office.',
                    'date' => '5 hours ago',
                    'position' => 1,
                ],
            ],
            // `/news` would put its results here; a web-search source must not read them.
            'news' => [['title' => 'Should not be read', 'link' => 'https://example.com/x']],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(SourceItem::class)
        ->and($items[0]->title)->toBe('Arrive Expands UK Presence with Launch of New Office')
        ->and($items[0]->kind)->toBe(SignalSourceKind::WebSearch);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/search')
        && ! str_contains($request->url(), '/news')
        && $request->hasHeader('X-API-KEY', 'test-key')
        && $request->data()['tbs'] === 'qdr:w');
});

it('names the host as the outlet, because /search does not say who published anything', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [[
                'title' => 'The Profs Receives The Kings Award for Enterprise',
                'link' => 'https://www.gulfnews.com/business/property/the-profs',
                // A display string Google composes — neither a host nor reliably parseable, and
                // deliberately not what the outlet is read from.
                'displayedLink' => 'gulfnews.com › business › property',
                'snippet' => 'An award was given.',
            ]],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    // Normalised: the `www.` is gone, because that is what the skip list compares against.
    expect($items[0]->source)->toBe('gulfnews.com');
});

it('treats a date field that is not a date as no date at all', function (): void {
    // The live spike returned exactly this: Serper scrapes `/search` dates off the page, and one
    // result's `date` was the literal string "Exciting news".
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                [
                    'title' => 'Bof named GO Awards Wales supplier of the year finalist',
                    'link' => 'https://example.co.uk/bof-award',
                    'snippet' => 'A finalist was named.',
                    'date' => 'Exciting news',
                ],
                [
                    'title' => 'A real one',
                    'link' => 'https://example.co.uk/real',
                    'snippet' => 'Something happened.',
                    'date' => '3 days ago',
                ],
            ],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    expect($items)->toHaveCount(2)
        // Null, not the epoch and not today: the caller falls back to the run time, which it has
        // always done for an unparseable date.
        ->and($items[0]->publishedAt)->toBeNull()
        ->and($items[1]->publishedAt)->not->toBeNull();
});

it('rejects a date outside a plausible window', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'From the future', 'link' => 'https://example.co.uk/a', 'date' => now()->addYears(3)->toDateString()],
                ['title' => 'From the archive', 'link' => 'https://example.co.uk/b', 'date' => '1931-04-02'],
            ],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    expect($items)->toHaveCount(2)
        ->and($items[0]->publishedAt)->toBeNull()
        ->and($items[1]->publishedAt)->toBeNull();
});

it('drops a job board from its own skip list, which the global list does not name', function (): void {
    // `indeed.com` is in `signals.sources.web_search.ignore_domains` and **not** in the global
    // `signals.ignore_domains` — the global list was written for a news feed, and job aggregators
    // are a shape of page only this endpoint returns.
    expect(config('signals.ignore_domains'))->not->toContain('indeed.com');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'Head of Operations Job in London WC2N', 'link' => 'https://uk.indeed.com/q-head-of-operations-jobs.html'],
                ['title' => '11 Operations manager jobs in London', 'link' => 'https://www.charityjob.co.uk/operations-manager-jobs'],
                ['title' => 'Genuine expansion story', 'link' => 'https://example.co.uk/real'],
            ],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    // The subdomain is covered by the parent entry, which is what host-or-parent matching is for.
    expect($items)->toHaveCount(1)
        ->and($items[0]->title)->toBe('Genuine expansion story');
});

it('does not apply its own skip list to the news source', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [[
                'title' => 'Indeed reports a hiring surge across the UK',
                'link' => 'https://uk.indeed.com/career-advice/hiring-surge',
                'source' => 'Indeed',
            ]],
        ]),
    ]);

    // A source's list is its own (`SignalSourceQuery`): a job board republishing research is noise
    // on a web-search page and a legitimate story on a news one.
    $items = app(SignalSourceRegistry::class)
        ->for(SignalSourceKind::News)
        ->fetch(new SignalSourceQuery(
            eventKey: SignalPresetCatalogue::FUNDING_ROUND,
            q: 'UK hiring surge',
            region: SearchRegion::Gb,
            gl: 'gb',
            hl: 'en',
            tbs: 'qdr:w',
            num: 20,
            kind: SignalSourceKind::News,
        ));

    expect($items)->toHaveCount(1);
});

it('treats a refused query pattern as a per-query rejection, and leaves the breaker shut', function (): void {
    // The live account tier's actual answer to a `site:` or quoted query.
    Http::fake([
        'google.serper.dev/*' => Http::response(
            ['message' => 'Query pattern not allowed for free accounts.', 'statusCode' => 400],
            400,
        ),
    ]);

    $thrown = null;

    try {
        webSearchSource()->fetch(webSearchQuery('site:linkedin.com/jobs London head of sales'));
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SignalsException::class)
        ->and($thrown->errorCode())->toBe('signals.source_query_rejected')
        // Not retryable: the query can never be accepted, so retrying it burns the job's attempts
        // and re-bills every other query in the plan.
        ->and($thrown->isRetryable())->toBeFalse()
        ->and($thrown->source())->toBe('web_search');

    // And the breaker is **shut**: a template with a quote in it says nothing about whether Serper
    // is up, and opening the circuit would make one team's bad template everybody's outage.
    $breaker = new CircuitBreaker(app(Repository::class), 5, 60, prefix: 'signals:cb');
    expect($breaker->isOpen('signals_web_search'))->toBeFalse()
        ->and($breaker->failures('signals_web_search'))->toBe(0);
});

it('treats a 5xx as transient: retryable, and the breaker records it', function (): void {
    config()->set('signals.serper.retry_times', 1);
    config()->set('signals.circuit_breaker.threshold', 1);

    Http::fake(['google.serper.dev/*' => Http::response('', 503)]);

    $thrown = null;

    try {
        webSearchSource()->fetch(webSearchQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown?->errorCode())->toBe('signals.source_unavailable')
        ->and($thrown?->isRetryable())->toBeTrue();

    $breaker = new CircuitBreaker(app(Repository::class), 1, 60, prefix: 'signals:cb');
    expect($breaker->isOpen('signals_web_search'))->toBeTrue()
        // Its **own** key: a web-search outage must not take the news down with it, which is the
        // whole reason the breaker is keyed per source.
        ->and($breaker->isOpen('signals_news'))->toBeFalse();
});

it('fails on a 200 whose body is not the expected shape', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response('<html>sign in</html>')]);

    expect(fn () => webSearchSource()->fetch(webSearchQuery()))
        ->toThrow(SignalsException::class);
});

it('fails fast and sends nothing when the API key is missing', function (): void {
    config()->set('signals.serper.api_key', '');
    Http::fake(['google.serper.dev/*' => Http::response(['organic' => []])]);

    $thrown = null;

    try {
        webSearchSource()->fetch(webSearchQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown?->errorCode())->toBe('signals.source_misconfigured')
        ->and($thrown?->isRetryable())->toBeFalse();

    Http::assertNothingSent();
});

it('collapses duplicates within one response', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'One story', 'link' => 'https://example.co.uk/story?utm_source=a'],
                ['title' => 'One story', 'link' => 'https://example.co.uk/story?utm_source=b'],
            ],
        ]),
    ]);

    expect(webSearchSource()->fetch(webSearchQuery()))->toHaveCount(1);
});

it('uses the URL as the dedupe identity, because /search links are not redirectors', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [['title' => 'Direct link', 'link' => 'https://example.co.uk/direct']],
        ]),
    ]);

    $items = webSearchSource()->fetch(webSearchQuery());

    // The content hash exists (it always does) but the identity the run compares is the link's,
    // which is the shared normaliser's behaviour for a host that is not a known redirector.
    expect($items[0]->dedupeKey())->toBe($items[0]->urlHash)
        ->and($items[0]->contentHash)->not->toBeNull();
});

it('treats a 400 that is not a refused pattern as a run failure, not a skipped query', function (): void {
    // **The S13a review's first finding.** A 400 is the most overloaded code a search API has: the
    // same endpoint answers a refused query pattern, an exhausted account, a parameter we are
    // sending wrongly, and a changed contract with one. Classifying all of them as "the member's
    // template was bad" would complete every run green with nothing found, no retry and the breaker
    // deliberately shut — for ever, silently.
    Http::fake([
        'google.serper.dev/*' => Http::response(
            ['message' => 'Not enough credits', 'statusCode' => 400],
            400,
        ),
    ]);

    $thrown = null;

    try {
        webSearchSource()->fetch(webSearchQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SignalsException::class)
        // Not the per-query verdict, and therefore not swallowed by the run's loop.
        ->and($thrown)->not->toBeInstanceOf(SourceQueryRejected::class)
        ->and($thrown->errorCode())->toBe('signals.source_failed')
        // Non-retryable: an exhausted account will not fix itself by being asked three more times.
        ->and($thrown->isRetryable())->toBeFalse();
});

it('recognises a refused pattern by its body, which is where the marker lives', function (): void {
    config()->set('signals.serper.query_rejection_markers', ['query pattern not allowed']);

    Http::fake([
        'google.serper.dev/*' => Http::response(
            // Mixed case, to prove the comparison is not case-sensitive.
            ['message' => 'Query Pattern Not Allowed for free accounts.', 'statusCode' => 400],
            400,
        ),
    ]);

    expect(fn () => webSearchSource()->fetch(webSearchQuery()))
        ->toThrow(SourceQueryRejected::class);
});

it('treats every 400 as a failure when no marker is configured', function (): void {
    // Fails safe: an empty list means "no 400 is ever a query rejection", so a deployment that
    // cleared the config loses the skip-and-continue behaviour rather than gaining a silent outage.
    config()->set('signals.serper.query_rejection_markers', []);

    Http::fake([
        'google.serper.dev/*' => Http::response(['message' => 'Query pattern not allowed.'], 400),
    ]);

    $thrown = null;

    try {
        webSearchSource()->fetch(webSearchQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeInstanceOf(SourceQueryRejected::class)
        ->and($thrown->errorCode())->toBe('signals.source_failed');
});
