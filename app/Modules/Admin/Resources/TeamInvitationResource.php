<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Enums\TeamRole;

/**
 * Pending and accepted invitations. Read-only: an invitation's whole value is that the
 * person it names chose to accept it, and an operator editing the email on one would be
 * redirecting a grant of access to somebody who never asked for it. Resending and
 * revoking are Auth's own Actions, reachable from the team's page.
 */
final class TeamInvitationResource extends AdminResource
{
    public function model(): string
    {
        return TeamInvitation::class;
    }

    public function key(): string
    {
        return 'team-invitations';
    }

    public function label(): string
    {
        return 'Invitations';
    }

    public function singular(): string
    {
        return 'Invitation';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Tenants;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('email', 'Email'),
            ResourceColumn::text('role', 'Role'),
            ResourceColumn::date('accepted_at', 'Accepted'),
            ResourceColumn::date('expires_at', 'Expires'),
            ResourceColumn::date('created_at', 'Sent'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('role', 'Role', TeamRole::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'email',
        ];
    }

    /**
     * @return list<string>
     */
    public function with(): array
    {
        return [
            'team',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'created_at',
            'expires_at',
        ];
    }
}
