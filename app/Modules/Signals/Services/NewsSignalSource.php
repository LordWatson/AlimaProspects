<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Enums\SignalSourceKind;

/**
 * Serper `/news` (ADR 0010/0011) — the original signal source, and the one every definition that
 * existed before S13 runs on.
 *
 * **Renamed from `SerperNewsClient` at S13, with its behaviour unchanged** (ADR 0023). It used to be
 * the module's only external client, behind a `NewsSearchClient` contract of its own; it is now one
 * {@see SignalSourceProvider} among several, and everything it used to do itself — the timeouts, the
 * retry policy, the breaker, the clamping, the canonical-URL identities, the skip list, the
 * within-response de-duplication — moved up into {@see AbstractSerperSignalSource} and
 * {@see AbstractHttpSignalSourceProvider} so a second Serper endpoint could not drift from it.
 *
 * What is left is the three things actually specific to the news endpoint: its path, its results
 * key, and the fact that it tells us who published each item.
 */
final class NewsSignalSource extends AbstractSerperSignalSource
{
    public function kind(): SignalSourceKind
    {
        return SignalSourceKind::News;
    }

    protected function endpoint(): string
    {
        return '/news';
    }

    protected function resultsKey(): string
    {
        return 'news';
    }

    /**
     * `/news` names the outlet, which is the one thing it gives that `/search` does not.
     *
     * It is a human name ("Gulf News", "Khaleej Times"), and that matters twice over: it is what a
     * card shows a member, and since S11 it is what the skip list can match on when Serper returns a
     * `google.com` redirect in place of the publisher's own link — behind which a host-only list
     * cannot express "not this publisher" at all.
     *
     * @param  array<array-key, mixed>  $result
     */
    protected function outletFor(array $result, string $link): ?string
    {
        $source = is_string($result['source'] ?? null) ? trim($result['source']) : '';

        return $source === '' ? null : $source;
    }
}
