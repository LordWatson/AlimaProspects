<?php

declare(strict_types=1);

use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\NewsItem;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Exceptions\SignalsException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('signals.serper.provider', 'serper');
    config()->set('signals.serper.api_key', 'test-key');
    config()->set('signals.serper.base_url', 'https://google.serper.dev');
    // No test may touch the real network: every one must fake Http or send nothing.
    Http::preventStrayRequests();
});

function newsQuery(string $q = 'Dubai startup raises funding'): SignalQuery
{
    return new SignalQuery(
        eventType: SignalEventType::FundingRound,
        q: $q,
        gl: 'ae',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
    );
}

it('maps a /news payload into normalised news items', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                [
                    'title' => 'Dubai startup Acme closes $40m Series A',
                    'link' => 'https://gulfbusiness.com/acme-series-a',
                    'snippet' => 'Dubai-based Acme DMCC has raised a $40m Series A.',
                    'source' => 'Gulf Business',
                    'date' => '2 days ago',
                ],
                ['title' => '', 'link' => 'https://news.ae/no-title'], // skipped: no title
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(NewsItem::class)
        ->and($items[0]->title)->toBe('Dubai startup Acme closes $40m Series A')
        ->and($items[0]->source)->toBe('Gulf Business')
        ->and($items[0]->urlHash)->toHaveLength(40)
        ->and($items[0]->publishedAt)->not->toBeNull();
});

it('sends q, gl, hl, tbs and num with the API key header', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['news' => []])]);

    app(NewsSearchClient::class)->search(newsQuery('UAE Series B funding'));

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/news')
            && $request->hasHeader('X-API-KEY', 'test-key')
            && $body['q'] === 'UAE Series B funding'
            && $body['gl'] === 'ae'
            && $body['hl'] === 'en'
            && $body['tbs'] === 'qdr:w'
            && $body['num'] === 20;
    });
});

it('drops results on ignored portal/aggregator domains', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Luxury villa listings', 'link' => 'https://www.bayut.com/villas', 'snippet' => 'portal'],
                ['title' => 'Genuine event', 'link' => 'https://arabianbusiness.com/deal', 'snippet' => 'real'],
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0]->link)->toBe('https://arabianbusiness.com/deal');
});

it('rejects a result without a usable http(s) link', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Phishy', 'link' => 'mailto:someone@example.com', 'snippet' => 'x'],
                ['title' => 'Also phishy', 'link' => 'javascript:alert(1)', 'snippet' => 'x'],
            ],
        ]),
    ]);

    expect(app(NewsSearchClient::class)->search(newsQuery()))->toBe([]);
});

it('collapses duplicate articles (same canonical URL) within one response', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Deal', 'link' => 'https://arabianbusiness.com/deal', 'snippet' => 'a'],
                ['title' => 'Deal (shared)', 'link' => 'https://www.arabianbusiness.com/deal/?utm_source=x', 'snippet' => 'a'],
            ],
        ]),
    ]);

    expect(app(NewsSearchClient::class)->search(newsQuery()))->toHaveCount(1);
});

it('retries on a 429 then succeeds', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::sequence()
            ->push(['message' => 'rate limited'], 429)
            ->push(['news' => [['title' => 'Deal', 'link' => 'https://arabianbusiness.com/deal', 'snippet' => 'a']]], 200),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('fails with a retryable domain exception when the provider errors', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response('boom', 500)]);
    config()->set('signals.serper.retry_times', 0);

    try {
        app(NewsSearchClient::class)->search(newsQuery());
        $this->fail('Expected a SignalsException.');
    } catch (SignalsException $e) {
        expect($e->errorCode())->toBe('signals.news_provider_failed')
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('opens the circuit after repeated transient failures', function (): void {
    config()->set('signals.serper.retry_times', 0);
    config()->set('signals.circuit_breaker.threshold', 3);
    Http::fake(['google.serper.dev/*' => Http::response('boom', 500)]);

    foreach (range(1, 3) as $ignored) {
        try {
            app(NewsSearchClient::class)->search(newsQuery());
        } catch (SignalsException) {
            // expected
        }
    }

    // 3 failures reach the threshold, so the breaker is open: the next call is refused outright,
    // proven behaviourally — it throws and sends no HTTP request.
    Http::assertSentCount(3);
    expect(fn () => app(NewsSearchClient::class)->search(newsQuery()))->toThrow(SignalsException::class);
    Http::assertSentCount(3); // still 3 — the refused call sent nothing
});

it('does not trip the breaker on a 4xx response', function (): void {
    config()->set('signals.serper.retry_times', 0);
    config()->set('signals.circuit_breaker.threshold', 3);
    Http::fake(['google.serper.dev/*' => Http::response('bad request', 400)]);

    foreach (range(1, 5) as $ignored) {
        try {
            app(NewsSearchClient::class)->search(newsQuery());
        } catch (SignalsException) {
            // expected
        }
    }

    // All 5 calls reached the network — a 4xx never opens the breaker (only 5xx/429/connection do),
    // so none was refused.
    Http::assertSentCount(5);
});

it('does not retry a 4xx response', function (): void {
    config()->set('signals.serper.retry_times', 2);
    Http::fake(['google.serper.dev/*' => Http::response('bad request', 400)]);

    expect(fn () => app(NewsSearchClient::class)->search(newsQuery()))->toThrow(SignalsException::class);
    Http::assertSentCount(1); // a 4xx is our fault — not retried
});

it('fails fast and sends nothing when the API key is missing', function (): void {
    config()->set('signals.serper.api_key', '');
    Http::fake();

    try {
        app(NewsSearchClient::class)->search(newsQuery());
        $this->fail('Expected a SignalsException.');
    } catch (SignalsException $e) {
        expect($e->errorCode())->toBe('signals.news_provider_misconfigured')
            ->and($e->isRetryable())->toBeFalse();
    }

    Http::assertNothingSent();
});

it('rejects an unsupported news driver', function (): void {
    config()->set('signals.serper.provider', 'bing');
    Http::fake();

    expect(fn () => app(NewsSearchClient::class)->search(newsQuery()))
        ->toThrow(SignalsException::class);
    Http::assertNothingSent();
});

it('drops a subdomain of an ignored domain but keeps a look-alike', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Portal mobile', 'link' => 'https://m.bayut.com/listing', 'snippet' => 'x'],
                ['title' => 'Not the portal', 'link' => 'https://notbayut.com/story', 'snippet' => 'y'],
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0]->link)->toBe('https://notbayut.com/story');
});

it('leaves publishedAt null when the date is unparseable', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Deal', 'link' => 'https://arabianbusiness.com/deal', 'snippet' => 'a', 'date' => 'whenever'],
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0]->publishedAt)->toBeNull();
});

it('clamps oversized text and rejects an over-long link', function (): void {
    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                [
                    'title' => str_repeat('A', 5000),
                    'link' => 'https://arabianbusiness.com/'.str_repeat('a', 10),
                    'snippet' => str_repeat('B', 5000),
                    'source' => str_repeat('S', 5000),
                    'date' => str_repeat('d', 5000),
                ],
                // Over the 2048-char column limit → dropped (can't be stored as source_url).
                ['title' => 'Too long', 'link' => 'https://arabianbusiness.com/'.str_repeat('p', 3000), 'snippet' => 'x'],
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(newsQuery());

    expect($items)->toHaveCount(1)
        ->and(mb_strlen($items[0]->title))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($items[0]->snippet))->toBeLessThanOrEqual(1000)
        ->and(mb_strlen((string) $items[0]->source))->toBeLessThanOrEqual(255)
        ->and(mb_strlen((string) $items[0]->raw['date']))->toBeLessThanOrEqual(64);
});

it('fails on a 200 response whose body is not the expected shape', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response('<html>auth required</html>', 200)]);

    expect(fn () => app(NewsSearchClient::class)->search(newsQuery()))
        ->toThrow(SignalsException::class);
});

it('treats a valid JSON object with no news key as empty results', function (): void {
    Http::fake(['google.serper.dev/*' => Http::response(['searchParameters' => ['q' => 'x']], 200)]);

    expect(app(NewsSearchClient::class)->search(newsQuery()))->toBe([]);
});
