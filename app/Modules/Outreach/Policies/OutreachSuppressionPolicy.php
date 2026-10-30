<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Models\OutreachSuppression;

/**
 * Ownership double-check for do-not-contact entries (ADR 0006, docs/compliance.md §3.2).
 *
 * This is the one model in the module **without** a tenant global scope — a global entry has to be
 * visible to every team, or it would not bind them — so the policy is the whole of the tenancy
 * guarantee here rather than a double-check. It is written accordingly:
 *
 * - **view** — a global entry is visible to any member of any team (it is why their send was
 *   refused, and all they see is the masked preview); a team entry only to that team.
 * - **create** / **delete** — **Owner/Admin of that team**, and **team-scoped entries only**. A
 *   global opt-out cannot be created or lifted from the product at all: lifting one would let a
 *   tenant overrule a person's stated wish with a button.
 */
final class OutreachSuppressionPolicy
{
    public function view(User $user, OutreachSuppression $entry): bool
    {
        if ($entry->scope === SuppressionScope::Global) {
            // Any authenticated member. The preview is masked, so this discloses "someone with an
            // address at this domain opted out" and nothing more.
            return $user->currentTeamId() !== null;
        }

        return $this->isOwnTeamAdmin($user, $entry) || $this->isOwnTeamMember($user, $entry);
    }

    /**
     * Authorised against a transient, team-stamped entry, since there is no row yet.
     */
    public function create(User $user, OutreachSuppression $entry): bool
    {
        return $entry->scope === SuppressionScope::Team && $this->isOwnTeamAdmin($user, $entry);
    }

    public function delete(User $user, OutreachSuppression $entry): bool
    {
        // Three separate places refuse to lift a global opt-out — the model, the list service and
        // here. That is deliberate: it is the single most consequential thing in the module to get
        // wrong, and no one layer should be the only thing standing in the way.
        return $entry->isRemovable() && $this->isOwnTeamAdmin($user, $entry);
    }

    private function isOwnTeamMember(User $user, OutreachSuppression $entry): bool
    {
        return $entry->team_id !== null && (int) $user->currentTeamId() === (int) $entry->team_id;
    }

    private function isOwnTeamAdmin(User $user, OutreachSuppression $entry): bool
    {
        $team = $entry->team;

        if ($team === null || ! $user->belongsToTeam($team)) {
            return false;
        }

        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
