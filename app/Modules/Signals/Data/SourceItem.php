<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Services\NewsSignalSource;
use App\Modules\Signals\Services\WebSearchSignalSource;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * One normalised, clamped item from any signal source (S13, ADR 0023), before AI extraction.
 *
 * **Renamed from `NewsItem`, because that had become a lie** — a planning decision found by
 * `/search`, a Companies House filing and an event a customer pushed in are all this DTO now. Free
 * text is length-clamped so a hostile payload can't bloat anything, the link is guaranteed http(s),
 * and `urlHash` is the canonicalised-URL sha1 used to dedupe an item across tracking parameters and
 * across runs. `publishedAt` is best-effort UTC (a provider's dates are often relative, e.g. "3 days
 * ago"); null when unparseable — the caller falls back to the run time.
 *
 * **Two identities since S11.** `urlHash` is still the truth about the link, but it stops being the
 * truth about the *article* behind a redirector whose token varies between requests — so
 * `dedupeHash` is the one the run compares and the unique index enforces, and `contentHash` is the
 * headline-and-outlet identity it falls back to. Both are nullable so that every existing
 * construction site stays valid; {@see self::dedupeKey()} is what callers ask, and it degrades to
 * `urlHash` rather than to nothing. See {@see SignalItemNormaliser}.
 *
 * **Two fields since S13**, and they are what make a source more than a second endpoint:
 *
 * - `kind` — which source produced it, carried onto `signals.source_kind` so the card can name it
 *   and the feed can filter by it. Defaults to `news`, which is what every pre-S13 item was.
 * - `matchedTemplate` — the query that found it, carried onto `signals.matched_template`. This
 *   closes the gap S12 recorded as its second deviation: the explanation panel could say which
 *   definition and which watch found a signal but not which of the definition's templates did,
 *   because no row stored it.
 * - `classification` — **null for anything that needs the AI**, and an {@see ExtractedSignal} for a
 *   source that already knows what its item is (ADR 0023 §6). A registry filing arrives structured,
 *   attributed and true; classifying it with a language model would spend money to make it less
 *   certain. Only a source *we* wrote against an authoritative provider may populate it — never one
 *   whose content came from free text, and never the webhook. S13a ships the field with every
 *   source returning null, so S13b adds a source rather than reshaping a run.
 */
final class SourceItem extends Data
{
    /**
     * @param  array<string, mixed>  $raw  the clamped source item (title, snippet, link, source, date)
     * @param  SignalSourceKind  $kind  Which source produced this (S13). {@see SignalSourceProvider}
     *                                  implementations set their own; {@see NewsSignalSource} and
     *                                  {@see WebSearchSignalSource} are the two that exist.
     * @param  string|null  $matchedTemplate  The query that found it, for the explanation panel.
     * @param  ExtractedSignal|null  $classification  Pre-classified, for a structured source only.
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
        public SignalSourceKind $kind = SignalSourceKind::News,
        public ?string $matchedTemplate = null,
        public ?ExtractedSignal $classification = null,
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

    /**
     * Whether this item arrived already classified, and so skips the AI batch (ADR 0023 §6).
     *
     * Asked of the item rather than of its `kind`, because the kind says a source *may* classify and
     * this says it *did*: a structured source that could not parse a particular filing must be able
     * to fall back to the prompt for that one item rather than assert a confidence it does not have.
     */
    public function isClassified(): bool
    {
        return $this->classification instanceof ExtractedSignal;
    }

    /**
     * The same item, attributed to the query that found it.
     *
     * Set by the run rather than by the provider: a provider is handed one query and has no reason
     * to hold the plan, and this keeps `fetch()` free of the bookkeeping. Returns a copy, because a
     * `Data` object shared between two queries that both matched must not have the second overwrite
     * the first.
     */
    public function foundBy(string $template): self
    {
        return new self(
            title: $this->title,
            snippet: $this->snippet,
            link: $this->link,
            urlHash: $this->urlHash,
            source: $this->source,
            publishedAt: $this->publishedAt,
            raw: $this->raw,
            contentHash: $this->contentHash,
            dedupeHash: $this->dedupeHash,
            kind: $this->kind,
            matchedTemplate: $template,
            classification: $this->classification,
        );
    }
}
