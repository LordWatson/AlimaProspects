<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Data;

/**
 * One row of the live AI usage card: a prompt key with what it did over the window (Phase 20).
 *
 * A typed object rather than the `(object)` cast Pulse's own cards use on their query results.
 * Two reasons, and neither is tidiness: the card does arithmetic on `latency_ms` and `cost_micros`
 * (a mean over the provider calls, micro-units into major units), and a cast `stdClass` gives
 * `mixed` for both — so every read needs a cast at the point of use, which is where a wrong one
 * hides. And a DTO is what this codebase passes out of a service anyway (ADR 0002).
 *
 * Where the numbers come from, and why they are not the billing figure, is documented on
 * `App\Modules\AiPlatform\Observability\AiUsageCard` — named in prose rather than imported for an
 * `@see`, because a `use` statement here would make `Data/` depend on `Observability/` for a
 * docblock, and the dependency only ever runs the other way.
 */
final readonly class AiUsageRow
{
    public function __construct(
        /** The prompt key, e.g. `lead.summary`. */
        public string $key,
        /** Provider calls plus cache hits — everything the ledger recorded. */
        public int $calls,
        /** Of those, the ones served from the response cache: no provider call, no cost. */
        public int $cached,
        /** Of those, the ones that failed — a transport error or a schema-invalid response. */
        public int $failed,
        /** Spend in cost micro-units (integer minor-of-minor units, CLAUDE.md §2). */
        public int $costMicros,
        /** Mean provider latency, cache hits excluded because they record none. */
        public int $latencyMs,
    ) {}
}
