<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Enums;

use App\Modules\Reporting\Data\ReportColumn;

/**
 * How a {@see ReportColumn}'s cells should be rendered.
 *
 * The cell always crosses the wire raw — an integer, a fraction, an ISO date — because only the
 * client knows the active locale and the team's timezone (`useFormat`), and only the CSV writer
 * knows that a spreadsheet wants `12.40` rather than `£12.40`. The format tells each of them which
 * kind of value they are holding.
 */
enum ColumnFormat: string
{
    /** A name, a label, a key. Left-aligned; neutralised before it reaches a spreadsheet. */
    case Text = 'text';

    /** A whole number. */
    case Count = 'count';

    /** Integer **minor units** (CLAUDE.md §2), with the column's currency code beside it. */
    case Money = 'money';

    /** A fraction between 0 and 1 — the client shows a percentage, the CSV a number out of 100. */
    case Percent = 'percent';

    /** An ISO-8601 instant, displayed in the team's timezone. */
    case Date = 'date';

    /** Whether the column reads better right-aligned, which every numeric one does. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Count, self::Money, self::Percent => true,
            self::Text, self::Date => false,
        };
    }
}
