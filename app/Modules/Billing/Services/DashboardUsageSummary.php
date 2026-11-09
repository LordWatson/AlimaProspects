<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Contracts\UsageSummarySource;
use App\Modules\Reporting\Data\AllowanceLine;
use App\Modules\Reporting\Services\NoUsageSummary;

/**
 * Fills in Reporting's allowance seam from this team's real plan position.
 *
 * The eighth binding this module installs over another module's own default (ADR 0012's pattern):
 * Reporting ships {@see NoUsageSummary} and nothing there imports
 * Billing, so the dashboard's allowance panel appears because Billing is installed rather than
 * because the dashboard was taught what a plan is.
 *
 * It reads {@see UsageLedger::usage()}, the same live position the billing screen and every guard
 * read — not `usage_meters`, which is the billing artefact and lags by up to an hour. A dashboard
 * that showed a stale figure beside a guard that refused on a fresh one would be the worse of the
 * two to be looking at.
 *
 * **Which lines are worth showing is decided here**, because it is a question about plans. Two
 * rules, and between them they keep the panel short enough to read:
 *
 * - An **unlimited** allowance is left out. There is no bar to draw and nothing to run out of, so a
 *   line saying "no limit on this plan" is a row of furniture on a panel whose whole job is to warn.
 *   The billing screen still lists it, which is the right place for an inventory.
 * - An allowance of **zero that nobody has touched** is left out too: on this plan that metric is
 *   not a thing the team is doing, and six empty bars for features they have not bought would bury
 *   the two that are filling up. A zero allowance they *have* spent against stays, because being
 *   stopped is exactly what they need to see.
 *
 * Order is {@see BillingMetric}'s own declaration order, which runs the
 * three that the product does before the six that cost us money per unit — most-used first, by
 * construction rather than by a sort nobody maintains.
 */
final readonly class DashboardUsageSummary implements UsageSummarySource
{
    public function __construct(private UsageLedger $ledger) {}

    /**
     * @return list<AllowanceLine>
     */
    public function allowancesFor(Team $team): array
    {
        $lines = [];

        foreach ($this->ledger->summary($team) as $usage) {
            if ($usage->included === null) {
                continue;
            }

            if ($usage->included === 0 && $usage->used === 0) {
                continue;
            }

            $lines[] = $this->line($usage);
        }

        return $lines;
    }

    /**
     * Every derived figure is resolved here rather than in the browser, for the reason
     * `Http\Resources\UsageResource` gives: where a plan stops — allowance or ceiling, depending on
     * whether it meters — must not be a rule that exists in two languages.
     */
    private function line(MetricUsage $usage): AllowanceLine
    {
        return new AllowanceLine(
            metric: $usage->metric->value,
            label: $usage->metric->label(),
            used: $usage->used,
            included: $usage->included,
            remaining: $usage->remaining(),
            used_percent: $usage->usedPercent(),
            ceiling: $usage->ceiling,
            headroom: $usage->headroom(),
            overage: $usage->overage(),
            overage_allowed: $usage->overageAllowed,
            exhausted: $usage->isExhausted(),
        );
    }
}
