<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How many new companies a team has actually had out of the product over a window — the lead side
 * of what Billing meters, answered by the module that owns the table.
 *
 * Counted from `discovery_runs.leads_created`, not from the `leads` table, and the difference is
 * the whole point: `leads_created` is the de-duplicated count of rows a run genuinely added, so a
 * search that returns fifty companies the team already had costs them nothing, while the `leads`
 * table shrinks every night as Compliance's retention sweep prunes it. Metering a number that goes
 * down on its own would hand a team back allowance it had already spent.
 *
 * Attributed by `completed_at`, because that is when the leads exist. A run that starts on the last
 * day of a month and finishes on the first of the next belongs to the month it delivered in.
 *
 * **Failed runs count too**, and that is not a quirk. A run persists its leads and *then* settles,
 * so one that dies in between has given the team its companies; counting only completed runs would
 * make those free, which is a hole somebody could widen on purpose. `DiscoverLeadsJob` writes
 * `leads_created` as soon as the rows exist precisely so this can be counted honestly, and a run
 * that failed before persisting anything still carries zero.
 */
final class LeadUsageCounter
{
    /**
     * New companies created for a team between `$from` (inclusive) and `$to` (exclusive).
     *
     * The bounds must already be in **UTC** — they reach a `where` on a column written by queue
     * workers in UTC (CLAUDE.md §2).
     */
    public function createdBetween(Team $team, DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) DiscoveryRun::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->whereIn('status', [DiscoveryRunStatus::Completed, DiscoveryRunStatus::Failed])
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $to)
            ->sum('leads_created');
    }

    /**
     * New companies created for a team so far this calendar month (UTC) — the window the lead
     * allowance is measured over.
     */
    public function createdThisMonth(Team $team): int
    {
        $start = Carbon::now('UTC')->startOfMonth();

        return $this->createdBetween($team, $start, $start->copy()->addMonth());
    }
}
