<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Services\OutreachGuard;

/**
 * Ownership double-check for drafted messages (ADR 0006).
 *
 * - **view** — any member of the owning team. A draft is the team's record of how they approached
 *   a company, and a colleague picking the lead up needs to read it.
 * - **update** / **send** — any member except Read-only, **not only the author**. A member who
 *   drafted something and then went on holiday should not be the only person who can send it, and
 *   the team is the controller either way. Matches `LeadPolicy::work`, which is the permission the
 *   lead screens already run on.
 * - **delete** — the author, or an Owner/Admin. Mirrors `LeadTaskPolicy::delete`.
 *
 * Note what has *no* policy: whether the message may actually go out. That is
 * {@see OutreachGuard}'s job, and it is a different question —
 * authorisation asks whether this member may act, the guard asks whether this lead may be
 * approached. Conflating them would mean a compliance rule enforced by a role check.
 */
final class OutreachMessagePolicy
{
    public function view(User $user, OutreachMessage $message): bool
    {
        return $user->belongsToTeam($message->team);
    }

    public function update(User $user, OutreachMessage $message): bool
    {
        $role = $user->roleOn($message->team);

        return $role !== null && $role !== TeamRole::ReadOnly;
    }

    public function send(User $user, OutreachMessage $message): bool
    {
        return $this->update($user, $message);
    }

    public function delete(User $user, OutreachMessage $message): bool
    {
        if (! $user->belongsToTeam($message->team)) {
            return false;
        }

        if ((int) $message->created_by === (int) $user->getKey()) {
            return true;
        }

        $role = $user->roleOn($message->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
