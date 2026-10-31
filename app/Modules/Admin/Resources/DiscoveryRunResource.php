<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;

/**
 * Every lead search the platform has run, with what it cost. Read-only: a run is a record
 * of something that happened, and editing one would be editing history.
 *
 * `cost_micros` is declared as a count rather than money on purpose — micro-units are not
 * minor units, and dressing them up with a currency symbol would show a figure a thousand
 * times too small.
 */
final class DiscoveryRunResource extends AdminResource
{
    public function model(): string
    {
        return DiscoveryRun::class;
    }

    public function key(): string
    {
        return 'discovery-runs';
    }

    public function label(): string
    {
        return 'Discovery runs';
    }

    public function singular(): string
    {
        return 'Discovery run';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Prospecting;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('source', 'Source'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::count('requested_limit', 'Asked for'),
            ResourceColumn::count('leads_found', 'Found'),
            ResourceColumn::count('leads_created', 'Created'),
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
            ResourceColumn::text('params', 'Parameters'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', DiscoveryRunStatus::class),
            ResourceFilter::enum('source', 'Source', LeadSource::class),
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
            'cost_micros',
        ];
    }
}
