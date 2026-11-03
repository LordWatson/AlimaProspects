<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One entry of an RSS or Atom feed (S13b).
 *
 * Every field is **clamped on the way in**, for the same reason a Serper item is: this is text from
 * an address a customer chose, it is stored on `signals`, it is rendered on a card, and it reaches
 * an AI prompt. The lengths match the columns — `signals.headline` is `string(255)` and the snippet
 * mirrors the 1,000 characters the Serper sources allow — so nothing arrives that cannot be stored.
 *
 * The description is **stripped of markup**, which RSS descriptions are routinely full of: a feed's
 * `<description>` is often a whole HTML article, and neither a card nor a prompt wants tags in it.
 */
final readonly class FeedEntry
{
    private const int MAX_TITLE = 255;

    private const int MAX_SUMMARY = 1_000;

    /** `signals.source_url` is `string(2048)`; a longer link cannot be stored, so drop the entry. */
    private const int MAX_URL = 2048;

    private function __construct(
        public string $title,
        public string $summary,
        public string $link,
        public ?string $guid,
        public ?Carbon $publishedAt,
    ) {}

    /**
     * Read one `<item>` or `<entry>`, or null when it has no title or no usable link.
     */
    public static function fromNode(\SimpleXMLElement $node): ?self
    {
        $title = FeedDocument::text($node->title ?? null);
        $link = self::link($node);

        if ($title === '' || $link === '' || mb_strlen($link) > self::MAX_URL) {
            return null;
        }

        // RSS: `description`. Atom: `summary`, falling back to `content` — which is often the whole
        // article, hence the clamp after the strip rather than before.
        $summary = FeedDocument::text($node->description ?? null);

        if ($summary === '') {
            $summary = FeedDocument::text($node->summary ?? null);
        }

        if ($summary === '') {
            $summary = FeedDocument::text($node->content ?? null);
        }

        $guid = FeedDocument::text($node->guid ?? null);

        if ($guid === '') {
            $guid = FeedDocument::text($node->id ?? null);
        }

        return new self(
            title: Str::limit($title, self::MAX_TITLE, ''),
            summary: Str::limit(self::toText($summary), self::MAX_SUMMARY, ''),
            link: $link,
            guid: $guid === '' ? null : Str::limit($guid, 255, ''),
            // RSS `pubDate`, Atom `published`, then Atom `updated` — an entry that has only been
            // updated still has a date worth having.
            publishedAt: FeedDocument::date($node->pubDate ?? null)
                ?? FeedDocument::date($node->published ?? null)
                ?? FeedDocument::date($node->updated ?? null),
        );
    }

    /**
     * The entry's link, from whichever shape the format uses.
     *
     * RSS puts it in the element's text; Atom puts it in an `href` **attribute**, and a single entry
     * may carry several `<link>` elements distinguished by `rel` — the one worth having is
     * `rel="alternate"` or no `rel` at all, never `rel="enclosure"` (a media file) or
     * `rel="self"` (the feed itself).
     */
    private static function link(\SimpleXMLElement $node): string
    {
        $links = $node->link ?? null;

        if ($links === null) {
            return '';
        }

        $fallback = '';

        foreach ($links as $link) {
            $href = trim((string) ($link['href'] ?? ''));
            $rel = trim((string) ($link['rel'] ?? ''));

            // RSS: no attributes, the URL is the text.
            if ($href === '') {
                $text = trim((string) $link);

                if ($text !== '' && $fallback === '') {
                    $fallback = $text;
                }

                continue;
            }

            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }

            if ($fallback === '' && $rel !== 'enclosure' && $rel !== 'self') {
                $fallback = $href;
            }
        }

        return $fallback;
    }

    /**
     * Reduce a description to readable text.
     *
     * Scripts and styles are dropped **wholesale** rather than tag-stripped, so none of their
     * content survives into a stored snippet or an AI prompt — the same order of operations
     * `HttpWebContentFetcher::toText()` uses, and for the same reason: stripping `<script>` tags
     * while keeping what was between them leaves the payload and loses only the wrapper.
     */
    private static function toText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $stripped = strip_tags($stripped);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $stripped));
    }
}
