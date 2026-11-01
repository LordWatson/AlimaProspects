<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;

/**
 * Window arithmetic in **date space**, for the one table in the product whose rows are dated by a
 * day rather than stamped with an instant (Phase 19c).
 *
 * {@see DateRange} is an interval of *instants*, and `DateRange::previous()` is an equal-length
 * interval of instants butted up against it — which is exactly right for `created_at` and subtly
 * wrong here. A rolling preset's bounds are mid-day: `Last30Days` opens at D-30 at 14:00 and closes
 * at now. Reduced to whole days by the metric store, the current window covers the dates `D-30 …
 * today` while `previous()` closes at `D-30 14:00` and therefore *also* covers `D-30`. So the
 * boundary day is counted in both windows, and the earlier one spans one more date than the later
 * — which biases every "vs the window before" delta on the screen by about a thirtieth and
 * double-counts a day's signups.
 *
 * {@see self::previous()} builds the comparison window from **dates**: the same number of dates,
 * ending the day before the current window opens. No overlap, equal length, and the same answer
 * for a preset as for a custom range.
 */
final class PlatformWindow
{
    /**
     * The window a request asked for, always cut in **UTC**.
     *
     * The timezone is not a default. `DateRange` cuts a window in the team's zone, which is right
     * for a tenant dashboard and meaningless for an operator: they belong to no team, and one who
     * has just been impersonating somebody would otherwise read the platform's figures on that
     * customer's clock.
     */
    public static function for(ReportRange $range, ?string $from = null, ?string $to = null): DateRange
    {
        return DateRange::for($range, 'UTC', $from, $to);
    }

    /**
     * The comparison window: the same number of **dates**, immediately before this one.
     *
     * Null for an open-ended window, which has no "before" — the same answer
     * {@see DateRange::previous()} gives, for the same reason.
     */
    public static function previous(DateRange $range): ?DateRange
    {
        [$first, $last] = self::dates($range);

        if ($first === null || $last === null) {
            return null;
        }

        // Inclusive count of dates, so a one-day window compares against the one day before it.
        $days = (int) $first->diffInDays($last) + 1;

        $end = $first->subDay();
        $start = $end->subDays($days - 1);

        return DateRange::for(ReportRange::Custom, 'UTC', $start->toDateString(), $end->toDateString());
    }

    /**
     * The window's first and last **UTC dates**, inclusive — the pair the metric store compares
     * against.
     *
     * `->utc()` before `startOfDay()`, for the reason `DateRange::constrain()` spells out: the
     * bounds are Carbon instances in whatever zone the window was cut in, and taking the date off a
     * Dubai instant would start the window on the wrong day for the four hours either side of
     * midnight.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function dates(DateRange $range): array
    {
        if ($range->from === null || $range->to === null) {
            return [null, null];
        }

        return [
            $range->from->utc()->startOfDay(),
            // The last day the window covers, which is the exclusive bound less a second — the
            // same reading `DateRange::toDate()` gives the screen, so the figures and the subtitle
            // cannot describe different windows.
            $range->to->utc()->subSecond()->startOfDay(),
        ];
    }
}
