<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageAlerter;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Console\Command;

/**
 * Snapshot every team's usage into `usage_meters`, and warn the ones approaching a limit.
 *
 * The billing screen syncs the team looking at it, which covers teams with somebody watching. This
 * covers the rest: a team whose discovery runs all night and whose Owner opens the screen once a
 * month still needs the warning email before the allowance runs out, and the closed month still
 * needs a meter row after the ledger behind it has been pruned.
 *
 * It syncs the **previous** period as well as the current one, and that is not redundant: the last
 * hourly sync of a month runs before midnight, so without one write after the month closes its
 * final hour would never reach the snapshot. `UsageLedger::sync()` seals a row once it has been
 * written at or after its period ended, so every later run leaves the closed month exactly as it
 * found it.
 */
final class SyncUsageCommand extends Command
{
    protected $signature = 'billing:sync-usage
                            {--team= : Only this team id}';

    protected $description = 'Snapshot each team’s lead and AI usage, and alert the ones near a limit.';

    public function handle(
        UsageLedger $ledger,
        UsageAlerter $alerter,
        PlanResolver $plans,
        TeamContext $teamContext,
    ): int {
        $synced = 0;

        Team::query()
            ->when(
                $this->option('team') !== null,
                fn ($query) => $query->whereKey((int) $this->option('team')),
            )
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($ledger, $alerter, $plans, $teamContext, &$synced): void {
                foreach ($chunk as $team) {
                    // Per team, because the resolver memoises and this command walks every one.
                    $plans->forget($team);

                    $teamContext->run($team, function () use ($team, $ledger, $alerter): void {
                        $period = BillingPeriod::containing();

                        // Seal the month that has just closed, if this is the first run since it
                        // did. A no-op every other time.
                        $ledger->sync($team, $period->previous());

                        // One pass: `sync` returns the same summary `summary()` would, so the
                        // alerter does not re-run the ledger aggregates it has just read.
                        $alerter->alert($team, $ledger->sync($team, $period), $period);
                    });

                    $synced++;
                }
            });

        $this->components->info("Synced usage for {$synced} team(s).");

        return self::SUCCESS;
    }
}
