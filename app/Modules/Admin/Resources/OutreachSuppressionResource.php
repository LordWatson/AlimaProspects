<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;

/**
 * The do-not-contact list. **Read-only here, and that is a compliance requirement rather
 * than caution**: an entry with reason `erasure` or `opt_out` cannot be lifted
 * (`SuppressionReason::isLiftable()`), and a generic delete would be a way to un-opt-out
 * somebody who asked us to stop. Lifting a liftable entry is Outreach's own operation.
 *
 * The rows hold a one-way hash and a mask, never the address — so even an operator
 * reading this table does not read the addresses on it.
 */
final class OutreachSuppressionResource extends AdminResource
{
    public function model(): string
    {
        return OutreachSuppression::class;
    }

    public function key(): string
    {
        return 'outreach-suppressions';
    }

    public function label(): string
    {
        return 'Suppressions';
    }

    public function singular(): string
    {
        return 'Suppression';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Outreach;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('scope', 'Scope'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('value_preview', 'Value'),
            ResourceColumn::text('reason', 'Reason'),
            ResourceColumn::date('suppressed_at', 'Suppressed'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('note', 'Note'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('reason', 'Reason', SuppressionReason::class),
            ResourceFilter::enum('scope', 'Scope', SuppressionScope::class),
            ResourceFilter::enum('type', 'Type', SuppressionType::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'value_preview',
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
            'suppressed_at',
        ];
    }
}
