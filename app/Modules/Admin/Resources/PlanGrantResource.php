<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\PlanGrant;

/**
 * Every plan an operator has granted, across tenants (Phase 19b).
 *
 * **Read-only here, and the write path is the team's own billing screen** — which is the right
 * shape for this one: granting a plan means choosing a window and bespoke allowances *for a
 * particular tenant*, and a cross-tenant table is where you go to answer the other question, "who
 * is on a plan nobody is paying Stripe for, and when does it end?".
 *
 * Revoked grants stay listed. The row is the record of what a tenant was entitled to and when, and
 * a filter on it is how an operator answers "why was this team on Scale in March?".
 */
final class PlanGrantResource extends AdminResource
{
    public function model(): string
    {
        return PlanGrant::class;
    }

    public function key(): string
    {
        return 'plan-grants';
    }

    public function label(): string
    {
        return 'Plan grants';
    }

    public function singular(): string
    {
        return 'Plan grant';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Billing;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('plan', 'Plan'),
            ResourceColumn::count('seats', 'Seats'),
            ResourceColumn::count('leads', 'Leads'),
            ResourceColumn::count('credits', 'Credits'),
            ResourceColumn::date('starts_at', 'From'),
            ResourceColumn::date('ends_at', 'Until'),
            ResourceColumn::date('revoked_at', 'Revoked'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('note', 'Why'),
            ResourceColumn::count('granted_by', 'Granted by'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('plan', 'Plan', PlanKey::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return ['note'];
    }

    /**
     * @return list<string>
     */
    public function with(): array
    {
        return ['team'];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return ['id', 'starts_at', 'ends_at'];
    }
}
