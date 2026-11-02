<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Compliance\Models\RetentionPolicy;

/**
 * **Part of the operator view over compliance activity deferred to Phase 19**
 * (`compliance-privacy.md`), and read-only on purpose. A retention policy decides when
 * somebody else's data is deleted; an operator shortening one would delete a tenant's
 * prospects on a schedule the tenant never agreed to, and lengthening one would keep
 * personal data past the period they committed to. The sweep columns are the useful part.
 */
final class RetentionPolicyResource extends AdminResource
{
    public function model(): string
    {
        return RetentionPolicy::class;
    }

    public function key(): string
    {
        return 'retention-policies';
    }

    public function label(): string
    {
        return 'Retention policies';
    }

    public function singular(): string
    {
        return 'Retention policy';
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
            ResourceColumn::text('is_enabled', 'Enabled'),
            ResourceColumn::count('uncontacted_lead_days', 'Uncontacted (days)'),
            ResourceColumn::count('contacted_lead_days', 'Contacted (days)'),
            ResourceColumn::date('last_swept_at', 'Last swept'),
            ResourceColumn::count('last_deleted_count', 'Deleted'),
            ResourceColumn::count('last_anonymised_count', 'Anonymised'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::boolean('is_enabled', 'Enabled'),
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
            'last_swept_at',
        ];
    }
}
