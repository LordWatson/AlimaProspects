<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use Illuminate\Support\Str;

/**
 * The identity of one news item: the canonical URL, and — since S11 — the **content** identity that
 * survives a redirector.
 *
 * Canonical form: lower-cased scheme + host (leading `www.` dropped), path with any trailing slash
 * removed, and a query string with `utm_*` parameters stripped and the rest sorted; the fragment is
 * dropped. Only http(s) URLs canonicalise — anything else returns null so it can never become a
 * signal.
 *
 * **Why a second identity exists (S11).** Serper sometimes returns
 * `https://google.com/goto?url=<opaque token>` with `source = "Construction Week Online"`. The
 * token is Google's own encoding, so the publisher URL cannot be recovered locally — there is
 * nothing to unwrap. That broke `url_hash` as an article's identity: if the token varies between
 * requests, the same article re-enters as a new signal on **every** run, costing a fresh Serper
 * query and a fresh AI batch each time, which is exactly the spend the hash exists to prevent
 * (`docs/modules/signals.md`, "Known limitations", consequence 1).
 *
 * So for a **known redirector host** the dedupe identity becomes {@see self::contentHash()} —
 * `sha1(normalised source name + normalised headline)` — and for everything else the canonical URL
 * hash stays primary, with the content hash stored beside it as data. One column
 * (`signals.dedupe_hash`) carries whichever applies and is what the unique index and the run's
 * "have we seen this?" check compare; `url_hash` is kept because it is still the truth about the
 * link.
 *
 * The redirector list is config (`signals.redirect_hosts`), not a constant: the next aggregator
 * that wraps its links should be a line in a file rather than a release.
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
     * Words carried by every headline and distinguishing none of them.
     *
     * Dropped from the content identity so "Acme raises $40m" and "Acme raises the $40m" are one
     * article rather than two. Kept deliberately short: a stop list long enough to be clever starts
     * discarding the words that tell two events apart.
     *
     * @var list<string>
     */
    private const array STOP_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'after', 'by', 'for', 'from', 'in', 'into',
        'is', 'its', 'of', 'on', 'or', 'the', 'to', 'with',
    ];

    /**
     * Separators an outlet's own name hangs off the end of a headline by.
     *
     * The pipe, the long dashes and the interpunct are taken as an outlet suffix wherever they
     * appear last, because a headline almost never contains one. A plain hyphen is **not** in this
     * list: "Acme - a Dubai firm - was sold" is one headline, so a hyphenated tail is only removed
     * when it is literally the source's name ({@see self::stripOutlet()}).
     *
     * @var list<string>
     */
    private const array OUTLET_SEPARATORS = ['|', '—', '–', '·', '»'];

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

    /**
     * Whether this URL's authority is a known redirector — a host that wraps somebody else's
     * article behind a token of its own.
     *
     * An entry may be a bare host (`news.google.com`) or a host and a path prefix
     * (`google.com/goto`). The path form is the one that matters for Google: `google.com` also
     * serves things that are not redirects, and treating the whole host as a redirector would hand
     * every result from it a headline-derived identity it does not need.
     *
     * Matching is host-or-parent on the host part, so a `www.` or a subdomain matches too.
     */
    public function isRedirector(string $url): bool
    {
        $canonical = $this->canonicalUrl($url);

        if ($canonical === null) {
            return false;
        }

        $host = (string) parse_url($canonical, PHP_URL_HOST);
        $path = (string) (parse_url($canonical, PHP_URL_PATH) ?? '');

        foreach ($this->redirectors() as $entry) {
            $entryHost = Str::before($entry, '/');
            $entryPath = Str::contains($entry, '/') ? '/'.trim(Str::after($entry, '/'), '/') : '';

            if ($host !== $entryHost && ! str_ends_with($host, '.'.$entryHost)) {
                continue;
            }

            if ($entryPath === '' || $path === $entryPath || Str::startsWith($path, $entryPath.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A headline reduced to the words that identify the story: lower-cased, outlet suffix removed,
     * punctuation flattened, stop words dropped, squished.
     *
     * Returns an empty string when nothing identifying is left — a headline of punctuation, or one
     * made entirely of stop words — and the caller treats that as "no content identity", which is
     * the right direction to fail in: an empty identity shared by two articles would merge them.
     */
    public function normalisedHeadline(string $headline, ?string $source = null): string
    {
        $value = $this->stripOutlet($headline, $source);

        // Unicode-aware: an Arabic headline must not reduce to an empty string because its letters
        // are not in `a-z`.
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($value));

        $split = preg_split('/\s+/u', trim($value));

        $words = array_values(array_filter(
            is_array($split) ? $split : [],
            static fn (string $word): bool => $word !== '' && ! in_array($word, self::STOP_WORDS, strict: true),
        ));

        return implode(' ', $words);
    }

    /**
     * The significant words of a headline, as a set — what {@see SignalClusterer} compares when it
     * asks whether two outlets are reporting the same event.
     *
     * Single characters are dropped as well as stop words: a stray initial is not what tells one
     * acquisition from another, and it inflates the overlap between unrelated headlines.
     *
     * @return list<string>
     */
    public function headlineTokens(string $headline, ?string $source = null): array
    {
        $words = $this->normalisedHeadline($headline, $source);

        if ($words === '') {
            return [];
        }

        $tokens = [];

        foreach (explode(' ', $words) as $word) {
            if (mb_strlen($word) < 2 || in_array($word, $tokens, strict: true)) {
                continue;
            }

            $tokens[] = $word;
        }

        return $tokens;
    }

    /**
     * `sha1(normalised source name + normalised headline)` — the article's identity when its link
     * is not one.
     *
     * The source is part of it on purpose. Two outlets reporting the same event are two articles,
     * each with its own link worth keeping; collapsing them here would lose the coverage that S11's
     * clustering is built to *show*. Grouping those two into one card is a different question with
     * a different answer ({@see SignalClusterer}).
     *
     * Null when the headline leaves nothing identifying behind.
     */
    public function contentHash(string $headline, ?string $source = null): ?string
    {
        $words = $this->normalisedHeadline($headline, $source);

        if ($words === '') {
            return null;
        }

        return sha1('source:'.$this->sourceKey($source).'|headline:'.$words);
    }

    /**
     * The identity this item dedupes on: the content hash behind a known redirector, and the
     * canonical URL hash everywhere else.
     *
     * Falls back to the URL hash when a redirected item has no usable headline — a worse identity
     * than the content hash, but a real one, and better than no row at all.
     */
    public function dedupeHash(string $url, string $headline, ?string $source = null): ?string
    {
        $urlHash = $this->urlHash($url);

        if ($urlHash === null) {
            return null;
        }

        if (! $this->isRedirector($url)) {
            return $urlHash;
        }

        return $this->contentHash($headline, $source) ?? $urlHash;
    }

    /**
     * An outlet's name as a comparable key — the same slug the ignore list compares a `source_name`
     * against, so "Gulf News" and "gulf news" are one outlet.
     */
    public function sourceKey(?string $source): string
    {
        return Str::slug(Str::squish((string) $source));
    }

    /**
     * A headline with the outlet's own name taken off the end.
     *
     * Two rules, because the separators differ in how safe they are. A pipe, a long dash or an
     * interpunct is read as an outlet suffix wherever it appears last. A hyphenated or colonned
     * tail is only removed when it **is** the source's name, because both are ordinary English
     * inside a headline.
     */
    private function stripOutlet(string $headline, ?string $source): string
    {
        $value = Str::squish($headline);

        foreach (self::OUTLET_SEPARATORS as $separator) {
            if (Str::contains($value, $separator)) {
                $head = Str::squish(Str::beforeLast($value, $separator));

                // Only when something is left: a headline that *starts* with a pipe keeps its words.
                if ($head !== '') {
                    $value = $head;
                }
            }
        }

        $sourceKey = $this->sourceKey($source);

        if ($sourceKey === '') {
            return $value;
        }

        foreach ([' - ', ' -- ', ': '] as $separator) {
            if (! Str::contains($value, $separator)) {
                continue;
            }

            $tail = Str::squish(Str::afterLast($value, $separator));

            if ($tail !== '' && Str::slug($tail) === $sourceKey) {
                $head = Str::squish(Str::beforeLast($value, $separator));

                if ($head !== '') {
                    $value = $head;
                }
            }
        }

        return $value;
    }

    /**
     * The configured redirector entries, normalised: lower-cased, `www.` dropped, blanks removed.
     *
     * @return list<string>
     */
    private function redirectors(): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = (array) config('signals.redirect_hosts', []);

        $entries = [];

        foreach ($configured as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $value = Str::replaceStart('www.', '', trim(Str::lower($entry), " \t\n\r/"));

            if ($value !== '' && ! in_array($value, $entries, strict: true)) {
                $entries[] = $value;
            }
        }

        return $entries;
    }
}
