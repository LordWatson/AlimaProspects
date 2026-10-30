<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Enums;

use App\Modules\Reporting\Support\DateRange;

/**
 * The windows the dashboard's date filter offers (Phase 13).
 *
 * Presets before a custom range, in the order a reader reaches for them (the `dataviz` guidance):
 * the rolling windows first, then the calendar ones, then everything, then a date pair.
 *
 * A preset is only a *name* for a window — {@see ReportRange::resolve()} turns it into a
 * {@see DateRange} in the **team's** timezone, so a Dubai team's "this month" opens on the 1st in
 * Dubai rather than on the server.
 *
 * Values are stable storage keys (they go in a URL and an audit entry); {@see self::label()} is a
 * translation key.
 */
enum ReportRange: string
{
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case ThisYear = 'this_year';

    /** No lower bound: every figure counts the team's whole history. The default. */
    case AllTime = 'all_time';

    /** A date pair the member typed. Falls back to {@see self::AllTime} when either is missing. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::Last90Days => 'Last 90 days',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::ThisYear => 'This year',
            self::AllTime => 'All time',
            self::Custom => 'Custom range',
        };
    }

    /**
     * How long the window is, in days, or null when the preset has no fixed length (all time, and
     * the calendar presets, whose length depends on today's date).
     */
    public function fixedDays(): ?int
    {
        return match ($this) {
            self::Last7Days => 7,
            self::Last30Days => 30,
            self::Last90Days => 90,
            default => null,
        };
    }

    /**
     * What the delta on a KPI tile is measured against — a translation key, never an assembled
     * sentence (the frontend translates it; `sprintf` here would ship English to an Arabic screen).
     *
     * Null for {@see self::AllTime}, which has no window before it: a "change" against nothing is
     * not a change, and a tile showing one would invite the wrong decision.
     */
    public function deltaLabel(): ?string
    {
        return match ($this) {
            self::Last7Days => 'vs the 7 days before',
            self::Last30Days => 'vs the 30 days before',
            self::Last90Days => 'vs the 90 days before',
            self::AllTime => null,
            default => 'vs the period before',
        };
    }

    /**
     * Resolve the preset against the team's clock.
     *
     * `$from`/`$to` are only read by {@see self::Custom}; they are `Y-m-d` dates in the team's
     * timezone, as the filter control sends them.
     */
    public function resolve(string $timezone, ?string $from = null, ?string $to = null): DateRange
    {
        return DateRange::for($this, $timezone, $from, $to);
    }

    /**
     * The presets the filter control offers, which is every case but {@see self::Custom} — that one
     * is reached by picking two dates, not by picking its name.
     *
     * @return list<self>
     */
    public static function presets(): array
    {
        $presets = [];

        foreach (self::cases() as $range) {
            if ($range !== self::Custom) {
                $presets[] = $range;
            }
        }

        return $presets;
    }
}
