<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Enums;

use App\Modules\Reporting\Support\TrendBuckets;
use Carbon\CarbonImmutable;

/**
 * How wide one bucket of the trend chart is (Phase 13).
 *
 * Chosen from the length of the selected window rather than configured: a weekly bar under a
 * "last 7 days" filter is one bar, and a daily bar over two years is 700 of them. {@see self::for()}
 * holds that rule in one place so {@see TrendBuckets} and the chart's own subtitle cannot disagree
 * about what a bar means.
 */
enum TrendGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** A translation key naming what one bucket is, for the chart's subtitle. */
    public function label(): string
    {
        return match ($this) {
            self::Day => 'Daily',
            self::Week => 'Weekly',
            self::Month => 'Monthly',
        };
    }

    /** Axis label format — short enough to sit under a bar without rotating. */
    public function axisFormat(): string
    {
        return match ($this) {
            self::Day, self::Week => 'j M',
            self::Month => 'M Y',
        };
    }

    public function startOf(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Day => $moment->startOfDay(),
            self::Week => $moment->startOfWeek(),
            self::Month => $moment->startOfMonth(),
        };
    }

    public function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Day => $start->addDay(),
            self::Week => $start->addWeek(),
            self::Month => $start->addMonth(),
        };
    }

    /**
     * The granularity for a window of `$days`, or for an open-ended one (`null`).
     *
     * The thresholds are the bucket ceiling read backwards. {@see TrendBuckets} can carry 52
     * buckets in one query, and a window does **not** start on a period boundary, so it spans up
     * to one more bucket than its length suggests: a weekly window opening on a Saturday borrows
     * six days from the week before. The weekly ceiling is therefore `(52 × 7) − 6 = 358` days
     * rather than a round 364 — at 364 a mid-week window would span 53 buckets, and the oldest
     * would be dropped, which would quietly put leads on the dashboard that the chart never drew.
     * The daily ceiling has the same slack (36 buckets, comfortably inside 52).
     *
     * An open-ended window has no length to measure, so it falls back to weeks and the chart shows
     * its most recent weeks.
     */
    public static function for(?int $days): self
    {
        if ($days === null) {
            return self::Week;
        }

        return match (true) {
            $days <= 35 => self::Day,
            $days <= 358 => self::Week,
            default => self::Month,
        };
    }
}
