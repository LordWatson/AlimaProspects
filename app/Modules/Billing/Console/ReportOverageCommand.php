<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\OverageReporter;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push each subscribed team's metered overage to Stripe.
 *
 * Scheduled daily rather than per request: metered usage is summed by Stripe over the billing
 * period, so there is nothing a customer sees sooner by reporting it the instant it happens, and
 * doing it in the request path would put a Stripe call behind a discovery run.
 *
 * It reports the current period plus **every closed period that still has overage outstanding**,
 * which is a question the meter rows answer directly. The obvious alternative — "also do last
 * month for the first day or two" — quietly loses a month if the command does not run on those
 * days, or if Stripe is down when it does. Asking the table instead means an outage delays the
 * revenue rather than dropping it.
 *
 * One team's failure never stops the rest: Stripe refusing a single subscription is a support
 * matter for that team, not a reason for every other team's usage to go unbilled.
 */
final class ReportOverageCommand extends Command
{
    protected $signature = 'billing:report-overage
                            {--team= : Only this team id}';

    protected $description = 'Report metered lead and AI overage to Stripe for each subscribed team.';

    public function handle(OverageReporter $reporter, PlanResolver $plans): int
    {
        $teams = 0;
        $units = 0;
        $failures = 0;

        Team::query()
            ->whereNotNull('stripe_id')
            ->when(
                $this->option('team') !== null,
                fn ($query) => $query->whereKey((int) $this->option('team')),
            )
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($reporter, $plans, &$teams, &$units, &$failures): void {
                foreach ($chunk as $team) {
                    // The memo is per team and this walks every one of them — without forgetting,
                    // team 400 would be resolved against an array holding 399 other teams.
                    $plans->forget($team);

                    foreach ($this->periodsFor($team) as $period) {
                        try {
                            $units += $reporter->report($team, $period);
                        } catch (Throwable $e) {
                            $failures++;

                            Log::channel('billing')->error('Overage reporting failed for a team.', [
                                'team_id' => $team->getKey(),
                                'period_start' => $period->start->toDateString(),
                                'message' => $e->getMessage(),
                            ]);

                            $this->components->warn("Team {$team->getKey()}: {$e->getMessage()}");
                        }
                    }

                    $teams++;
                }
            });

        $this->components->info("Reported {$units} overage unit(s) across {$teams} team(s).");

        if ($failures > 0) {
            $this->components->warn("{$failures} report(s) failed — see the billing log.");
        }

        // Still a success exit code: the failures are per team and logged, and a non-zero exit
        // would make the scheduler treat a single bad subscription as a broken job.
        return self::SUCCESS;
    }

    /**
     * The periods to report for one team: this one, plus any closed one still carrying overage
     * nobody has billed.
     *
     * The closed ones come from the meter rows rather than from a date rule, so a month whose last
     * overage was never sent — because the command did not run, or because Stripe was down when it
     * did — is picked up on the next run instead of being lost. Newest first, so the current
     * period is settled before the history.
     *
     * @return list<BillingPeriod>
     */
    private function periodsFor(Team $team): array
    {
        $current = BillingPeriod::containing();

        /** @var list<BillingPeriod> $periods */
        $periods = [$current];

        $outstanding = UsageMeter::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('period_start', '<', $current->start)
            ->whereRaw('used - included - reported > 0')
            ->orderByDesc('period_start')
            ->pluck('period_start');

        foreach ($outstanding as $start) {
            $period = BillingPeriod::containing($start);

            // One row per metric, so the same period turns up twice.
            foreach ($periods as $seen) {
                if ($seen->start->equalTo($period->start)) {
                    continue 2;
                }
            }

            $periods[] = $period;
        }

        return $periods;
    }
}
