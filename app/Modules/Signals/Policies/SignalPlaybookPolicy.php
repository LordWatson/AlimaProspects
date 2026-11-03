<?php

declare(strict_types=1);

namespace App\Modules\Signals\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Signals\Models\SignalPlaybook;

/**
 * Ownership double-check for playbooks (ADR 0006).
 *
 * The same shape as {@see SignalWatchPolicy} and {@see SignalWatchlistPolicy} — any member may see
 * what the team automates, and Owners, Admins and Members may change it — with one deliberate
 * difference, which is `preview`.
 *
 * **Previewing is a read.** It evaluates stored signals, executes nothing and spends nothing, so a
 * Read-only member may ask "what would this rule do?" — which is exactly the question somebody
 * reviewing a colleague's rule wants to answer, and refusing it would make the safest person on the
 * team the one who cannot check the riskiest feature. Every other ability here is a write, because
 * every one of them changes what the product does to the team's pipeline unattended.
 */
final class SignalPlaybookPolicy
{
    public function view(User $user, SignalPlaybook $playbook): bool
    {
        return $user->belongsToTeam($playbook->team);
    }

    public function viewAny(User $user, SignalPlaybook $playbook): bool
    {
        return $user->belongsToTeam($playbook->team);
    }

    public function preview(User $user, SignalPlaybook $playbook): bool
    {
        return $user->belongsToTeam($playbook->team);
    }

    public function create(User $user, SignalPlaybook $playbook): bool
    {
        return $this->canManage($user, $playbook);
    }

    public function update(User $user, SignalPlaybook $playbook): bool
    {
        return $this->canManage($user, $playbook);
    }

    public function delete(User $user, SignalPlaybook $playbook): bool
    {
        return $this->canManage($user, $playbook);
    }

    private function canManage(User $user, SignalPlaybook $playbook): bool
    {
        $role = $user->roleOn($playbook->team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin, TeamRole::Member], strict: true);
    }
}
