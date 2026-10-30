<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Console;

use App\Modules\AiInsights\Jobs\GeneratePeriodicInsightsJob;
use App\Modules\AiInsights\Models\InsightRun;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues the daily insight run for every team whose morning it is (Phase 14).
 *
 * ### Why this is hourly and not a nightly `dailyAt`
 *
 * A team picks nothing here; its **timezone** does. The insights are a thing to read before the
 * first call of the day, so the sweep runs every hour and, per team, converts `now()` into that
 * team's timezone before comparing it with the configured hour. A single run at 07:00 UTC would
 * reach Dubai at eleven and drift every time the clocks changed in London — the same mistake, and
 * the same fix, as `notifications:digest` (see `docs/modules/notifications.md`).
 *
 * ### Idempotence, in two layers
 *
 * This command asks {@see InsightRun} whether the team has already been done **today in its own
 * timezone**, which is what stops a second sweep an hour later queueing a second run — and the
 * marker is written whatever the run found, so a day that legitimately produced no insights is
 * still a day that has been done. If a run slips through anyway (a retry, a DST fold, a manual
 * `--team`), each insight's dedupe key means it finds its rows already there and creates nothing.
 * Neither layer is enough on its own: the first saves the work and the AI call, the second saves
 * the member from a duplicate.
 *
 * Runs with no active team, so the `BelongsToTeam` global scope is a no-op and every team is
 * considered; the job runs inside each team's own context.
 */
final class GenerateInsightsCommand extends Command
{
    protected $signature = 'insights:generate
        {--team= : Generate for one team now, whatever the hour and whether or not it has been done}
        {--dry-run : Report which teams are due without queueing anything}';

    protected $description = 'Queue the daily insight run for each team whose local morning it is.';

    /** Teams per chunk. */
    private const int CHUNK = 100;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyTeam = $this->option('team');
        $forced = is_string($onlyTeam) && $onlyTeam !== '';
        $hour = max(0, min(23, (int) config('ai-insights.generate_at_hour', 7)));
        $now = CarbonImmutable::now('UTC');

        $queued = 0;
        $skipped = 0;

        $teams = Team::query()->orderBy('id');

        if ($forced) {
            $teams->whereKey((int) $onlyTeam);
        }

        $teams->chunkById(self::CHUNK, function (Collection $chunk) use (
            $now,
            $hour,
            $dryRun,
            $forced,
            &$queued,
            &$skipped,
        ): void {
            $ranToday = $this->ranToday($chunk, $now);

            foreach ($chunk as $team) {
                /** @var Team $team */
                // `--team` is the demo and support path: it means "now, please", so the hour and
                // the once-a-day marker are both skipped. The insights' dedupe keys, and the
                // generator's "already have today's digest" check, are what keep it safe.
                if (! $forced && ! $this->isDue($team, $now, $hour, $ranToday)) {
                    $skipped++;

                    continue;
                }

                $queued++;

                if (! $dryRun) {
                    GeneratePeriodicInsightsJob::dispatch((int) $team->getKey(), force: $forced);
                }
            }
        });

        Log::channel('insights')->info('Insight generation swept.', [
            'queued' => $queued,
            'skipped' => $skipped,
            'hour' => $hour,
            'dry_run' => $dryRun,
            'forced' => $forced,
        ]);

        $this->info($dryRun
            ? "Would queue {$queued} insight run(s) ({$skipped} not due)."
            : "Queued {$queued} insight run(s) ({$skipped} not due).");

        return self::SUCCESS;
    }

    /**
     * Has this team reached its hour, and has it not already been done today?
     *
     * The hour comparison is `>=` rather than `==` so a worker that was down at 07:00 still
     * produces the day's insights when it comes back at 09:00. A member who starts late would
     * otherwise lose the day entirely, and "what Alima noticed" is not less true at nine.
     *
     * @param  array<int, list<string>>  $ranToday  Team id => the local dates it has runs for.
     */
    private function isDue(Team $team, CarbonImmutable $now, int $hour, array $ranToday): bool
    {
        $local = $this->localNow($team, $now);

        // A team whose timezone the database cannot be made sense of is skipped with a log line
        // rather than taking the rest of its chunk down with it: `setTimezone()` on an invalid
        // string throws, and one bad row must not cost every team after it its insights.
        if ($local === null) {
            return false;
        }

        if ($local->hour < $hour) {
            return false;
        }

        return ! in_array($local->toDateString(), $ranToday[(int) $team->getKey()] ?? [], strict: true);
    }

    private function localNow(Team $team, CarbonImmutable $now): ?CarbonImmutable
    {
        try {
            return $now->setTimezone((string) $team->timezone);
        } catch (Throwable $e) {
            Log::channel('insights')->error('Skipped a team with an unusable timezone.', [
                'team_id' => $team->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * The local dates each team in this chunk already has a run for, narrowed to the couple of
     * days any team's "today" could be.
     *
     * One query per chunk rather than one per team: the sweep runs hourly, and a query per team
     * per hour is a cost that grows with the customer list for no information. The date window is
     * what keeps the row count down on a long-lived account — every timezone on earth is inside
     * one day of UTC.
     *
     * @param  Collection<int, Team>  $teams
     * @return array<int, list<string>>
     */
    private function ranToday(Collection $teams, CarbonImmutable $now): array
    {
        /** @var list<int> $ids */
        $ids = $teams->map(static fn (Team $team): int => (int) $team->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $dates = [
            $now->subDay()->toDateString(),
            $now->toDateString(),
            $now->addDay()->toDateString(),
        ];

        $runs = [];

        InsightRun::query()
            ->withoutGlobalScopes()
            ->whereIn('team_id', $ids)
            ->whereIn('local_date', $dates)
            // `toBase()`, so a two-column row is never hydrated into a half-populated model: under
            // `Model::shouldBeStrict()` that is a trap waiting for the first reader who touches a
            // column the select didn't ask for.
            ->toBase()
            ->get(['team_id', 'local_date'])
            ->each(function (object $row) use (&$runs): void {
                $runs[(int) $row->team_id][] = (string) $row->local_date;
            });

        return $runs;
    }
}
