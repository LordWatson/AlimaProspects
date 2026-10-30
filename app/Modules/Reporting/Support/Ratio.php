<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Enums\ColumnFormat;

/**
 * One rule for every rate on a report: a fraction between 0 and 1, or **null** when there is
 * nothing to divide by.
 *
 * Null rather than `0.0` is the whole point. A reply rate of zero means nobody replied; a reply
 * rate over no attempts means nobody was asked, and the two must not render the same way. The
 * screen draws an em dash for null (see {@see ColumnFormat::Percent}) and a `0%` only when it is
 * true — the same honesty rule the funnel's unavailable steps are built on.
 */
final class Ratio
{
    public static function of(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole, 4);
    }
}
