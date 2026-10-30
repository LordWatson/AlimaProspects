<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Enums;

use App\Modules\Reporting\Data\Kpi;

/**
 * How a {@see Kpi}'s integer value should be rendered. The number
 * itself always crosses the wire raw — the locale and the team's currency are the frontend's to
 * apply (`useFormat`) — so the tile needs telling which of the two it is holding. Money arrives in
 * integer minor units, per CLAUDE.md §2.
 */
enum KpiFormat: string
{
    case Count = 'count';
    case Money = 'money';
}
