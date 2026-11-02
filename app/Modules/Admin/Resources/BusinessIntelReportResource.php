<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;

/**
 * The AI background check each tenant gets at onboarding. Read-only; the status column is where a stuck onboarding usually turns out to be stuck.
 */
final class BusinessIntelReportResource extends AdminResource
{
    public function model(): string
    {
        return BusinessIntelReport::class;
    }

    public function key(): string
    {
        return 'business-intel-reports';
    }

    public function label(): string
    {
        return 'Background checks';
    }

    public function singular(): string
    {
        return 'Background check';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Intelligence;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('business_name', 'Business'),
            ResourceColumn::count('version', 'Version'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
            ResourceColumn::date('completed_at', 'Completed'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('error_message', 'Error'),
            ResourceColumn::text('website', 'Website'),
            ResourceColumn::date('accepted_at', 'Accepted'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', BackgroundCheckStatus::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'business_name',
            'website',
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
        ];
    }
}
