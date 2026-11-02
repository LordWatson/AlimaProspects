<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Reporting\Data\Kpi;
use App\Modules\Reporting\Enums\ColumnFormat;
use App\Modules\Reporting\Enums\KpiFormat;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;

/**
 * What the panel's front page shows: is the platform growing, and is anything on fire?
 *
 * **Not a thirteenth report.** {@see PlatformReportBuilder} answers questions an operator sits down
 * to ask, over a window they chose, with a cohort rule per table (ADR 0016). This answers the one
 * question somebody has while walking past: it owns no window, offers no filters, and is read in
 * three seconds. Reporting is where a morning's work starts; this is the glance before it.
 *
 * It reads the **same nightly rollup** rather than counting live, for the same reason reporting
 * does: a cross-tenant `count(*)` over every table on every page load is a bill nobody authorised,
 * and a figure derived two different ways is a figure that will eventually disagree with itself.
 * Each series is one indexed read of `platform_daily_metrics`.
 *
 * The trends are **thirty days against the thirty before**, fixed. A window picker here would make
 * this the reporting screen, badly.
 */
final class PlatformPulse
{
    /** The window every figure on the front page is about. */
    private const int DAYS = 30;

    /**
     * The headline tiles, in the order an operator reads them: the business, then the usage, then
     * the spend.
     *
     * @var list<PlatformMetric>
     */
    private const array TILES = [
        PlatformMetric::Mrr,
        PlatformMetric::PayingTeams,
        PlatformMetric::Signups,
        PlatformMetric::Activations,
        PlatformMetric::AiCostMicros,
    ];

    /**
     * The series the big chart offers, switched **client-side**: three indexed reads up front
     * beats a round trip per toggle, and a dashboard that reloads to change a line is a dashboard
     * nobody toggles.
     *
     * @var list<PlatformMetric>
     */
    private const array SERIES = [
        PlatformMetric::Signups,
        PlatformMetric::Activations,
        PlatformMetric::AiCostMicros,
    ];

    public function __construct(
        private readonly PlatformMetricStore $store,
        private readonly QueueHealth $queues,
        private readonly ProviderHealth $providers,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function tiles(): array
    {
        [$window, $previous] = $this->windows();

        return array_map(
            fn (PlatformMetric $metric): array => $this->kpi($metric, $window, $previous)->toArray(),
            self::TILES,
        );
    }

    /**
     * Daily series for the chart, each with its own label and format.
     *
     * @return list<array{key: string, label: string, caption: string, format: string, points: list<array{label: string, value: int}>}>
     */
    public function series(): array
    {
        [$window] = $this->windows();

        return array_map(function (PlatformMetric $metric) use ($window): array {
            $points = [];

            foreach ($this->store->series($metric, $window) as $day => $value) {
                $points[] = ['label' => (string) $day, 'value' => (int) $value];
            }

            return [
                'key' => $metric->value,
                'label' => $metric->label(),
                'caption' => $metric->definition(),
                'format' => $this->format($metric)->value,
                'points' => $points,
            ];
        }, self::SERIES);
    }

    /**
     * Is anything on fire?
     *
     * Deliberately three facts and a verdict rather than a screenful: failed jobs, open circuit
     * breakers, and whether last night's rollup ran — which is the one that invalidates everything
     * else on the page, because every figure above comes from it.
     *
     * @return array{ok: bool, failed_jobs: int, breakers_open: list<string>, rollup_day: string|null, rollup_stale: bool}
     */
    public function health(): array
    {
        // `failedJobs()` returns an **envelope** — availability, driver, total, and a page of rows
        // — so `count()` of it is the number of keys, which is four whatever the queue is doing.
        // The first version of this counted that, and reported four failed jobs on an empty
        // installation. Read the total it already computed.
        $queues = $this->queues->failedJobs(limit: 1);
        $failed = ($queues['available'] ?? false) === true ? (int) ($queues['total'] ?? 0) : 0;

        $open = [];

        foreach ($this->providers->all() as $provider) {
            if (($provider['open'] ?? false) === true) {
                $open[] = (string) $provider['label'];
            }
        }

        [$window] = $this->windows();

        // The rollup stamps its own day, so "when did the numbers last move" is a read rather than
        // an inference. Null means it has never run on this installation.
        $rollupDay = $this->store->latestKnownDay(PlatformMetric::RollupDay, $window);
        $stale = $rollupDay === null || $rollupDay < now('UTC')->subDay()->toDateString();

        return [
            'ok' => $failed === 0 && $open === [] && ! $stale,
            'failed_jobs' => $failed,
            'breakers_open' => $open,
            'rollup_day' => $rollupDay,
            'rollup_stale' => $stale,
        ];
    }

    /**
     * The window and the one before it, both in UTC.
     *
     * UTC because the platform has no timezone of its own and the rollup is cut in UTC — a panel
     * that framed a day in anybody's local time would be comparing a figure with a different
     * figure (`docs/modules/admin.md`, ADR 0016).
     *
     * @return array{0: DateRange, 1: DateRange}
     */
    private function windows(): array
    {
        $today = now('UTC')->toImmutable();

        $window = DateRange::for(
            ReportRange::Custom,
            'UTC',
            $today->subDays(self::DAYS - 1)->toDateString(),
            $today->toDateString(),
        );

        $previous = DateRange::for(
            ReportRange::Custom,
            'UTC',
            $today->subDays(self::DAYS * 2 - 1)->toDateString(),
            $today->subDays(self::DAYS)->toDateString(),
        );

        return [$window, $previous];
    }

    /**
     * One tile, in the shape the tenant dashboard's own `StatTile` already reads.
     *
     * Reusing `Reporting\Data\Kpi` rather than inventing a panel KPI: the component is the same
     * component, and a second shape for the same idea is how two screens drift apart. It is a DTO,
     * not one of Reporting's Actions — platform reporting still has its own builders (ADR 0016).
     */
    private function kpi(PlatformMetric $metric, DateRange $window, DateRange $previous): Kpi
    {
        $value = $this->store->total($metric, $window);
        $before = $this->store->total($metric, $previous);

        return new Kpi(
            key: $metric->value,
            label: $metric->label(),
            value: $value,
            caption: $metric->definition(),
            format: $this->format($metric),
            currency: null,
            delta: $value - $before,
            delta_label: 'vs the 30 days before',
            spark: array_values($this->store->series($metric, $window)),
        );
    }

    private function format(PlatformMetric $metric): KpiFormat
    {
        return $metric->kind()->format() === ColumnFormat::Money
            ? KpiFormat::Money
            : KpiFormat::Count;
    }
}
