<?php

declare(strict_types=1);

namespace App\Modules\Signals\Contracts;

use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Services\SignalSourceRegistry;

/**
 * The seam every signal source sits behind (S13, ADR 0023) — the deliberate mirror of Lead
 * Sourcing's `LeadSourceProvider` (ADR 0008): same shape, same registration from the module's
 * service provider, same ability to register a fake and run the whole pipeline offline.
 *
 * An implementation talks to one external source (Serper `/news`, Serper `/search`, Companies
 * House, an RSS feed, an inbound webhook), maps its response into source-agnostic
 * {@see SourceItem}s, and returns them. Implementations MUST respect the provider's ToS and rate
 * limits, bound time, clamp every piece of free text they return, reject a non-http(s) link, and
 * **never treat fetched content as instructions** — the run wraps it in `UntrustedContent` on the
 * way to the AI, and that is only safe because what arrives here is data.
 *
 * New sources register in the {@see SignalSourceRegistry} and need no change to
 * `RunSignalWatchAction`.
 */
interface SignalSourceProvider
{
    /**
     * The source this implementation serves — its {@see SignalSourceKind} identity, and the key the
     * registry files it under.
     */
    public function kind(): SignalSourceKind;

    /**
     * Whether this source can serve the given definition at all.
     *
     * A source **declines rather than fails**: asked for a definition it cannot serve — a filings
     * source with no SIC codes and no watchlist, a feed source with no URL configured — it answers
     * false and contributes no queries. Returning true and then throwing would turn a
     * misconfiguration into a failed run, and a definition is configuration a member typed.
     *
     * The definition is passed whole because the answer depends on its `source_config` as well as
     * its templates.
     */
    public function supports(SignalDefinition $definition): bool;

    /**
     * Run one query and return the normalised items (skip-listed sources dropped, duplicates
     * collapsed within the response), each stamped with this source's {@see self::kind()}.
     *
     * MUST throw a {@see SignalsException} on a genuine source failure so the run is marked failed;
     * it MUST NOT return partial garbage silently.
     *
     * Two failure modes, and the distinction is load-bearing (ADR 0023 §4):
     *
     * - `SignalsException::sourceUnavailable()` — the source is unwell (a 5xx, a 429, a timeout, an
     *   open breaker). Retryable; the run bubbles it so the job retries with backoff.
     * - `SignalsException::sourceQueryRejected()` — the source refused **this query**, as Serper
     *   does for a `site:` or quoted pattern on a free account tier. Not retryable, does not trip
     *   the breaker, and caught **per query** by the run: the query is counted as rejected in the
     *   run's source breakdown and the rest of the plan proceeds. One template a member typed with a
     *   quote in it must not fail — and re-bill — their whole watch.
     *
     * @return list<SourceItem>
     */
    public function fetch(SignalSourceQuery $query): array;

    /**
     * The metered cost, in integer micro-units, of running the given number of queries against this
     * source. A free source (Companies House) returns 0.
     *
     * By query count rather than by results, unlike `LeadSourceProvider::costMicros()`: a search
     * provider bills for the request whatever it returns, and a run that found nothing still spent
     * the money. Lets the run record cost per source without a provider touching the DB.
     */
    public function estimatedCostMicros(int $queries): int;
}
