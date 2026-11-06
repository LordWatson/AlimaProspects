<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Contracts\WebContentFetcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * May we fetch this page? (`robots.txt`, Signals S12.)
 *
 * `docs/compliance.md` §5 has required us to respect `robots.txt` since the beginning and nothing
 * implemented it, because until now the only pages the product fetched were a lead's **own**
 * website — where the team has a business relationship and the file rarely says otherwise. Reading
 * a **news publisher's** article is different in kind: publishers use `robots.txt` deliberately and
 * at length, and ignoring it is the sort of thing that gets an IP range blocked and a paragraph
 * written about you.
 *
 * It lives in `Core\Support` beside {@see CircuitBreaker} because it is nobody's domain rule — but
 * it is **not** wired into {@see WebContentFetcher} itself, and that restraint is deliberate:
 * retro-fitting a new refusal into the seam Enrichment and Business Intelligence already depend on
 * would change what two shipped features fetch, silently, for a reason neither asked for. Signals
 * consults it explicitly. Extending it to the other callers is a decision with its own
 * consequences and belongs to whoever takes it.
 *
 * Three properties worth knowing:
 *
 * - **It fetches through the same SSRF-safe seam** rather than opening its own HTTP client, so a
 *   `robots.txt` cannot be used to reach something the fetcher would refuse — through
 *   `fetchLines()` rather than `fetch()`, and that distinction is not fussiness: `fetch()` collapses
 *   every run of whitespace, so a `robots.txt` read through it arrives as **one line** and parses
 *   as no rules at all. This class was a production no-op on its first day, and the test could not
 *   see it because the double preserved newlines (found by `laravel-module-reviewer`).
 * - **A missing file fails open, an unreadable one fails closed.** A 404 means no restriction —
 *   that is what the standard says, and treating it as a prohibition would stop the product reading
 *   most of the web. A timeout or a 5xx is **not an answer**, and RFC 9309 says to treat it as
 *   disallow-all; it is cached for minutes rather than for the day, so a publisher having a bad
 *   hour does not cost us a day's reading. A URL whose host cannot be parsed is refused outright,
 *   because a destination we cannot name is not one we can check.
 * - **One fetch per host per TTL.** A day by default: long enough that a busy news day is one
 *   `robots.txt` per publisher, short enough that a publisher who changes their mind is honoured
 *   within one.
 *
 * The parser implements the parts of the standard that are actually used: `User-agent` groups, the
 * most specific matching group, `Disallow` and `Allow`, and longest-match-wins between them. It does
 * not implement `Crawl-delay` (we fetch a handful of pages a day), and it treats an unparseable
 * line as absent rather than as a refusal.
 */
final class RobotsPolicy
{
    /** The product's own robots token, if the configured user agent yields nothing usable. */
    private const string FALLBACK_AGENT = 'alimaprospectsbot';

    public function __construct(
        private readonly WebContentFetcher $fetcher,
        private readonly Cache $cache,
    ) {}

    /**
     * Whether `robots.txt` permits us to fetch this URL.
     *
     * False for a URL we cannot read, and true when the host publishes no rules about us.
     */
    public function allows(?string $url): bool
    {
        $parts = $this->parts($url);

        if ($parts === null) {
            return false;
        }

        [$origin, $path] = $parts;

        $rules = $this->rulesFor($origin);

        if ($rules === []) {
            return true;
        }

        // Longest match wins, and an `Allow` beats a `Disallow` of the same length — which is the
        // rule that lets a publisher disallow a section and carve one path back out of it.
        $verdict = true;
        $best = -1;

        foreach ($rules as [$allow, $pattern]) {
            if (! $this->matches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);

            if ($length > $best || ($length === $best && $allow)) {
                $best = $length;
                $verdict = $allow;
            }
        }

        return $verdict;
    }

    /**
     * The rules that apply to **us**, as `[allow, path]` pairs, cached per origin.
     *
     * An empty list means "nothing says otherwise", which is the overwhelmingly common case and
     * the one worth being cheap.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function rulesFor(string $origin): array
    {
        $key = 'robots:'.sha1($origin);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            /** @var list<array{0: bool, 1: string}> $cached */
            return $cached;
        }

        $body = $this->fetcher->fetchLines($origin.'/robots.txt');

        if ($body === null) {
            // Could not read it, which is not the same as there being nothing to read. Deny
            // everything under this host — RFC 9309's answer to a server error — but for minutes
            // rather than the day, so a publisher having a bad hour does not cost a day's reading
            // once they recover. `remember()` could not express this: the two outcomes need
            // different lifetimes.
            $deny = [[false, '/']];

            $this->cache->put($key, $deny, $this->errorTtl());

            return $deny;
        }

        $rules = $this->parse($body);

        $this->cache->put($key, $rules, $this->ttl());

        return $rules;
    }

    /** How long a successfully read `robots.txt` is trusted for. */
    private function ttl(): int
    {
        return max(60, (int) config('signals.article_fetch.robots_ttl', 86_400));
    }

    /** How long a host that could not be read stays denied. */
    private function errorTtl(): int
    {
        return max(60, (int) config('signals.article_fetch.robots_error_ttl', 300));
    }

    /**
     * Pull the rules for the most specific `User-agent` group that applies to us.
     *
     * Our own token first; `*` only when there is no group naming us — which is what the standard
     * says and the opposite of merging the two. A publisher who writes a permissive group for us
     * and a restrictive one for everybody means the permissive one.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function parse(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        /** @var array<string, list<array{0: bool, 1: string}>> $groups */
        $groups = [];
        /** @var list<string> $agents */
        $agents = [];
        $readingAgents = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(Str::before($line, '#'));

            if ($line === '' || ! Str::contains($line, ':')) {
                continue;
            }

            $field = Str::lower(trim(Str::before($line, ':')));
            $value = trim(Str::after($line, ':'));

            if ($field === 'user-agent') {
                // A blank line is not what separates groups in practice; a `User-agent` after a
                // rule is. Consecutive agent lines share the rules that follow them.
                if (! $readingAgents) {
                    $agents = [];
                }

                $agents[] = Str::lower($value);
                $readingAgents = true;

                continue;
            }

            $readingAgents = false;

            if (! in_array($field, ['disallow', 'allow'], strict: true) || $agents === []) {
                continue;
            }

            foreach ($agents as $agent) {
                // An empty `Disallow` means "nothing is disallowed" — a permission, not a rule
                // about the empty path, and a parser that stored it as a rule would match every
                // URL with a zero-length pattern.
                if ($field === 'disallow' && $value === '') {
                    continue;
                }

                $groups[$agent][] = [$field === 'allow', $value];
            }
        }

        $us = $this->agentToken();

        foreach ([$us, '*'] as $candidate) {
            if (isset($groups[$candidate])) {
                return $groups[$candidate];
            }
        }

        return [];
    }

    /**
     * Whether a `robots.txt` path pattern matches this path.
     *
     * Prefix matching, with the two wildcards every major crawler implements — and which
     * publishers therefore write — honoured: `*` for any run of characters and a trailing `$` to
     * anchor the end. Everything else in the pattern is literal.
     */
    private function matches(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (! Str::contains($pattern, ['*', '$'])) {
            return Str::startsWith($path, $pattern);
        }

        $anchored = Str::endsWith($pattern, '$');
        $body = $anchored ? Str::beforeLast($pattern, '$') : $pattern;

        // The pattern is the publisher's, so it is quoted before anything in it reaches a regex;
        // only the wildcard is then put back into its meaning.
        $regex = str_replace('\*', '.*', preg_quote($body, '/'));

        return preg_match('/^'.$regex.($anchored ? '$' : '').'/', $path) === 1;
    }

    /**
     * Our robots token: the product name out of the configured user agent, lower-cased.
     *
     * Derived rather than hard-coded so that changing `WEB_FETCH_USER_AGENT` changes which group a
     * publisher's file has to name — otherwise a deployment that renamed its crawler would be
     * obeying rules written for a name it no longer sends.
     */
    private function agentToken(): string
    {
        $agent = Str::lower((string) config('web-fetch.user_agent'));
        $token = Str::lower(Str::before(Str::before($agent, '/'), ' '));

        return $token === '' ? self::FALLBACK_AGENT : $token;
    }

    /**
     * `[origin, path]` for a usable http(s) URL, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parts(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parsed = parse_url(trim($url));

        if (! is_array($parsed) || ! isset($parsed['host'], $parsed['scheme'])) {
            return null;
        }

        $scheme = Str::lower((string) $parsed['scheme']);

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            return null;
        }

        $origin = $scheme.'://'.Str::lower((string) $parsed['host']);

        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        $path = (string) ($parsed['path'] ?? '/');

        return [$origin, $path === '' ? '/' : $path];
    }
}
