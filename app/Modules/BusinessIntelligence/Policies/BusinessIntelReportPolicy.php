<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Policies;

use App\Models\User;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Enums\TeamRole;

/**
 * Ownership double-check for background-check reports (ADR 0006). The report is team-level business
 * setup, so it mirrors the Business Profile: any member may view it; only Owners and Admins may run
 * a check or accept its findings; a non-member always fails. Role comes from the `team_user` pivot
 * via {@see User::roleOn()}.
 */
final class BusinessIntelReportPolicy
{
    public function view(User $user, BusinessIntelReport $report): bool
    {
        return $user->belongsToTeam($report->team);
    }

    public function manage(User $user, BusinessIntelReport $report): bool
    {
        $role = $user->roleOn($report->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
