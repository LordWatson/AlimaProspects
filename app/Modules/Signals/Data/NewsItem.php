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
    ) {}
}
