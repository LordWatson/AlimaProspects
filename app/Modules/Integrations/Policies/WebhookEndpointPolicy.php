<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Integrations\Models\WebhookEndpoint;

/**
 * Who may configure where a team's data is sent (PL7, ADR 0006).
 *
 * **Owner or Admin for everything, including viewing**, which is stricter than most policies here
 * and deliberately so. An endpoint is an instruction to send the team's whole lead flow — companies,
 * revealed contact details, trigger events — to a URL somebody chose. That is the same kind of
 * decision as the do-not-contact list rather than the same kind as a saved view, and the list of
 * endpoints is itself worth withholding: a member who can read it knows exactly which third party
 * the team's data is flowing to, and can confirm it by watching the delivery log.
 *
 * There is no `update` separate from `create`: an endpoint's URL is its whole identity, and
 * somebody who may repoint one may as well have made it.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->owns($user, $endpoint) && $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->view($user, $endpoint);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->view($user, $endpoint);
    }

    private function owns(User $user, WebhookEndpoint $endpoint): bool
    {
        return (int) $user->currentTeamId() === (int) $endpoint->team_id;
    }

    private function isAdmin(User $user): bool
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return false;
        }

        $role = $user->roleOn($team);

        return $role !== null && in_array($role, [TeamRole::Owner, TeamRole::Admin], strict: true);
    }
}
