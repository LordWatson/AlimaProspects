<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\LeadSourcing\Models\Lead;

/**
 * Ownership double-check for leads (ADR 0006). The {@see Lead} global scope hides other teams'
 * rows, but a global scope is not an authorisation check — this is. A non-member always fails.
 *
 * - **view** — any member of the owning team, Read-only included.
 * - **work** — any member except Read-only: the one ability behind every piece of CRM-lite work on
 *   a lead (moving it between stages, tagging it, writing a note, adding or ticking off a task,
 *   assigning it, and the bulk versions of all of those). They are one permission rather than six
 *   because they are one job, and a team that trusts a member to move a lead trusts them to note
 *   why.
 * - **create** — any member except Read-only, the same as `work`. Adding one company by hand is
 *   ordinary work — a member back from an event with a business card — and **not** the same act as
 *   an import, which is why it isn't behind `import`: one lead at a time is something a team member
 *   is trusted to do, thousands at once isn't.
 * - **import** / **export** — Owner/Admin. Both move a team's lead data in or out in bulk, which is
 *   a different kind of act from working a lead: an import can add thousands of rows, and an export
 *   takes the team's whole list — including contact details — out of the platform (see
 *   docs/compliance.md).
 *
 * Role comes from the `team_user` pivot via {@see User::roleOn()}.
 */
final class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        return $user->belongsToTeam($lead->team);
    }

    public function work(User $user, Lead $lead): bool
    {
        $role = $user->roleOn($lead->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }

    public function create(User $user, Lead $lead): bool
    {
        return $this->work($user, $lead);
    }

    public function import(User $user, Lead $lead): bool
    {
        return $this->isAtLeastAdmin($user, $lead);
    }

    public function export(User $user, Lead $lead): bool
    {
        return $this->isAtLeastAdmin($user, $lead);
    }

    private function isAtLeastAdmin(User $user, Lead $lead): bool
    {
        $role = $user->roleOn($lead->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
