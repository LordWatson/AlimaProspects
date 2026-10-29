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
     * The canonical form of a URL, or null if it isn't a usable http(s) URL.
     */
    public function canonicalUrl(string $url): ?string
    {
        $trimmed = trim($url);

        if ($trimmed === '' || ! Str::startsWith(Str::lower($trimmed), ['http://', 'https://'])) {
            return null;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? 'https'));
        $host = Str::replaceStart('www.', '', Str::lower((string) $parts['host']));
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
     * sha1 of the canonical URL (40 hex chars, matching `signals.url_hash`), or null for an
     * unusable URL.
     */
    public function urlHash(string $url): ?string
    {
        $canonical = $this->canonicalUrl($url);

        return $canonical === null ? null : sha1($canonical);
    }
}
