<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Services\Platform\PlatformRollup;
use App\Modules\Compliance\Console\PurgeExpiredDataCommand;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the platform's nightly rollup (Phase 19c).
 *
 * ```
 * php artisan platform:roll-up                  # yesterday, plus the funnel's trailing window
 * php artisan platform:roll-up --date=2026-10-01 # one day, as a reconstruction
 * php artisan platform:roll-up --days=30         # the last 30 days, oldest first
 * ```
 *
 * ## Why it walks **forwards**
 *
 * MRR movement for a day is today's per-tenant MRR against the row the previous night wrote
 * (`PlatformRollup::rollUpMovement()`), so a backfill has to produce the days in order or the
 * movement figures compare against rows that are not there yet. `--days` therefore starts at the
 * oldest day and works towards yesterday, and that is not an implementation detail — reversed, the
 * first day's movement would read as an entire platform's worth of new MRR.
 *
 * ## Why it runs **before** the sweep
 *
 * {@see PurgeExpiredDataCommand} prunes the rows these figures are computed from. Scheduled at
 * 02:40, ahead of Signals' prune (03:20), Compliance's sweep (03:40) and the insights prune
 * (03:50), so every figure is recorded while its source still exists. `PlatformRollupTest` asserts
 * the ordering against the real schedule.
 *
 * ## What a backfill will not invent
 *
 * Dunning, seats, allowance consumption and reported overage are **point-in-time**: nothing in the
 * product historises them, so recomputing an old day would stamp today's state onto it. Those
 * metrics are written only for a day the command is treating as current — yesterday or today — and
 * an older day is left exactly as it was found. The command says so in its output rather than
 * leaving an operator to wonder why a backfilled month has no dunning figures.
 */
final class RollUpPlatformMetricsCommand extends Command
{
    protected $signature = 'platform:roll-up
        {--date= : A single UTC day to roll up (Y-m-d). Defaults to yesterday.}
        {--days= : Roll up this many days ending yesterday, oldest first.}
        {--skip-funnel : Leave the onboarding funnel\'s cohort rows alone.}';

    protected $description = 'Roll the platform\'s daily metrics up from the ledgers (Phase 19c).';

    public function handle(PlatformRollup $rollup): int
    {
        $yesterday = CarbonImmutable::now('UTC')->subDay()->startOfDay();

        $days = $this->dates($yesterday);

        if ($days === null) {
            return self::FAILURE;
        }

        $rows = 0;

        foreach ($days as $day) {
            // A day within the last 24 hours is the nightly case, and the only one where today's
            // state can honestly be attributed to the date being written.
            $pointInTime = ! $day->lessThan($yesterday);

            try {
                $written = $rollup->handle($day, $pointInTime);
            } catch (Throwable $e) {
                // One bad day must not abandon the rest of a backfill: the figures are independent
                // per day, and a half-written window is fixed by running the command again.
                $this->components->error($day->toDateString().' failed: '.$e->getMessage());

                Log::channel('billing')->error('Platform rollup failed for a day.', [
                    'date' => $day->toDateString(),
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $rows += array_sum($written);

            $this->components->twoColumnDetail(
                $day->toDateString().($pointInTime ? '' : ' <fg=yellow>(reconstructed)</>'),
                array_sum($written).' rows across '.count($written).' metrics',
            );
        }

        if (! $this->option('skip-funnel')) {
            $rows += $this->rollUpFunnel($rollup, $yesterday);
        }

        $this->components->info($rows.' rows written.');

        return self::SUCCESS;
    }

    /**
     * The onboarding funnel's trailing window.
     *
     * Recomputed every night rather than written once, because a funnel row is dated by the day a
     * cohort **signed up** and its value changes every time one of those teams takes another step.
     * See `PlatformMetric::isCohort()`.
     */
    private function rollUpFunnel(PlatformRollup $rollup, CarbonImmutable $yesterday): int
    {
        $window = max(1, (int) config('admin.reporting.funnel_cohort_days', 30));
        $rows = 0;

        for ($offset = $window - 1; $offset >= 0; $offset--) {
            $rows += array_sum($rollup->rollUpFunnel($yesterday->subDays($offset)));
        }

        $this->components->twoColumnDetail(
            'Onboarding funnel',
            $window.' cohort days recomputed',
        );

        return $rows;
    }

    /**
     * The days to roll up, oldest first — or null when the options do not describe a window.
     *
     * @return list<CarbonImmutable>|null
     */
    private function dates(CarbonImmutable $yesterday): ?array
    {
        $date = $this->option('date');
        $days = $this->option('days');

        if (is_string($date) && $date !== '') {
            try {
                return [CarbonImmutable::parse($date, 'UTC')->startOfDay()];
            } catch (Throwable) {
                $this->components->error('--date must be a Y-m-d date.');

                return null;
            }
        }

        if (is_string($days) && $days !== '') {
            $count = (int) $days;

            if ($count < 1) {
                $this->components->error('--days must be a positive whole number.');

                return null;
            }

            return array_map(
                static fn (int $offset): CarbonImmutable => $yesterday->subDays($offset),
                // Oldest first. See the class note: movement compares against the previous day's
                // stored row, so the order is load-bearing.
                array_reverse(range(0, $count - 1)),
            );
        }

        return [$yesterday];
    }
}
