<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;

/**
 * The per-lead timeline. Append-only in the product and read-only here, which is what makes it worth trusting when a tenant asks who moved something.
 */
final class LeadActivityResource extends AdminResource
{
    public function model(): string
    {
        return LeadActivity::class;
    }

    public function key(): string
    {
        return 'lead-activities';
    }

    public function label(): string
    {
        return 'Lead activity';
    }

    public function singular(): string
    {
        return 'Lead activity';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Workspace;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::count('lead_id', 'Lead'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::count('user_id', 'Member'),
            ResourceColumn::date('occurred_at', 'When'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('properties', 'Properties'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('type', 'Type', LeadActivityType::class),
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
            'occurred_at',
        ];
    }
}
