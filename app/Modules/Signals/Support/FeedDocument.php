<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Signals\Services\FeedSignalSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * An RSS or Atom document, parsed into the handful of fields a signal needs (S13b).
 *
 * **Parsed with `libxml` and `SimpleXML`, with external entities refused.** This is XML from an
 * address a customer chose, so XXE is the risk that matters: a feed whose doctype declares
 * `<!ENTITY x SYSTEM "file:///etc/passwd">` would otherwise have the parser read a local file and
 * hand it back inside an entry's text — which would then be stored, shown, and sent to an AI prompt.
 * {@see self::parse()} therefore sets `LIBXML_NONET | LIBXML_NOENT` and rejects any document
 * carrying a doctype at all, before parsing. A feed has no legitimate reason to declare one.
 *
 * **It handles both formats because publishers have not agreed.** RSS 2.0 nests items under
 * `channel`, Atom puts `entry` at the root; RSS has `pubDate` and `description`, Atom has
 * `published`/`updated` and `summary`/`content`, and Atom's link is an attribute rather than a text
 * node. Normalising that here is why {@see FeedSignalSource} does not care which it fetched.
 */
final readonly class FeedDocument
{
    /**
     * @param  list<FeedEntry>  $entries
     */
    private function __construct(
        public string $title,
        public array $entries,
    ) {}

    /**
     * Parse a feed body, or null when it is not a feed we can read.
     *
     * Null rather than an exception, because "this is not a feed" is a thing a caller must report
     * to a member rather than a thing to crash on — and the caller turns it into a run-level
     * failure with the source named.
     */
    public static function parse(string $body): ?self
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        // **Before parsing.** A feed has no legitimate reason to declare a doctype, and refusing the
        // document outright is a stronger guarantee than trusting a parser flag to neutralise one.
        if (Str::contains(Str::lower(Str::limit($body, 2_000, '')), '<!doctype')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            // `LIBXML_NONET` forbids network access during parsing and `LIBXML_NOENT` keeps entity
            // references unsubstituted. Belt and braces over the doctype check above.
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT | LIBXML_NOCDATA);
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return null;
        }

        return self::fromXml($xml);
    }

    private static function fromXml(\SimpleXMLElement $xml): ?self
    {
        // RSS 2.0: <rss><channel><item>…  |  RSS 1.0 / Atom: items or entries at the root.
        $channel = $xml->channel ?? null;
        $isRss = $channel !== null && $channel->count() > 0;

        $container = $isRss ? $xml->channel : $xml;
        $nodes = $isRss ? $container->item : ($xml->entry->count() > 0 ? $xml->entry : $xml->item);

        if ($nodes === null || $nodes->count() === 0) {
            return null;
        }

        $title = self::text($container->title ?? null);

        $entries = [];

        foreach ($nodes as $node) {
            $entry = FeedEntry::fromNode($node);

            if ($entry instanceof FeedEntry) {
                $entries[] = $entry;
            }
        }

        return new self(
            title: $title === '' ? 'Feed' : Str::limit($title, 255, ''),
            entries: $entries,
        );
    }

    /** One element's text, trimmed, or the empty string. */
    public static function text(mixed $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) $node);
    }

    /**
     * A feed's date, in any of the shapes publishers use, or null.
     *
     * RSS gives RFC 2822 (`Mon, 03 Nov 2026 09:00:00 +0000`) and Atom gives RFC 3339
     * (`2026-11-03T09:00:00Z`); plenty of feeds give something that is neither. Unparseable is null
     * and the run falls back to its own time, exactly as it does for an unreadable Serper date.
     */
    public static function date(mixed $node): ?Carbon
    {
        $value = self::text($node);

        if ($value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }

        // The same sanity window the Serper sources use: a date outside it is nonsense rather than
        // news, and a feed is no more trustworthy about its own timestamps than a scraped page.
        if ($parsed->isAfter(now()->addDays(2)) || $parsed->isBefore(now()->subYears(25))) {
            return null;
        }

        return $parsed;
    }
}
