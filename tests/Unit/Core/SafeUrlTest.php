<?php

declare(strict_types=1);

use App\Modules\Core\Support\Security\SafeUrl;

/*
 * The one SSRF guard (Phase 20, extracted at S13b).
 *
 * **It is shared, so it is tested on its own.** `HttpWebContentFetcher` reaches it from a lead's
 * website field and `FeedSignalSource` from an address a customer typed, and the second of those is
 * fetched on a schedule with nobody watching. Both bugs the S13b review and its tests found here
 * were in the *shared* half: a `normalise()` that prepended `https://` to anything, and a question
 * about IPv4-mapped IPv6 that nobody had actually asked the runtime.
 */

it('refuses a scheme that is not http or https', function (): void {
    foreach ([
        'file:///etc/passwd',
        'gopher://example.com/',
        'ftp://example.com/feed.xml',
        'javascript:alert(1)',
        'data:text/plain,hello',
    ] as $url) {
        // **The bug this file exists for.** `normalise()` prepended `https://` to anything without
        // an http prefix, so `file:///etc/passwd` became `https://file:///etc/passwd` — scheme
        // https, host `file`, unresolvable, allowed straight through the guard. It was harmless
        // while only a lead's website field reached it and a live hole the moment a customer could
        // supply a feed address.
        expect(SafeUrl::normalise($url))->toBeNull($url)
            ->and(SafeUrl::allows($url))->toBeFalse($url);
    }
});

it('assumes https only for a string with no scheme at all', function (): void {
    // The case the prepending was for, and the only one it should ever have covered: a member
    // pasting an address should not have to know that a URL needs a scheme.
    expect(SafeUrl::normalise('example.com/feed.xml'))->toBe('https://example.com/feed.xml');
});

it('requires a host', function (): void {
    foreach (['https://', 'https:///path', 'http://'] as $url) {
        expect(SafeUrl::normalise($url))->toBeNull($url);
    }
});

it('refuses a port that is not 80 or 443', function (): void {
    expect(SafeUrl::allows('http://example.com:6379/'))->toBeFalse()
        ->and(SafeUrl::allows('https://example.com:9200/'))->toBeFalse()
        ->and(SafeUrl::allows('https://example.com:443/feed.xml'))->toBeTrue();
});

it('refuses an address off the public internet, including an IPv4-mapped IPv6 one', function (): void {
    foreach ([
        'http://127.0.0.1/',
        'http://[::1]/',
        'http://169.254.169.254/latest/meta-data/',   // the cloud metadata endpoint
        'http://10.0.0.1/',
        'http://172.16.0.1/',
        'http://192.168.1.1/',
        'http://[fd00::1]/',
        'http://[fe80::1]/',

        // **Asked of the runtime rather than assumed** — the S13b review's open question, because
        // IPv4-mapped IPv6 has historically been the gap in exactly this check. It is covered on
        // this PHP, and this is what will say so if an upgrade changes the answer.
        'http://[::ffff:127.0.0.1]/',
        'http://[::ffff:169.254.169.254]/',
        'http://[::ffff:10.0.0.1]/',

        // **Carrier-grade NAT (RFC 6598), which the PHP filter flags do not cover.** Found while
        // confirming the question above: on a host behind a carrier NAT the neighbour this reaches
        // is no more the public internet than a private range is.
        'http://100.64.0.1/',
        'http://100.127.255.254/',
    ] as $url) {
        expect(SafeUrl::allows($url))->toBeFalse($url);
    }
});

it('allows an ordinary public address', function (): void {
    expect(SafeUrl::allows('https://example.com/feed.xml'))->toBeTrue()
        ->and(SafeUrl::allows('http://8.8.8.8/'))->toBeTrue()
        ->and(SafeUrl::allows('http://[2001:4860:4860::8888]/'))->toBeTrue();
});

it('hands back the addresses it approved, so a caller can pin them', function (): void {
    // The answer to DNS rebinding (S13b review, finding 5): `assert()` resolves and approves, the
    // client resolves again on connect, and a one-second TTL is enough to make those two different
    // answers. A caller that pins what was approved has no second lookup to be lied to in.
    $ips = SafeUrl::approvedIps('http://8.8.8.8/');

    expect($ips)->toBe(['8.8.8.8'])
        ->and(SafeUrl::resolveOption('http://8.8.8.8/', $ips))->toBe('8.8.8.8:80:8.8.8.8')
        ->and(SafeUrl::resolveOption('https://8.8.8.8/', $ips))->toBe('8.8.8.8:443:8.8.8.8')
        // Nothing to pin is not an error: the caller passes it through and keeps the check it had.
        ->and(SafeUrl::resolveOption('https://8.8.8.8/', []))->toBeNull();
});

it('throws rather than approving an address it would refuse', function (): void {
    expect(fn () => SafeUrl::approvedIps('http://169.254.169.254/'))
        ->toThrow(RuntimeException::class);
});

it('resolves nothing for a host that cannot exist', function (): void {
    // `.invalid` is reserved by RFC 2606 precisely so that it never resolves anywhere. An empty
    // list rather than a throw, because what to do about it differs by caller: a lead's website
    // field should be attempted (a flaky lookup is not a verdict), and `FeedSignalSource` refuses,
    // because an address it cannot resolve is one it cannot pin.
    expect(SafeUrl::approvedIps('https://nothing-here.invalid/feed.xml'))->toBe([]);
});
