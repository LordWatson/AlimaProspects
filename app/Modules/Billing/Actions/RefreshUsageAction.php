<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Services\UsageAlerter;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;

/**
 * Bring a team's usage up to date and warn its Owners if an allowance is nearly gone — the one
 * operation the billing screen performs before it renders.
 *
 * An Action rather than two service calls in the controller, because it is one operation with one
 * meaning ("refresh what this team has used") and the controller's job is to call one of those
 * (CLAUDE.md §2). It also keeps the two halves in the right order and makes them share a single
 * pass over the ledgers: `sync()` returns the summary, so the alerter does not re-read the
 * aggregates that were just read.
 *
 * **Yes, this runs on a GET.** Two aggregate queries and two upserts, deliberately, because the
 * figures an Owner is looking at when they decide whether to upgrade should be the figures as of
 * that moment rather than as of the last scheduled run — and because a team that burns an allowance
 * between two hourly syncs would otherwise meet the limit before the warning.
 *
 * @see UsageAlerter for why a warning cannot be sent twice for the same threshold.
 */
final class RefreshUsageAction
{
    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly UsageAlerter $alerter,
    ) {}

    /**
     * @return array<string, MetricUsage> keyed by metric value
     */
    public function handle(Team $team, ?BillingPeriod $period = null): array
    {
        $period ??= BillingPeriod::containing();

        $usage = $this->ledger->sync($team, $period);

        $this->alerter->alert($team, $usage, $period);

        return $usage;
    }
}
