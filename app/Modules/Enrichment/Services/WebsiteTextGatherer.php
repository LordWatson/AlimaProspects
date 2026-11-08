<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Contracts\WebContentFetcher;
use Illuminate\Support\Str;

/**
 * Gathers readable text for a lead's website from its homepage **and** the conventional pages where
 * contact details actually live — `/contact`, `/about`, `/team` and the like (Q: find the contacts
 * that sit off the homepage).
 *
 * The homepage alone rarely states an email; a contact or about page usually does. Fetching is free
 * (it is the AI extraction that costs), so this reads up to `enrichment.max_pages_per_lead` pages and
 * returns their text concatenated, clamped to `enrichment.max_combined_chars`, for **one** extraction
 * call over the lot. The homepage is always first and always counts as one of the pages; the
 * remaining budget is spent on the configured paths, in order, skipping any that return nothing (a
 * 404 or a non-HTML response fetches as an empty string). Paths are resolved against the site's
 * origin, so a homepage of `https://acme.co.uk/en` still probes `https://acme.co.uk/contact`.
 *
 * Every fetch goes through the shared {@see WebContentFetcher}, so the SSRF guards, the byte cap and
 * the markup stripping are exactly those Enrichment already relied on — this class adds no network
 * behaviour of its own, only which URLs to ask for and how to join the results.
 */
final class WebsiteTextGatherer
{
    public function __construct(private readonly WebContentFetcher $fetcher) {}

    /**
     * Readable text for the site at `$website`, homepage first then contact-ish pages, or '' when the
     * homepage itself yields nothing (an unreachable or non-company URL) — in which case no further
     * page is tried, because there is no site to read.
     */
    public function gather(?string $website): string
    {
        $website = trim((string) $website);

        if ($website === '') {
            return '';
        }

        $maxPages = max(1, (int) config('enrichment.max_pages_per_lead', 4));
        $maxChars = max(1, (int) config('enrichment.max_combined_chars', 18_000));

        $home = $this->fetcher->fetch($website);

        if (trim($home) === '') {
            return ''; // no readable homepage → treat the site as absent, don't probe sub-pages
        }

        $collected = [$home];
        $length = Str::length($home);

        $origin = $this->origin($website);

        if ($origin !== null && $maxPages > 1) {
            /** @var list<string> $paths */
            $paths = (array) config('enrichment.contact_paths', []);

            foreach ($paths as $path) {
                if (count($collected) >= $maxPages || $length >= $maxChars) {
                    break;
                }

                $text = trim($this->fetcher->fetch($origin.'/'.ltrim((string) $path, '/')));

                if ($text === '') {
                    continue;
                }

                $collected[] = $text;
                $length += Str::length($text) + 2; // +2 for the join below
            }
        }

        return Str::limit(implode("\n\n", $collected), $maxChars, '');
    }

    /**
     * The scheme+host origin of a URL (e.g. `https://acme.co.uk`), or null if it can't be parsed.
     */
    private function origin(string $url): ?string
    {
        if (! Str::startsWith(Str::lower($url), ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        return "{$scheme}://{$host}";
    }
}
