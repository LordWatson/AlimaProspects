<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\PlatformMetric;
use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * How each external provider is doing (Phase 19c): the breaker's live state, and the recent error
 * and latency shape from the rollup.
 *
 * ## The breaker is advisory, and the screen says so
 *
 * {@see CircuitBreaker} is cache-backed by design: its state sheds load rather than guaranteeing
 * anything, it evaporates when the cache is cleared, and its failure counter carries the cooldown's
 * own TTL so a quiet provider reports zero rather than its history. Every row this returns carries
 * `advisory: true` and the screen prints the caveat. A health panel that reported the breaker as
 * truth would be believed.
 *
 * ## Three providers have no breaker at all
 *
 * The AI transport, the mail transport and Stripe. {@see PlatformProvider::breakerPrefix()} returns
 * null for them and `instrumented` comes back false — not "closed", which would be a green light
 * nobody wired up. What those three do have is an error rate, which the rollup records.
 *
 * ## Rates come from the rollup, not from a live scan
 *
 * A cross-tenant scan over `ai_requests` is the one query `platform_daily_metrics` exists to
 * avoid, so "recent" means the last `admin.reporting.provider_health_days` **complete** days. The
 * trade is that a provider that started failing an hour ago shows a healthy rate and an open
 * breaker — which is the right way round: the breaker is the live signal and the rates are the
 * context.
 */
final class ProviderHealth
{
    public function __construct(
        private readonly Cache $cache,
        private readonly PlatformMetricStore $store,
        private readonly PlatformMoney $money,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $days = max(1, (int) config('admin.reporting.provider_health_days', 7));
        $range = DateRange::for(ReportRange::Custom, 'UTC', ...$this->window($days));

        $calls = $this->store->byDimension(PlatformMetric::ProviderCalls, $range);
        $failures = $this->store->byDimension(PlatformMetric::ProviderFailures, $range);
        $cost = $this->store->byDimension(PlatformMetric::ProviderCostMicros, $range);
        $meanLatency = $this->meanAiLatency($range);

        $rows = [];

        foreach (PlatformProvider::cases() as $provider) {
            $dimension = $provider->metricDimension();
            $made = $calls[$dimension] ?? 0;
            $failed = $failures[$dimension] ?? 0;
            $prefix = $provider->breakerPrefix();

            $rows[] = [
                'key' => $provider->value,
                'label' => $provider->label(),
                'impact' => $provider->impact(),
                'instrumented' => $prefix !== null,
                'open' => $prefix === null ? null : $this->breaker($provider)->isOpen((string) $provider->breakerKey()),
                'failures_recorded' => $prefix === null ? null : $this->breaker($provider)->failures((string) $provider->breakerKey()),
                'threshold' => $prefix === null ? null : $this->threshold($provider),
                'advisory' => true,
                'metered' => $provider->isMetered(),
                'calls' => $provider->isMetered() ? $made : null,
                'failures' => $provider->isMetered() ? $failed : null,
                // Null over no calls. A failure rate of 0% for a provider nobody used reads as
                // healthy; an em dash reads as unused, which is what it is.
                'failure_rate' => $provider->isMetered() ? Ratio::of($failed, $made) : null,
                'cost_minor' => $provider->isMetered()
                    ? $this->money->convert([$this->currencyFor($provider) => $cost[$dimension] ?? 0])->minor_units
                    : null,
                'mean_latency_ms' => $provider === PlatformProvider::Claude ? $meanLatency : null,
            ];
        }

        return $rows;
    }

    /**
     * The window these figures cover, for the screen's caption and the reset confirmation.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $days = max(1, (int) config('admin.reporting.provider_health_days', 7));

        return [
            'days' => $days,
            'currency' => $this->money->currency(),
            'last_rolled_up' => $this->store->lastRolledUpDay(),
        ];
    }

    /**
     * A breaker configured exactly as the owning module configured it.
     *
     * Admin **observes** these; it does not own them. The threshold and cooldown come from each
     * module's own config so a reset clears the same cache entries the module's own breaker reads —
     * a prefix declared twice is a reset that clears the wrong key and leaves the real circuit
     * open, which is the one failure mode this screen must not have.
     */
    public function breaker(PlatformProvider $provider): CircuitBreaker
    {
        return new CircuitBreaker(
            $this->cache,
            $this->threshold($provider),
            $this->cooldown($provider),
            prefix: (string) $provider->breakerPrefix(),
        );
    }

    private function threshold(PlatformProvider $provider): int
    {
        return (int) config($this->configRoot($provider).'.circuit_breaker.threshold', 5);
    }

    private function cooldown(PlatformProvider $provider): int
    {
        return (int) config($this->configRoot($provider).'.circuit_breaker.cooldown', 60);
    }

    /** Which module's config holds this provider's breaker settings. */
    private function configRoot(PlatformProvider $provider): string
    {
        return match ($provider) {
            PlatformProvider::SerperNews => 'signals',
            PlatformProvider::Push => 'notifications',
            default => 'lead-sourcing',
        };
    }

    /**
     * Which currency a provider's recorded cost is in — `ai_requests` carries its own, and the two
     * run ledgers are denominated by config. See `admin.reporting.data_cost_currency`.
     */
    private function currencyFor(PlatformProvider $provider): string
    {
        return $provider === PlatformProvider::Claude
            ? (string) config('ai-platform.cost.currency', 'usd')
            : (string) config('admin.reporting.data_cost_currency', 'usd');
    }

    private function meanAiLatency(DateRange $range): ?int
    {
        $calls = $this->store->total(PlatformMetric::AiRequests, $range);

        if ($calls === 0) {
            return null;
        }

        return intdiv($this->store->total(PlatformMetric::AiLatencyMsTotal, $range), $calls);
    }

    /**
     * The custom window's `Y-m-d` bounds: the last `$days` **complete** days, ending yesterday.
     *
     * Yesterday rather than today, because today has not been rolled up and a part-day would drag
     * every rate towards zero — the same reason `DateRange` never lets a window run past now.
     *
     * @return array{0: string, 1: string}
     */
    private function window(int $days): array
    {
        $yesterday = CarbonImmutable::now('UTC')->subDay();

        return [$yesterday->subDays($days - 1)->toDateString(), $yesterday->toDateString()];
    }
}
