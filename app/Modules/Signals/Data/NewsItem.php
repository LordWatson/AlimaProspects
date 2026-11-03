<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Services\SerperNewsClient;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * One normalised, clamped Serper `/news` item as {@see SerperNewsClient} returns it, before AI
 * extraction (S3). Free text is length-clamped so a hostile payload can't bloat anything, the link
 * is guaranteed http(s), and `urlHash` is the canonicalised-URL sha1 used to dedupe an article
 * across tracking parameters and across runs. `publishedAt` is best-effort UTC (Serper dates are
 * often relative, e.g. "3 days ago"); null when unparseable — the caller falls back to the run time.
 *
 * **Two identities since S11.** `urlHash` is still the truth about the link, but it stops being the
 * truth about the *article* behind a redirector whose token varies between requests — so
 * `dedupeHash` is the one the run compares and the unique index enforces, and `contentHash` is the
 * headline-and-outlet identity it falls back to. Both are nullable so that every existing
 * construction site stays valid; {@see self::dedupeKey()} is what callers ask, and it degrades to
 * `urlHash` rather than to nothing. See {@see SignalItemNormaliser}.
 */
final class NewsItem extends Data
{
    /**
     * @param  array<string, mixed>  $raw  the clamped source item (title, snippet, link, source, date)
     */
    public function __construct(
        public string $title,
        public string $snippet,
        public string $link,
        public string $urlHash,
        public ?string $source = null,
        public ?Carbon $publishedAt = null,
        public array $raw = [],
        public ?string $contentHash = null,
        public ?string $dedupeHash = null,
    ) {}

    /**
     * The identity this item is de-duplicated by, in this run and across every earlier one.
     *
     * Falls back to `urlHash` when nothing computed a dedupe hash — an older fixture, or a caller
     * that built the DTO by hand — so the behaviour without one is exactly v1's.
     */
    public function dedupeKey(): string
    {
        return $this->dedupeHash ?? $this->urlHash;
    }
}
