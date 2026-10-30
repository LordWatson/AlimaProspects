<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Outreach\Models\OutreachTemplate;

/**
 * Ownership double-check for message briefs (ADR 0006). The global scope hides other teams' rows,
 * but a global scope is not an authorisation check — this is.
 *
 * - **view** — any member of the owning team, Read-only included: a brief is part of understanding
 *   how the team talks to prospects.
 * - **manage** (create, update, delete) — **Owner/Admin**. A brief is not one member's draft; it
 *   shapes every message the team sends afterwards, and the compliance posture of a team's
 *   outreach is set here as much as anywhere. Deliberately stricter than `LeadPolicy::work`, which
 *   lets any member write their own message.
 */
final class OutreachTemplatePolicy
{
    public function view(User $user, OutreachTemplate $template): bool
    {
        return $user->belongsToTeam($template->team);
    }

    public function update(User $user, OutreachTemplate $template): bool
    {
        return $this->isAtLeastAdmin($user, $template);
    }

    public function delete(User $user, OutreachTemplate $template): bool
    {
        return $this->isAtLeastAdmin($user, $template);
    }

    /**
     * Authorised against a transient, team-stamped template, since there is no row yet.
     */
    public function create(User $user, OutreachTemplate $template): bool
    {
        return $this->isAtLeastAdmin($user, $template);
    }

    private function isAtLeastAdmin(User $user, OutreachTemplate $template): bool
    {
        if (! $user->belongsToTeam($template->team)) {
            return false;
        }

        $role = $user->roleOn($template->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
