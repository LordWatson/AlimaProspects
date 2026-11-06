<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Enums\SignalSourceKind;

/**
 * Serper `/search` — the general web, for events that never reach a news desk (S13a, ADR 0023).
 *
 * **Why a second endpoint is worth having.** Three of the things teams most want to hear about are
 * not news: a planning decision appears in a council's register, an award in the organiser's press
 * release, a new office on the company's own blog. `/news` indexes publications; `/search` indexes
 * everything, and the S13a spike found it returns results where `/news` has none.
 *
 * **Four ways it differs from news, every one of them a handling decision.** The spike is recorded
 * in `docs/modules/signals.md`; the consequences are here.
 *
 * 1. **No outlet.** A `/search` result has `title`, `link`, `displayedLink`, `snippet`, `date` and
 *    `position` — and no `source`. So the publisher is the host ({@see self::outletFor()}), which is
 *    honest about what a web result is: a page on a site, not a story in a paper. A member sees
 *    `gulfnews.com` rather than "Gulf News", and the card labels the source kind so that reads as
 *    information rather than as a bug.
 * 2. **Its dates are scraped, not supplied.** Four to nine results in ten carry one, and one in the
 *    spike held the literal string "Exciting news". The sanity window in
 *    {@see AbstractSerperSignalSource::parseDate()} exists for this endpoint; an item with no
 *    readable date falls back to the run time, as it always has.
 * 3. **Its links are direct.** None of the redirector wrapping that makes `/news` need a content
 *    hash — so `dedupe_hash` here is the URL hash, which is exactly what the shared normaliser
 *    already does when a link is not a known redirector. Nothing to special-case; worth knowing
 *    when reading a run's identities.
 * 4. **Its noise is different, not merely greater.** The spike's dominant noise was social — and
 *    the global `signals.ignore_domains` list already drops Facebook, Instagram, LinkedIn, Reddit,
 *    X, YouTube, TikTok and Medium, so most of it never reaches a feed. What the global list misses
 *    is job aggregators and informational service pages ("how planning decisions are made", a
 *    portal's "register a project" form), which are not events and never will be. Those are in
 *    `signals.sources.web_search.ignore_domains` and apply to **this source only** — the global list
 *    is about publishers nobody wants, and this one is about a shape of page that only this endpoint
 *    returns.
 *
 * One thing it does **not** do differently: geography. `gl` biases `/search` and does not constrain
 * it — the spike found an Asheville council decision for a London query and a Los Angeles vacancy
 * for a Dubai one — so a run leans harder here on `signals.extract_event`'s geo-qualification and on
 * `SignalJurisdictionResolver`. That is the existing relevance floor doing its job, not a gap, but
 * it is the reason a team adding web search should expect to raise a threshold rather than lower one.
 */
final class WebSearchSignalSource extends AbstractSerperSignalSource
{
    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::WebSearch;
    }

    protected function endpoint(): string
    {
        return '/search';
    }

    protected function resultsKey(): string
    {
        return 'organic';
    }

    /**
     * The host, because `/search` does not say who published anything.
     *
     * Read from the **canonical link** rather than from `displayedLink`: the latter is a display
     * string Google composes ("gulfnews.com › business › property") and is neither a host nor
     * reliably parseable, while the link has already been canonicalised and had any userinfo
     * stripped by the time this is called. Falls back to null — never to the raw `displayedLink` —
     * because a blank outlet is a known, handled state ("we were not told who published this") and a
     * malformed one would be compared against the skip list as though it meant something.
     *
     * @param  array<array-key, mixed>  $result
     */
    protected function outletFor(array $result, string $link): ?string
    {
        return $this->ignored->hostOf($link);
    }
}
