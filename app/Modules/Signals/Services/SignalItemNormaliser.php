<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use Illuminate\Support\Str;

/**
 * Canonicalises a news URL so the same article dedupes across tracking parameters, and derives the
 * `signals.url_hash` from it. Canonical form: lower-cased scheme + host (leading `www.` dropped),
 * path with any trailing slash removed, and a query string with `utm_*` parameters stripped and the
 * rest sorted; the fragment is dropped. Only http(s) URLs canonicalise — anything else returns null
 * so it can never become a signal.
 */
final class SignalItemNormaliser
{
    /**
     * A hostname we are willing to treat as one: labels of letters, digits and inner hyphens,
     * with at least one dot. Deliberately strict, because everything downstream — the dedupe hash
     * and the ignore list — compares hosts as strings.
     */
    private const string HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';

    /**
     * The canonical form of a URL, or null if it isn't a usable http(s) URL.
     */
    public function canonicalUrl(string $url): ?string
    {
        // Strip tab/newline/CR anywhere in the string, not just at the ends. A browser removes
        // them before navigating, but `parse_url` substitutes an underscore — so
        // `https://bayut\t.com/x` parses as host `bayut_.com`, which is a different string from
        // the real destination. Left alone, that is an ignore-list bypass and an endless supply of
        // fresh `url_hash` values for one article (each costing a Serper query and an AI batch).
        $trimmed = trim(str_replace(["\t", "\n", "\r"], '', $url));

        if ($trimmed === '' || ! Str::startsWith(Str::lower($trimmed), ['http://', 'https://'])) {
            return null;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? 'https'));
        $host = $this->host((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $params);

            $params = array_filter(
                $params,
                static fn (int|string $key): bool => ! Str::startsWith(Str::lower((string) $key), 'utm_'),
                ARRAY_FILTER_USE_KEY,
            );

            if ($params !== []) {
                ksort($params);
                $query = '?'.http_build_query($params);
            }
        }

        return $scheme.'://'.$host.$path.$query;
    }

    /**
     * A URL's host, normalised to the form everything downstream compares: lower-cased, no
     * `www.`, no trailing dot. Null when it isn't a hostname we recognise.
     *
     * The trailing dot matters: `bayut.com.` is a valid absolute FQDN that resolves identically to
     * `bayut.com`, but is a different string — so without this, a source a team explicitly
     * excluded comes straight back.
     */
    public function host(string $host): ?string
    {
        $normalised = Str::replaceStart('www.', '', rtrim(Str::lower(trim($host)), '.'));

        return preg_match(self::HOST_PATTERN, $normalised) === 1 ? $normalised : null;
    }

    /**
     * sha1 of the canonical URL (40 hex chars, matching `signals.url_hash`), or null for an
     * unusable URL.
     */
    public function urlHash(string $url): ?string
    {
        $canonical = $this->canonicalUrl($url);

        return $canonical === null ? null : sha1($canonical);
    }
}
