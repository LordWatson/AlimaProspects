<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Enums\PreviewVerdict;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * One article a preview looked at, with what would have happened to it and why (S9).
 *
 * Shown once and discarded — nothing here is persisted, because a definition nobody has saved has no
 * business putting rows in a team's feed. The {@see PreviewVerdict} is the point: an empty list is
 * not a diagnosis, and each verdict names a different thing to change.
 *
 * The subject fields are what the AI read out of the headline and snippet, not a lookup. They are
 * here so a member can see that the classification is about the right company before they trust the
 * type — "it found an event, but it thinks the buyer is the subject" is a judgement only a person
 * can make.
 */
final class PreviewedItem extends Data
{
    public function __construct(
        public readonly string $headline,
        public readonly string $url,
        public readonly ?string $source,
        public readonly ?Carbon $publishedAt,
        public readonly PreviewVerdict $verdict,
        public readonly ?int $confidence,
        public readonly ?int $relevance,
        public readonly ?string $summary,
        public readonly ?string $subjectCompanyName,
        public readonly ?string $subjectPersonName,
        public readonly ?string $location,
        /** The query that found it, so a member can see which template is pulling its weight. */
        public readonly string $query,
    ) {}

    /**
     * An item rejected before the AI ever saw it.
     *
     * Its scores are null rather than zero, and that distinction is worth keeping: zero would read
     * as "the AI judged this harshly", and nothing judged it at all.
     */
    public static function skipped(NewsItem $item, PreviewVerdict $verdict, string $query): self
    {
        return new self(
            headline: $item->title,
            url: $item->link,
            source: $item->source,
            publishedAt: $item->publishedAt,
            verdict: $verdict,
            confidence: null,
            relevance: null,
            summary: null,
            subjectCompanyName: null,
            subjectPersonName: null,
            location: null,
            query: $query,
        );
    }

    /**
     * An item the AI classified, with the verdict the thresholds and geography produced.
     */
    public static function classified(
        NewsItem $item,
        ExtractedSignal $event,
        PreviewVerdict $verdict,
        string $query,
    ): self {
        return new self(
            headline: $item->title,
            url: $item->link,
            source: $item->source,
            publishedAt: $item->publishedAt,
            verdict: $verdict,
            confidence: $event->confidence,
            relevance: $event->relevance,
            summary: $event->summary,
            subjectCompanyName: $event->subjectCompanyName,
            subjectPersonName: $event->subjectPersonName,
            location: $event->location,
            query: $query,
        );
    }
}
