<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;

/**
 * The one rule every plan limit in Alima is decided by, shared by the sources that answer the
 * other modules' seams.
 *
 * **Enforcement lives here rather than in route middleware**, and that is a decision worth being
 * explicit about given the phase plan calls it middleware. Three of the four places a limit has to
 * bite have no request behind them: a queued discovery job, a scheduled signals watch, and the
 * nightly insight generation all spend a team's allowance from a worker. A middleware would guard
 * the one case that is cheapest to guard — somebody clicking a button — and leave the three that
 * actually run up a bill. So the limit is enforced at the point of spend, in the guards that were
 * built with this seam in Phase 5 and Phase 7, and this concern is what those guards now consult.
 *
 * It also answers the question a refusal cannot: how much headroom is left. A discovery run asked
 * for 200 companies with 40 of the allowance remaining should fetch 40, not fail — see
 * {@see self::headroomFor()}.
 */
trait EnforcesPlanLimits
{
    abstract protected function usageLedger(): UsageLedger;

    /**
     * The team's live position for one metric.
     */
    protected function usageFor(Team $team, BillingMetric $metric): MetricUsage
    {
        return $this->usageLedger()->usage($team, $metric);
    }

    /**
     * Whether the team may spend any more of this metric.
     *
     * False means *stop*: either the plan has no overage and the allowance is gone, or the plan has
     * overage and the hard ceiling has been reached. A team on a metered plan between those two
     * points is still permitted — that is what they are paying for.
     */
    protected function withinAllowance(Team $team, BillingMetric $metric): bool
    {
        return ! $this->usageFor($team, $metric)->isExhausted();
    }

    /**
     * Units of this metric the team may still consume before work stops, or null when there is no
     * ceiling at all (an unlimited plan).
     */
    protected function headroomFor(Team $team, BillingMetric $metric): ?int
    {
        return $this->usageFor($team, $metric)->headroom();
    }
}
