<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Auth\Services\UnlimitedSeatLimit;
use App\Modules\Core\Models\Team;

/**
 * The real seat limit, replacing Auth's {@see UnlimitedSeatLimit}: whatever the team's plan
 * includes, with null for the Enterprise plan's unlimited seats.
 *
 * There is no ceiling or overage here, unlike leads and credits. A seat is a licence, not a
 * consumable: silently letting a team add an eleventh person and billing them for it would be a
 * charge nobody agreed to, where an extra hundred companies is work they asked for.
 */
final class PlanSeatLimit implements SeatLimitSource
{
    public function __construct(private readonly PlanResolver $plans) {}

    public function seatsFor(Team $team): ?int
    {
        return $this->plans->planFor($team)->seats;
    }
}
