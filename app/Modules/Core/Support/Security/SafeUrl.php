<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Security;

use App\Modules\Core\Services\HttpWebContentFetcher;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one SSRF rule: may this application fetch that URL? (Phase 20's `Core\Support\Security`,
 * extracted at Signals S13b.)
 *
 * **Why it moved here.** It lived inside {@see HttpWebContentFetcher} as three private methods,
 * which was right while that class was the only thing making a server-side request to a
 * customer-supplied address. S13b adds a second: a team pastes an RSS feed URL and a queue worker
 * fetches it. A feed needs headers (`ETag`, `Last-Modified`) and a conditional GET, which the
 * fetcher's contract does not express — so the choice was a third method on a seam three modules
 * share, or one guard both callers obey. The guard is the part that must not have two versions: a
 * second copy that is 1% more permissive is an SSRF hole, and nobody would notice, because the
 * permissive copy passes all the same tests.
 *
 * What it refuses, and why each one matters when the address came from a customer:
 *
 * - **a non-http(s) scheme** — `file:///etc/passwd`, `gopher://`, `dict://`; the last two are
 *   classic ways to make an HTTP client speak to a service that is not HTTP;
 * - **a port that is not 80 or 443** — a URL is how you reach an internal Redis, Memcached or
 *   Elasticsearch from inside the network;
 * - **a host that resolves off the public internet** — loopback, private, link-local, reserved.
 *   `169.254.169.254` is the cloud metadata endpoint, and reaching it is how an SSRF becomes a
 *   credential leak.
 *
 * **An unresolvable host is allowed through**, deliberately: the request then simply fails to
 * connect, and refusing it would mean a flaky DNS lookup blocking a legitimate site. That is a
 * conscious trade and the reason {@see self::assert()} is not the only control — every caller also
 * re-validates each redirect, because the first hop being public says nothing about the second.
 */
final class SafeUrl
{
    /**
     * Normalise a user-supplied address, or null when it cannot be one.
     *
     * Assumes `https` when no scheme is given, because a member pasting `example.com/feed.xml` means
     * a web address and should not have to know that.
     */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        // **Does it already declare a scheme?** This test used to be `startsWith('http://',
        // 'https://')`, which meant anything else got `https://` *prepended* rather than refused —
        // so `file:///etc/passwd` became `https://file:///etc/passwd`, whose scheme is `https` and
        // whose host is `file`, and an unresolvable host is allowed through. A feed URL typed by a
        // customer walked straight past the guard (found by `FeedSignalSourceTest`).
        //
        // So a declared scheme is honoured and checked, and only a string with no scheme at all is
        // assumed to be `https` — which is the case this was for: a member pasting
        // `example.com/feed.xml` means a web address and should not have to know that.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) === 1) {
            if (! Str::startsWith(Str::lower($url), ['http://', 'https://'])) {
                return null;
            }
        } else {
            $url = 'https://'.$url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            return null;
        }

        // A host is not optional. `https://` alone, or `https:///path`, parses with no host at all
        // and would reach `assert()` as something to resolve.
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }

    /**
     * Throw unless this application may fetch the URL.
     *
     * @throws RuntimeException
     */
    public static function assert(string $url): void
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], strict: true) || ! is_string($host) || $host === '') {
            throw new RuntimeException('Disallowed URL scheme or host.');
        }

        // `parse_url` keeps the brackets on an IPv6 literal host (e.g. `[::1]`); strip them so the
        // address validates and cannot slip past the private-range check as an unresolvable "host".
        $host = trim($host, '[]');

        if ($port !== null && ! in_array((int) $port, [80, 443], strict: true)) {
            throw new RuntimeException('Disallowed port.');
        }

        foreach (self::resolveIps($host) as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new RuntimeException('URL resolves to a non-public address.');
            }
        }
    }

    /**
     * Whether this application may fetch the URL, as a question rather than an assertion.
     *
     * For a **validation** path — a member typing a feed URL into a form deserves "we can't reach
     * that address" beside the field, not an exception from a queue worker three hours later.
     */
    public static function allows(?string $url): bool
    {
        $normalised = self::normalise($url);

        if ($normalised === null) {
            return false;
        }

        try {
            self::assert($normalised);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Resolve a host to the IPs it points at. A host given as an IP literal resolves to itself. A
     * host that cannot be resolved yields no IPs — treated as safe to attempt (the request then just
     * fails on connect), so a flaky DNS lookup never blocks a legitimate site.
     *
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        // IPv6, when present. A host that is public over A and private over AAAA is a real trick.
        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True for any address outside the public internet (loopback, private, link-local, reserved).
     *
     * **IPv4-mapped IPv6 is covered**, which the S13b review asked to have confirmed rather than
     * assumed: `::ffff:127.0.0.1`, `::ffff:169.254.169.254` and `::ffff:10.0.0.1` are all rejected
     * by these two flags on this PHP, and `SafeUrlTest` asserts it so an upgrade cannot quietly
     * change the answer.
     *
     * **Carrier-grade NAT space is added by hand**, because the flags do not cover it:
     * `100.64.0.0/10` (RFC 6598) is shared address space, and on a host behind a carrier NAT the
     * neighbour it reaches is no more "the public internet" than a private range is. It is the one
     * range this had to add, found while confirming the mapped-IPv6 question.
     */
    private static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // Normalise a mapped address before the range test, so `::ffff:100.64.0.1` is read as the
        // IPv4 address it is. (The flags above already reject the mapped private and reserved ones;
        // this is only reached for an address they allowed.)
        $v4 = Str::startsWith(Str::lower($ip), '::ffff:')
            ? Str::after(Str::lower($ip), '::ffff:')
            : $ip;

        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($v4);

        // 100.64.0.0/10 — RFC 6598 shared address space.
        return $long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    }

    /**
     * The addresses this URL resolves to, having checked every one of them.
     *
     * For a caller that wants to **pin** what it checked. {@see self::assert()} resolves the host
     * and approves the answer, and the HTTP client then resolves it again when it connects — so a
     * record with a short TTL can answer publicly for the check and `169.254.169.254` for the
     * request. That is DNS rebinding, and re-checking immediately before the call narrows the
     * window without closing it. Handing the approved addresses to the client and telling it to use
     * those closes it.
     *
     * Returns an **empty list for a host that does not resolve**. `assert()` treats that as safe to
     * attempt (a flaky lookup should not block a legitimate site, and the request simply fails on
     * connect), which is right for a website field a member typed. A caller that is pinning has to
     * decide for itself: `FeedSignalSource` refuses, because an address it cannot resolve is one it
     * cannot pin, and a feed is the one address in this product that a customer supplies and we
     * then fetch on a schedule with nobody watching.
     *
     * @return list<string>
     *
     * @throws RuntimeException if the URL is not one we may fetch at all
     */
    public static function approvedIps(string $url): array
    {
        self::assert($url);

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Disallowed URL scheme or host.');
        }

        return self::resolveIps(trim($host, '[]'));
    }

    /**
     * The `CURLOPT_RESOLVE` entry that pins this URL's host to the addresses already approved.
     *
     * One entry, with the approved addresses comma-separated, which is curl's own syntax for it.
     * Returns null when there is nothing to pin — no addresses, or no curl — so a caller can pass it
     * straight through and a stream-handler environment simply keeps the pre-request check it
     * always had.
     *
     * @param  list<string>  $ips
     */
    public static function resolveOption(string $url, array $ips): ?string
    {
        if ($ips === [] || ! extension_loaded('curl')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port ??= $scheme === 'http' ? 80 : 443;

        return trim($host, '[]').':'.((int) $port).':'.implode(',', $ips);
    }
}
