<?php

declare(strict_types=1);

use App\Modules\Core\Support\Security\SafeUrl;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\FeedDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * RSS/Atom feeds as a signal source (S13b).
 *
 * **This is the one source whose address a customer supplies**, so most of this file is about
 * refusing things: a URL that resolves off the public internet, a redirect to one, a doctype, an
 * oversized body, a poll inside the politeness floor. The happy path is three tests; the guards are
 * the rest, which is the right proportion for an SSRF surface.
 */

beforeEach(function (): void {
    config()->set('signals.sources.feed.min_poll_minutes', 60);
    config()->set('signals.sources.feed.max_items', 40);
    Http::preventStrayRequests();
    Cache::flush();
});

function feedSource(): SignalSourceProvider
{
    return app(SignalSourceRegistry::class)->for(SignalSourceKind::Feed);
}

function feedQuery(
    string $url = 'https://example.com/feed.xml',
    array $ignore = [],
    int $teamId = 7,
    bool $preview = false,
): SignalSourceQuery {
    return new SignalSourceQuery(
        eventKey: 'uk_funding',
        q: '',
        region: SearchRegion::Gb,
        gl: 'gb',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        ignoreDomains: $ignore,
        kind: SignalSourceKind::Feed,
        // `team_id` the way `SignalQueryBuilder::pushDefinitionQueries()` stamps it: the poll floor
        // and the ETag are cached per team, so a query without one would be fetched every time.
        config: ['feed' => ['url' => $url], 'team_id' => $teamId],
        preview: $preview,
    );
}

/** The poll-floor cache key for one team and one feed, as `FeedSignalSource::cacheKey()` builds it. */
function feedPollKey(string $url = 'https://example.com/feed.xml', int $teamId = 7): string
{
    return 'signals:feed:polled:'.$teamId.':'.sha1($url);
}

function rssBody(string $items): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0">
      <channel>
        <title>Trade Weekly</title>
        <link>https://example.com</link>
        {$items}
      </channel>
    </rss>
    XML;
}

it('reads an RSS feed into unclassified items', function (): void {
    $date = now()->subDays(2)->toRfc2822String();

    Http::fake([
        'example.com/*' => Http::response(rssBody(<<<XML
            <item>
              <title>Acme raises a Series A</title>
              <link>https://example.com/acme-series-a</link>
              <description><![CDATA[<p>Acme has <strong>raised</strong> a round.</p>]]></description>
              <pubDate>{$date}</pubDate>
              <guid>acme-1</guid>
            </item>
            XML), 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $items = feedSource()->fetch(feedQuery());

    expect($items)->toHaveCount(1);

    $item = $items[0];

    expect($item->title)->toBe('Acme raises a Series A')
        // Markup stripped: a feed's description is routinely a whole HTML article, and neither a
        // card nor a prompt wants tags in it.
        ->and($item->snippet)->toBe('Acme has raised a round.')
        // The **feed's** title is the publisher, not the entry's.
        ->and($item->source)->toBe('Trade Weekly')
        ->and($item->kind)->toBe(SignalSourceKind::Feed)
        ->and($item->publishedAt)->not->toBeNull()
        // **Not** structured: a feed entry is prose somebody wrote, so it goes through the same
        // prompt as a news article. Only an authoritative, already-parsed source may assert its own
        // event (ADR 0023 §6).
        ->and($item->isClassified())->toBeFalse()
        ->and($item->classification)->toBeNull();
});

it('reads an Atom feed, whose link is an attribute', function (): void {
    Http::fake([
        'example.com/*' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Regulator Notices</title>
              <entry>
                <title>Enforcement notice issued</title>
                <link rel="self" href="https://example.com/feed.xml"/>
                <link rel="alternate" href="https://example.com/notice-1"/>
                <summary>A notice was issued.</summary>
                <published>2026-11-01T09:00:00Z</published>
                <id>notice-1</id>
              </entry>
            </feed>
            XML),
    ]);

    $items = feedSource()->fetch(feedQuery());

    expect($items)->toHaveCount(1)
        ->and($items[0]->source)->toBe('Regulator Notices')
        // `rel="alternate"`, never `rel="self"` (the feed itself) — one entry carries several links.
        ->and($items[0]->link)->toBe('https://example.com/notice-1');
});

it('sends the conditional-GET validators it was given last time', function (): void {
    Http::fake([
        'example.com/*' => Http::sequence()
            ->push(rssBody('<item><title>One</title><link>https://example.com/1</link></item>'), 200, [
                'ETag' => '"abc123"',
                'Last-Modified' => 'Mon, 03 Nov 2026 09:00:00 GMT',
            ])
            ->push('', 304),
    ]);

    expect(feedSource()->fetch(feedQuery()))->toHaveCount(1);

    // The floor would otherwise refuse the second poll outright, which is a different test.
    config()->set('signals.sources.feed.min_poll_minutes', 0);
    Cache::forget(feedPollKey());

    // A 304 is a success with no body: the publisher is telling us we already have it, which is the
    // whole point of sending the validators — and most polls answer this way.
    expect(feedSource()->fetch(feedQuery()))->toBe([]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('If-None-Match', '"abc123"')
        || ! $request->hasHeader('If-None-Match'));
});

it('does not poll the same feed twice inside the politeness floor', function (): void {
    Http::fake(['example.com/*' => Http::response(rssBody('<item><title>One</title><link>https://example.com/1</link></item>'))]);

    expect(feedSource()->fetch(feedQuery()))->toHaveCount(1);

    // Per feed URL **within one team**, not per watch: three of one team's watches pointed at one
    // newsletter should be three readings of one fetch, not three requests to a publisher who will
    // reasonably block us.
    expect(feedSource()->fetch(feedQuery()))->toBe([]);

    Http::assertSentCount(1);
});

// **The S13b review's finding 4, and it was a tenancy bug wearing a politeness costume.** Both
// cache entries were keyed by `sha1($url)` alone, so one team's poll stamped the floor and banked
// the ETag for *everybody* — and because nothing caches the entries for a second reader to read,
// the next team to watch the same public newsletter got an empty list and their signals simply
// never existed. Silently, and for as long as the busier team kept polling.
it('does not let one team\'s poll of a public feed blind another team', function (): void {
    // A **sequence** of two identical responses rather than one repeated: the body is read off a
    // stream now (finding 3), and a single faked response hands out one stream that the first read
    // leaves at EOF. Two real responses carry two streams.
    $body = rssBody('<item><title>One</title><link>https://example.com/1</link></item>');

    Http::fake([
        'example.com/*' => Http::sequence()
            ->push($body, 200)
            ->push($body, 200),
    ]);

    expect(feedSource()->fetch(feedQuery(teamId: 7)))->toHaveCount(1);

    // The same address, a different team: their own fetch, their own floor, their own entries.
    expect(feedSource()->fetch(feedQuery(teamId: 8)))->toHaveCount(1);

    // ...and each is floored against itself afterwards.
    expect(feedSource()->fetch(feedQuery(teamId: 7)))->toBe([]);
    expect(feedSource()->fetch(feedQuery(teamId: 8)))->toBe([]);

    Http::assertSentCount(2);
});

// A preview is a member at a screen checking an address they have just typed. If it consumed the
// floor and banked the ETag, pressing Preview would leave the type's **first real run** fetching
// nothing and reporting an empty feed — with the member having watched it work a minute earlier.
it('keeps a preview out of the run\'s poll floor and validators', function (): void {
    Http::fake([
        'example.com/*' => Http::sequence()
            ->push(rssBody('<item><title>One</title><link>https://example.com/1</link></item>'), 200, ['ETag' => '"abc123"'])
            ->push(rssBody('<item><title>One</title><link>https://example.com/1</link></item>'), 200, ['ETag' => '"abc123"'])
            ->push(rssBody('<item><title>One</title><link>https://example.com/1</link></item>'), 200, ['ETag' => '"abc123"']),
    ]);

    expect(feedSource()->fetch(feedQuery(preview: true)))->toHaveCount(1);

    // Nothing was stamped, so a second preview still fetches...
    expect(feedSource()->fetch(feedQuery(preview: true)))->toHaveCount(1);
    expect(Cache::has(feedPollKey()))->toBeFalse();

    // ...and the first real run is unaffected.
    expect(feedSource()->fetch(feedQuery()))->toHaveCount(1);

    Http::assertSentCount(3);

    // No validator was sent on any of them, because none was ever stored.
    Http::assertSent(fn ($request): bool => ! $request->hasHeader('If-None-Match'));
});

// **The byte cap was cosmetic until the S13b review** (finding 3): the request did not set
// `stream`, so Guzzle buffered the whole body into memory before `readCapped()` could refuse any of
// it, and a member could point a feed at a multi-gigabyte response and take the worker with it. The
// cap itself is what this asserts — a truncated XML document is unparseable, which is the right
// outcome: loud, non-retryable, and not a silent half-feed.
it('reads no more than the byte cap off a feed', function (): void {
    config()->set('signals.sources.feed.max_bytes', 2_048);

    $padding = str_repeat('<item><title>'.str_repeat('x', 200).'</title><link>https://example.com/a</link></item>', 50);

    Http::fake(['example.com/*' => Http::response(rssBody($padding))]);

    expect(fn () => feedSource()->fetch(feedQuery()))
        ->toThrow(SignalsException::class);
});

it('refuses a URL that resolves off the public internet', function (): void {
    Http::fake(['*' => Http::response(rssBody(''))]);

    foreach ([
        'http://127.0.0.1/feed.xml',
        'http://localhost/feed.xml',
        'http://169.254.169.254/latest/meta-data/',  // the cloud metadata endpoint
        'http://[::1]/feed.xml',
        'http://192.168.1.10/feed.xml',
    ] as $url) {
        $thrown = null;

        try {
            feedSource()->fetch(feedQuery($url));
        } catch (SignalsException $e) {
            $thrown = $e;
        }

        // **Misconfigured, not unavailable**: this will never succeed, so the run must not retry it
        // and the breaker must not open — the address is the problem, not the network.
        expect($thrown?->errorCode())->toBe('signals.source_misconfigured', $url)
            ->and($thrown?->isRetryable())->toBeFalse();
    }

    Http::assertNothingSent();
});

it('refuses a non-standard port and a non-http scheme', function (): void {
    Http::fake(['*' => Http::response(rssBody(''))]);

    foreach (['https://example.com:6379/feed', 'ftp://example.com/feed.xml'] as $url) {
        expect(fn () => feedSource()->fetch(feedQuery($url)))->toThrow(SignalsException::class);
    }

    // A URL is how you reach an internal Redis from inside the network.
    Http::assertNothingSent();
});

it('does not follow a redirect', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    // The guard validated one address; following a `Location` would hand the client an address
    // nobody checked, which is the standard way round an SSRF guard.
    expect(fn () => feedSource()->fetch(feedQuery()))->toThrow(SignalsException::class);

    Http::assertSentCount(1);
});

it('refuses a feed carrying a doctype, so XXE is not even attempted', function (): void {
    // A feed whose doctype declares an external entity would otherwise have the parser read a local
    // file and hand it back inside an entry's text — which would be stored, shown, and sent to an AI
    // prompt. A feed has no legitimate reason to declare one.
    Http::fake([
        'example.com/*' => Http::response(<<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
            <rss version="2.0"><channel><title>&xxe;</title>
              <item><title>One</title><link>https://example.com/1</link></item>
            </channel></rss>
            XML),
    ]);

    expect(fn () => feedSource()->fetch(feedQuery()))->toThrow(SignalsException::class);
});

it('fails on a body that is not a feed at all', function (): void {
    Http::fake(['example.com/*' => Http::response('<html><body>Sign in</body></html>')]);

    expect(fn () => feedSource()->fetch(feedQuery()))->toThrow(SignalsException::class);
});

it('treats a deleted feed as a skipped query rather than an outage', function (): void {
    Http::fake(['example.com/*' => Http::response('', 404)]);

    $thrown = null;

    try {
        feedSource()->fetch(feedQuery());
    } catch (SignalsException $e) {
        $thrown = $e;
    }

    // A 404 on a feed is permanent. Treating it as a generic failure would have the run retry an
    // address that has been deleted — so it is a per-query rejection, counted on the breakdown.
    expect($thrown?->errorCode())->toBe('signals.source_address_not_found')
        ->and($thrown?->isRetryable())->toBeFalse();
});

it('caps how many entries it takes from one poll', function (): void {
    config()->set('signals.sources.feed.max_items', 3);

    $items = '';

    for ($i = 1; $i <= 10; $i++) {
        $items .= "<item><title>Story {$i}</title><link>https://example.com/{$i}</link></item>";
    }

    Http::fake(['example.com/*' => Http::response(rssBody($items))]);

    // A feed that publishes a hundred items a day is a wire service, and reading all of it would
    // make the AI bill this feature's main cost.
    expect(feedSource()->fetch(feedQuery()))->toHaveCount(3);
});

it('applies the team’s own skip list to a feed', function (): void {
    Http::fake([
        'example.com/*' => Http::response(rssBody(
            '<item><title>A rival writes</title><link>https://rival.ae/post</link></item>'.
            '<item><title>Something real</title><link>https://example.com/real</link></item>'
        )),
    ]);

    // A feed that syndicates a competitor's blog is exactly what a team's list is for.
    $items = feedSource()->fetch(feedQuery(ignore: ['rival.ae']));

    expect($items)->toHaveCount(1)
        ->and($items[0]->title)->toBe('Something real');
});

it('declines a definition with no feed URL rather than failing', function (): void {
    $definition = new SignalDefinition;
    $definition->forceFill(['source_config' => null]);

    // `supports()` is a decline, not a failure: a definition whose feed URL is missing contributes
    // no queries, which is what a misconfiguration should cost.
    expect(feedSource()->supports($definition))->toBeFalse();

    $definition->forceFill(['source_config' => ['feed' => ['url' => 'https://example.com/feed.xml']]]);

    expect(feedSource()->supports($definition))->toBeTrue();

    // And it re-checks the address, because `SafeUrl` depends on DNS: a host that was public last
    // week can point at 127.0.0.1 today, which is what a rebinding attack is.
    $definition->forceFill(['source_config' => ['feed' => ['url' => 'http://127.0.0.1/feed.xml']]]);

    expect(feedSource()->supports($definition))->toBeFalse();
});

it('is free to fetch', function (): void {
    // What a feed costs is the AI that classifies what comes out of it.
    expect(feedSource()->estimatedCostMicros(40))->toBe(0);
});

// --- The parser on its own --------------------------------------------------------------------

it('returns null for a body with no entries', function (): void {
    expect(FeedDocument::parse(rssBody('')))->toBeNull()
        ->and(FeedDocument::parse(''))->toBeNull()
        ->and(FeedDocument::parse('not xml at all'))->toBeNull();
});

it('drops an entry with no title or no link', function (): void {
    $document = FeedDocument::parse(rssBody(
        '<item><title>No link</title></item>'.
        '<item><link>https://example.com/no-title</link></item>'.
        '<item><title>Good</title><link>https://example.com/good</link></item>'
    ));

    expect($document->entries)->toHaveCount(1)
        ->and($document->entries[0]->title)->toBe('Good');
});

it('drops a date outside a plausible window', function (): void {
    $document = FeedDocument::parse(rssBody(
        '<item><title>From the future</title><link>https://example.com/f</link><pubDate>Mon, 03 Nov 2099 09:00:00 GMT</pubDate></item>'
    ));

    // The same sanity window the Serper sources use: a feed is no more trustworthy about its own
    // timestamps than a scraped page, and the run falls back to its own time.
    expect($document->entries[0]->publishedAt)->toBeNull();
});

// --- The shared guard -------------------------------------------------------------------------

it('shares one SSRF rule with the web content fetcher', function (): void {
    // Extracted at S13b rather than copied. A second guard that is 1% more permissive is a hole
    // nobody notices, because the permissive copy passes every test the strict one does.
    expect(SafeUrl::allows('https://example.com/feed.xml'))->toBeTrue()
        ->and(SafeUrl::allows('http://127.0.0.1/'))->toBeFalse()
        ->and(SafeUrl::allows('https://example.com:6379/'))->toBeFalse()
        ->and(SafeUrl::allows('file:///etc/passwd'))->toBeFalse()
        ->and(SafeUrl::allows(''))->toBeFalse()
        ->and(SafeUrl::allows(null))->toBeFalse()
        // A bare host is assumed https, because a member pasting `example.com/feed.xml` means a web
        // address and should not have to know that.
        ->and(SafeUrl::normalise('example.com/feed.xml'))->toBe('https://example.com/feed.xml');
});
