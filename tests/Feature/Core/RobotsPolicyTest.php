<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Services\HttpWebContentFetcher;
use App\Modules\Core\Support\RobotsPolicy;
use App\Modules\Core\Testing\FakeWebContentFetcher;
use Illuminate\Support\Facades\Http;

/*
 * `robots.txt` (Signals S12, `Core\Support`).
 *
 * `docs/compliance.md` §5 has required this since the beginning and nothing implemented it, because
 * until now the only pages the product fetched were a lead's own website. Reading a **news
 * publisher's** article is different in kind, and these are the cases that decide whether we are
 * honouring the file or merely claiming to.
 *
 * The two directions of failure are the thing to hold on to: an unreachable file **fails open**
 * (that is what the standard says, and treating a 404 as a prohibition would stop the product
 * reading most of the web), and an unreadable URL **fails closed** (a destination we cannot name is
 * not one we can check).
 */

beforeEach(function (): void {
    $this->fetcher = new FakeWebContentFetcher('');
    app()->instance(WebContentFetcher::class, $this->fetcher);
    app()->forgetInstance(RobotsPolicy::class);

    config()->set('web-fetch.user_agent', 'AlimaProspectsBot/1.0 (+https://alimaprospects.com/bot)');
});

function robotsFor(string $body = ''): RobotsPolicy
{
    test()->fetcher->returnsForUrl('https://news.test/robots.txt', $body);

    return app(RobotsPolicy::class);
}

it('allows a page when the host publishes no rules', function (): void {
    // Fails open on purpose: no `robots.txt` means no restriction.
    expect(robotsFor('')->allows('https://news.test/story'))->toBeTrue();
});

it('refuses a URL it cannot read', function (): void {
    // Fails closed: a destination we cannot name is not one we can check.
    expect(robotsFor('')->allows(null))->toBeFalse()
        ->and(robotsFor('')->allows('not a url'))->toBeFalse()
        ->and(robotsFor('')->allows('javascript:alert(1)'))->toBeFalse();
});

it('honours a wildcard disallow', function (): void {
    $policy = robotsFor("User-agent: *\nDisallow: /private\n");

    expect($policy->allows('https://news.test/private/story'))->toBeFalse()
        ->and($policy->allows('https://news.test/public/story'))->toBeTrue();
});

it('prefers the group that names us over the wildcard', function (): void {
    // The standard says the most specific matching group wins, and it is the opposite of merging
    // the two: a publisher who writes a permissive group for us and a restrictive one for
    // everybody means the permissive one.
    $policy = robotsFor(
        "User-agent: *\nDisallow: /\n\nUser-agent: alimaprospectsbot\nDisallow: /members\n",
    );

    expect($policy->allows('https://news.test/story'))->toBeTrue()
        ->and($policy->allows('https://news.test/members/story'))->toBeFalse();
});

it('lets a longer allow carve a path out of a disallow', function (): void {
    // Longest match wins, which is the rule that makes this expressible at all.
    $policy = robotsFor("User-agent: *\nDisallow: /news\nAllow: /news/public\n");

    expect($policy->allows('https://news.test/news/private'))->toBeFalse()
        ->and($policy->allows('https://news.test/news/public/story'))->toBeTrue();
});

it('reads an empty disallow as permission, not as a rule about every path', function (): void {
    // `Disallow:` with nothing after it means "nothing is disallowed". A parser that stored it as
    // a rule would match every URL with a zero-length pattern and refuse the whole site.
    expect(robotsFor("User-agent: *\nDisallow:\n")->allows('https://news.test/story'))->toBeTrue();
});

it('honours the two wildcards publishers actually write', function (): void {
    $policy = robotsFor("User-agent: *\nDisallow: /*/private\nDisallow: /tmp$\n");

    expect($policy->allows('https://news.test/section/private/story'))->toBeFalse()
        ->and($policy->allows('https://news.test/tmp'))->toBeFalse()
        // `$` anchors the end, so a longer path is not covered by that rule.
        ->and($policy->allows('https://news.test/tmpfiles'))->toBeTrue();
});

it('ignores comments and lines it cannot parse', function (): void {
    $policy = robotsFor("# a comment\nUser-agent: *\nDisallow: /private # trailing\nnonsense\n");

    // An unparseable line is absent, not a refusal.
    expect($policy->allows('https://news.test/private'))->toBeFalse()
        ->and($policy->allows('https://news.test/story'))->toBeTrue();
});

it('shares consecutive user-agent lines’ rules', function (): void {
    $policy = robotsFor("User-agent: somebot\nUser-agent: alimaprospectsbot\nDisallow: /shared\n");

    expect($policy->allows('https://news.test/shared'))->toBeFalse();
});

it('takes our token from the configured user agent', function (): void {
    // Derived rather than hard-coded, so a deployment that renames its crawler is not obeying
    // rules written for a name it no longer sends.
    config()->set('web-fetch.user_agent', 'SomeoneElseBot/2.0');
    app()->forgetInstance(RobotsPolicy::class);

    $policy = robotsFor("User-agent: someoneelsebot\nDisallow: /private\n");

    expect($policy->allows('https://news.test/private'))->toBeFalse();
});

it('reads a host’s robots.txt once', function (): void {
    $policy = robotsFor("User-agent: *\nDisallow: /private\n");

    $policy->allows('https://news.test/one');
    $policy->allows('https://news.test/two');
    $policy->allows('https://news.test/three');

    // A day's TTL: long enough that a busy news day is one `robots.txt` per publisher.
    expect(count(array_filter(
        $this->fetcher->fetched,
        static fn (string $url): bool => str_ends_with($url, '/robots.txt'),
    )))->toBe(1);
});

it('honours a real multi-line robots.txt through the real fetcher', function (): void {
    /*
     * **The test whose absence made this class a production no-op.**
     *
     * Every case above uses the double, which preserves newlines. The real
     * `HttpWebContentFetcher::fetch()` strips markup and collapses *every* run of whitespace to one
     * space, so a `robots.txt` read through it arrives as a single line, matches no user-agent
     * group and permits everything. The parser was right; the input never reached it.
     *
     * So this one goes through the real implementation against a faked HTTP layer, with the two
     * things that broke it: multiple lines, and a comment on the first one.
     */
    app()->forgetInstance(WebContentFetcher::class);
    app()->forgetInstance(RobotsPolicy::class);
    app()->bind(WebContentFetcher::class, HttpWebContentFetcher::class);

    Http::fake([
        'news.test/robots.txt' => Http::response(
            "# Our crawling policy\nUser-agent: *\nDisallow: /subscriber\nAllow: /subscriber/preview\n",
            200,
            ['Content-Type' => 'text/plain'],
        ),
    ]);

    $policy = app(RobotsPolicy::class);

    expect($policy->allows('https://news.test/subscriber/story'))->toBeFalse()
        ->and($policy->allows('https://news.test/subscriber/preview/story'))->toBeTrue()
        ->and($policy->allows('https://news.test/free/story'))->toBeTrue();
});

it('denies a host whose robots.txt it could not read, briefly', function (): void {
    // RFC 9309's answer to a server error is disallow-all, and the only way to honour it is to be
    // able to tell "there is no file" from "we could not ask" — which is why `fetchLines()` returns
    // null for one and an empty string for the other.
    $this->fetcher->cannotRead('https://news.test/robots.txt');
    app()->forgetInstance(RobotsPolicy::class);

    expect(app(RobotsPolicy::class)->allows('https://news.test/story'))->toBeFalse();
});

it('allows everything when the host answers that there is no robots.txt', function (): void {
    // A 404 is an answer, and it means no restriction. Treating it as a prohibition would stop the
    // product reading most of the web.
    expect(robotsFor('')->allows('https://news.test/story'))->toBeTrue();
});
