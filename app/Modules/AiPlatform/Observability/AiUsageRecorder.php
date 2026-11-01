<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Observability;

use App\Modules\AiPlatform\Enums\AiPulseMetric;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Events\AiRequestRecorded;
use Laravel\Pulse\Facades\Pulse;

/**
 * Records AI spend into Pulse (Phase 20, ADR 0017).
 *
 * A Pulse **recorder**, registered in `config/pulse.php` like the framework's own, listening to
 * {@see AiRequestRecorded} — so AI Platform raises an event and never learns that a metrics
 * dashboard exists.
 *
 * **Why this and the nightly rollup both.** Admin's platform reporting (Phase 19c) answers "what
 * did this customer cost us last month" from the `ai_requests` ledger, and that is the
 * authoritative number. This answers a different question — "is something happening *right now*" —
 * over a seven-day rolling window, per minute, with no team in it. When a prompt starts failing or
 * a provider gets slow at 10pm, the ledger will tell you tomorrow and this tells you now. Neither
 * replaces the other, and the live one is explicitly **not** the figure anybody bills from: Pulse
 * samples and trims, and `PULSE_ENABLED=false` makes it disappear entirely.
 *
 * **Keyed by prompt key, not by team.** Two reasons, and the second is the real one: the useful
 * question here is which *prompt* is burning money or breaking, and a per-team key on a dashboard
 * that is not tenant-scoped would put a customer's spend on a screen whose whole purpose is the
 * aggregate. Per-team cost has a home already, behind `AdminAbility::ReadPlatformReports`.
 */
final class AiUsageRecorder
{
    /**
     * The event this recorder listens to. Pulse reads this property and wires the listener itself.
     *
     * @var list<class-string>
     */
    public array $listen = [
        AiRequestRecorded::class,
    ];

    public function record(AiRequestRecorded $event): void
    {
        $key = $event->promptKey;

        // Five entries rather than one with five aggregates, because Pulse aggregates a single
        // value per type: calls, the cache hits and failures among them, the spend, and the time.
        // The type names and the `sum` constraint are {@see AiPulseMetric}, shared with the card
        // that reads them back.
        Pulse::record(AiPulseMetric::Requests->value, $key, 1)->sum();

        if ($event->status === AiRequestStatus::Failed) {
            Pulse::record(AiPulseMetric::Failed->value, $key, 1)->sum();
        }

        // A cache hit costs nothing and takes a millisecond. Counting it as a provider call would
        // flatter both the spend and the latency, and the question this card answers is what the
        // provider is doing — so it is counted, and then nothing else about it is.
        if ($event->cacheHit) {
            Pulse::record(AiPulseMetric::Cached->value, $key, 1)->sum();

            return;
        }

        Pulse::record(AiPulseMetric::CostMicros->value, $key, $event->costMicros)->sum();
        // Total milliseconds, not an average: the card divides by the number of provider calls it
        // has in the same row, which is the only way to get a mean that is weighted correctly
        // across Pulse's buckets.
        Pulse::record(AiPulseMetric::LatencyMs->value, $key, $event->latencyMs)->sum();
    }
}
