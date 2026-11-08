<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;

/**
 * What every paid enrichment vendor has in common (PL2), whatever it is being asked to find.
 *
 * The deliberate mirror of `LeadSourcing\Contracts\LeadSourceProvider` (ADR 0008) and
 * `Signals\Contracts\SignalSourceProvider` (ADR 0023): a class per vendor, registered in a
 * registry, and **never a branch inside the orchestrator**. Adding a vendor is a registration and
 * a config line; it is not an edit to the waterfall.
 *
 * Three obligations, and each exists because of a specific way this goes wrong:
 *
 * - **{@see self::key()}** is the vendor's stable config key, and what an attempt record is
 *   attributed to. A display name here would mean renaming a vendor rewrote its own history.
 * - **{@see self::describesCost()}** is how PL4 can say "about 2 credits" *before* the call, and
 *   how the margin guard knows what a miss costs. A provider that could not declare its price
 *   would have to be called to find out what calling it costs.
 * - **{@see self::isEnabled()}** is how a vendor with no key configured removes itself from the
 *   waterfall instead of failing inside it. {@see EnrichmentProviderRegistry} skips a disabled
 *   provider, so switching one off is a `.env` change rather than a deploy (ADR 0027 §3).
 */
interface EnrichmentProvider
{
    /**
     * The vendor's stable storage key, matching its entry in `config/enrichment.php`.
     */
    public function key(): string;

    /**
     * What this provider can be asked for.
     */
    public function capability(): ProviderCapability;

    /**
     * What one call costs us, in micros. A declaration, not a measurement — the authoritative
     * figure is what PL3 records on the attempt.
     */
    public function describesCost(): CostProfile;

    /**
     * Whether this provider is usable right now: configured, with credentials, not switched off.
     *
     * Deliberately **not** a health check. Whether the vendor is *up* is the circuit breaker's
     * question (ADR 0023), and the two must not be confused: a breaker that opened an hour ago
     * says nothing about whether we hold a key, and a missing key is not an outage to recover from.
     */
    public function isEnabled(): bool;
}
