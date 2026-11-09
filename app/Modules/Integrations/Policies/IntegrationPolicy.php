<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Policies;

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Integrations\Models\Integration;

/**
 * Who may connect, configure or disconnect a CRM (PL7, ADR 0006).
 *
 * Owner or Admin, for the reason {@see WebhookEndpointPolicy} is: connecting a CRM authorises Alima
 * to write into a system the business runs on, and the auto-sync rule turns that into something
 * that happens unattended.
 *
 * **`sync` is the one thing an ordinary member may do.** Pushing one company a person is looking at
 * is working a lead, not configuring an integration, and restricting it would make the connection
 * useless to the people the product is for. Everything that makes a push safe is in the Action and
 * applies whoever asked.
 *
 * **`connect` is separate from `viewAny` and separate from `manage`.** There is no row to own yet,
 * so it cannot take an `Integration` — and authorising the connect routes on `viewAny` (which only
 * asks whether the caller has a team at all) let any member connect or *replace* the team's CRM,
 * point the auto-sync target at an account of their own, and trigger the account-changed link wipe
 * in `ConnectIntegrationAction`. Found by review; the callback had no check at all.
 */
final class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeamId() !== null;
    }

    /**
     * Start, or re-start, an OAuth connection. Class-level: there is no row yet.
     */
    public function connect(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function manage(User $user, Integration $integration): bool
    {
        return $this->owns($user, $integration) && $this->isAdmin($user);
    }

    public function sync(User $user, Integration $integration): bool
    {
        return $this->owns($user, $integration);
    }

    private function owns(User $user, Integration $integration): bool
    {
        return (int) $user->currentTeamId() === (int) $integration->team_id;
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
