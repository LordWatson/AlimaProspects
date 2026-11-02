<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Observability;

use App\Modules\AiPlatform\Data\AiUsageRow;
use App\Modules\AiPlatform\Enums\AiPulseMetric;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Laravel\Pulse\Facades\Pulse;

/**
 * Reads back what {@see AiUsageRecorder} wrote: one {@see AiUsageRow} per prompt key over a period
 * (Phase 20, ADR 0017).
 *
 * Separated from {@see AiUsageCard} in the review round, for the reason that usually justifies
 * pulling a query out of a view: the arithmetic here is **wrong quietly**. The mean latency divides
 * by the provider calls rather than by all of them, the cost is micro-units, and a renamed Pulse
 * type does not error — the column comes back null and a figure on an operator's dashboard becomes
 * zero. All three want a test of their own, and a Livewire component is an awkward place to put
 * one. The card is now only a renderer.
 *
 * **Not the billing figure.** Pulse samples, trims after seven days, and disappears with
 * `PULSE_ENABLED=false`. The authoritative per-team cost is the `ai_requests` ledger, read by
 * Admin's platform reporting behind `AdminAbility::ReadPlatformReports`.
 */
final readonly class AiUsageSummary
{
    /**
     * One row per prompt key, ordered by call volume.
     *
     * `aggregateTypes` rather than one query per metric: it returns one row per key with a column
     * per type, which is what makes a prompt with calls but no cost — every one served from the
     * response cache — appear as a row rather than vanish from the table.
     *
     * @return Collection<int, AiUsageRow>
     */
    public function forPeriod(CarbonInterval $interval): Collection
    {
        /** @var Collection<int, object> $rows */
        $rows = Pulse::aggregateTypes(
            AiPulseMetric::values(),
            'sum',
            $interval,
            orderBy: AiPulseMetric::Requests->value,
        );

        return $rows->map(static function (object $row): AiUsageRow {
            $value = static fn (AiPulseMetric $metric): int => (int) ($row->{$metric->value} ?? 0);

            $calls = $value(AiPulseMetric::Requests);
            $cached = $value(AiPulseMetric::Cached);

            return new AiUsageRow(
                key: (string) ($row->key ?? ''),
                calls: $calls,
                cached: $cached,
                failed: $value(AiPulseMetric::Failed),
                costMicros: $value(AiPulseMetric::CostMicros),
                // Latency is recorded as a **total**, so the mean is it over the number of
                // *provider* calls — the ones not served from cache, because a cache hit records
                // no latency at all. `max(1, …)` is load-bearing: a prompt whose every call was a
                // cache hit is a real state, and zero is the honest answer rather than a crash on
                // somebody's dashboard.
                latencyMs: (int) round($value(AiPulseMetric::LatencyMs) / max(1, $calls - $cached)),
            );
        });
    }
}
