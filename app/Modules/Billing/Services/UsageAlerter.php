<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Core\Models\Team;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a team's Owners and Admins when an allowance is nearly gone — the other half of
 * enforcement, because a limit nobody saw coming reads as a fault rather than a plan.
 *
 * **At most one message per team, per metric, per period, per threshold.** The check runs on every
 * usage sync, which is hourly plus every visit to the billing screen, so without that the first
 * busy afternoon past 80% would send a notification an hour until the month turned over. The lock
 * is a cache `add` — atomic, so two workers syncing the same team in the same second cannot both
 * win — held until the period ends, which is exactly when the warning becomes relevant again.
 *
 * Cache rather than a column, because this is a de-duplication window and not a fact about the
 * team: losing it to a flushed cache sends one extra notification, where losing a column would
 * need a migration to express something nothing else reads.
 */
final class UsageAlerter
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * @param  array<string, MetricUsage>  $usage  keyed by metric value, as {@see UsageLedger::summary()} returns
     */
    public function alert(Team $team, array $usage, ?BillingPeriod $period = null): void
    {
        $period ??= BillingPeriod::containing();

        /** @var list<int> $thresholds */
        $thresholds = array_map(intval(...), (array) config('billing.alerts.thresholds', [20, 0]));

        // Descending, so a team that goes from 50% left to nothing left in one run is told the
        // thing that matters — "it has run out" — and not warned that it is getting low.
        rsort($thresholds);

        foreach ($usage as $metric) {
            $this->alertFor($team, $metric, $period, $thresholds);
        }
    }

    /**
     * @param  list<int>  $thresholds
     */
    private function alertFor(Team $team, MetricUsage $usage, BillingPeriod $period, array $thresholds): void
    {
        // Nothing to warn about on an unlimited plan, and nothing to warn about before any of it
        // has been used — a brand-new period sitting at 100% remaining is not news.
        if ($usage->included === null || $usage->included <= 0 || $usage->used <= 0) {
            return;
        }

        $remainingPercent = 100 - $usage->usedPercent();

        foreach ($thresholds as $threshold) {
            if ($remainingPercent > $threshold) {
                continue;
            }

            if (! $this->claim($team, $usage, $period, $threshold)) {
                return;
            }

            $this->notifications->toAdmins($team, NotificationType::CreditLow, [
                'metric' => $usage->metric->value,
                'remaining_percent' => $remainingPercent,
            ]);

            // One threshold per run: having crossed 20% and 0% in the same sync, the team is told
            // it has run out and not also told it is getting low.
            return;
        }
    }

    private function claim(Team $team, MetricUsage $usage, BillingPeriod $period, int $threshold): bool
    {
        $key = sprintf(
            'billing:alert:%d:%s:%s:%d',
            $team->getKey(),
            $usage->metric->value,
            $period->start->format('Y-m'),
            $threshold,
        );

        return Cache::add($key, true, $period->end);
    }
}
