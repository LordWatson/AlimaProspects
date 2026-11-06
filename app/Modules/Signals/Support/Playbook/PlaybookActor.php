<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Models\SignalPlaybook;

/**
 * Whose authority an unattended rule acts under (S14).
 *
 * A playbook runs with nobody at the keyboard, and every module Action it calls wants a `User`: the
 * assignment writes who assigned, the stage move writes who moved it, the task writes who created
 * it, the audit log writes an actor. So a rule needs a member, and the honest answer is **the
 * person who built it** — the chain does what they instructed, and a lead's history reading "Dana
 * moved this to Engaged" with the run log as the explanation is true.
 *
 * The alternative was a system user, and it was rejected deliberately. A synthetic actor makes
 * every entry it writes unattributable, which is the opposite of what an audit trail is for, and it
 * would need a row in `users` that can sign in to nothing — a credential-shaped hole kept for
 * bookkeeping.
 *
 * Two fallbacks, in order, and then a refusal:
 *
 * 1. The creator, **if they can still act for this team**. Membership alone is not enough, and
 *    that was a defect found in review: a member demoted to Read-only would have gone on
 *    converting, assigning and drafting for ever, because the module Actions take an actor and do
 *    not consult a policy. So the role is checked against the same set `SignalPlaybookPolicy`
 *    manages with — somebody who may no longer change a rule may no longer have it act as them.
 * 2. The team's **owner**, lowest id first for stability. A rule somebody built before they left is
 *    still the team's rule, and switching it off because of a leaver would be the product deciding
 *    to stop working.
 * 3. Null — and the executor records a refusal on the run saying so, rather than running as
 *    nobody.
 */
final class PlaybookActor
{
    /**
     * The member this rule acts as, or null when the team has nobody to stand in.
     *
     * Reads inside the caller's team context. `created_by` is `nullOnDelete`, so a deleted user
     * arrives here as null and falls straight through to the owner.
     */
    public function for(Team $team, SignalPlaybook $playbook): ?User
    {
        $creatorId = $playbook->created_by;

        if ($creatorId !== null) {
            /** @var User|null $creator */
            $creator = $team->users()->where('users.id', $creatorId)->first();

            if ($creator instanceof User && $this->mayAct($creator, $team)) {
                return $creator;
            }
        }

        /** @var User|null $owner */
        $owner = $team->users()
            ->wherePivot('role', TeamRole::Owner->value)
            ->orderBy('users.id')
            ->first();

        return $owner;
    }

    /**
     * Whether this member could have performed the chain's operations by hand.
     */
    private function mayAct(User $user, Team $team): bool
    {
        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
