<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Core\Enums\Capability;

/**
 * Every exception to what a plan includes, across tenants (Phase 19b, ADR 0015).
 *
 * The cross-tenant view is what this is for: "which customers have Signals on a plan that does not
 * include it, and when does each of those expire?" is a question a pricing conversation starts
 * with, and it cannot be answered a team at a time.
 *
 * Read-only — the write path is the team's own billing screen, where the reason and the expiry are
 * required fields rather than optional columns.
 */
final class CapabilityOverrideResource extends AdminResource
{
    public function model(): string
    {
        return CapabilityOverride::class;
    }

    public function key(): string
    {
        return 'capability-overrides';
    }

    public function label(): string
    {
        return 'Capability overrides';
    }

    public function singular(): string
    {
        return 'Capability override';
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
            ResourceColumn::text('capability', 'Capability'),
            ResourceColumn::text('enabled', 'On'),
            ResourceColumn::date('expires_at', 'Expires'),
            ResourceColumn::date('created_at', 'Set'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('reason', 'Why'),
            ResourceColumn::count('set_by', 'Set by'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('capability', 'Capability', Capability::class),
            ResourceFilter::boolean('enabled', 'On'),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return ['reason'];
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
        return ['id', 'expires_at', 'created_at'];
    }
}
