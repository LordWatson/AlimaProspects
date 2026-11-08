<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use Spatie\LaravelData\Data;

/**
 * What one call to a provider costs us (PL2), in integer **micros** of the billing currency.
 *
 * Micros, not the plan's "millicredits and pence": every monetary column in this codebase is micros
 * (`ai_requests.cost_micros`, `leads.enrichment_cost_micros`, `signal_definition_spend.*`) and a
 * second unit in the same ledger is a billing bug waiting to happen — ADR 0026 §3 settled it.
 *
 * **Two figures, because vendors charge both ways and the difference is the whole of the margin
 * guard.** A finder that bills only for a hit is cheap to try and cheap to fail; a verifier that
 * bills per lookup charges for every "no". A profile with `perAttemptMicros` set is a provider
 * whose misses have to be counted, and PL4's margin figures are wrong without it.
 *
 * It is a provider's **declaration**, not a measurement: the authoritative number is what PL3
 * records on each attempt. This is what PL4 estimates with before the call ("reveal — about 2
 * credits") and what a cost ceiling is checked against.
 */
final class CostProfile extends Data
{
    public function __construct(
        /** Charged when the provider returns an answer. */
        public int $perSuccessMicros = 0,
        /** Charged whether or not it does. Several verifiers bill this way. */
        public int $perAttemptMicros = 0,
    ) {}

    /**
     * A provider that costs us nothing — our own free-tier checks, and every `Fake`.
     */
    public static function free(): self
    {
        return new self;
    }

    /**
     * The worst this call can cost: both figures, because a successful call to a bills-per-attempt
     * provider pays both. An estimate shown to a member has to be the ceiling, or the estimate is
     * the thing they will quote back when the bill is larger.
     */
    public function worstCaseMicros(): int
    {
        return $this->perAttemptMicros + $this->perSuccessMicros;
    }

    /**
     * What this call actually cost, given whether it found anything.
     */
    public function forOutcome(bool $found): int
    {
        return $this->perAttemptMicros + ($found ? $this->perSuccessMicros : 0);
    }
}
