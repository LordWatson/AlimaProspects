<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Core\Enums\Market;

/**
 * Each tenant's legitimate-interests assessment — the legal basis on which they are allowed
 * to source leads at all. Read-only without qualification: it is the tenant's own legal
 * record, signed by a named person on a date, and an operator editing it would be forging
 * somebody else's compliance paperwork. The `review_due_at` column is what a support
 * conversation about “why has discovery stopped?” usually turns on.
 */
final class LiaRecordResource extends AdminResource
{
    public function model(): string
    {
        return LiaRecord::class;
    }

    public function key(): string
    {
        return 'lia-records';
    }

    public function label(): string
    {
        return 'Lawful basis records';
    }

    public function singular(): string
    {
        return 'Lawful basis record';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Compliance;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('market', 'Market'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::count('version', 'Version'),
            ResourceColumn::date('completed_at', 'Completed'),
            ResourceColumn::date('review_due_at', 'Review due'),
            ResourceColumn::date('withdrawn_at', 'Withdrawn'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('purpose', 'Purpose'),
            ResourceColumn::text('necessity', 'Necessity'),
            ResourceColumn::text('balancing', 'Balancing'),
            ResourceColumn::text('safeguards', 'Safeguards'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', LiaStatus::class),
            ResourceFilter::enum('market', 'Market', Market::class),
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
            'review_due_at',
        ];
    }
}
