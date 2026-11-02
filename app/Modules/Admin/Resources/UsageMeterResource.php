<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Models\UsageMeter;

/**
 * What each team used against what their plan included, per metric per period. Read-only,
 * and the reason is ADR 0012: `usage_meters` is a **record over** the `ai_requests` and
 * `discovery_runs` ledgers, not a counter the app increments. Editing a row would not
 * change a team's allowance — the next sync would recompute it — it would only make the
 * panel disagree with the ledger for an hour.
 */
final class UsageMeterResource extends AdminResource
{
    public function model(): string
    {
        return UsageMeter::class;
    }

    public function key(): string
    {
        return 'usage-meters';
    }

    public function label(): string
    {
        return 'Usage meters';
    }

    public function singular(): string
    {
        return 'Usage meter';
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
            ResourceColumn::text('metric', 'Metric'),
            ResourceColumn::date('period_start', 'Period from'),
            ResourceColumn::count('used', 'Used'),
            ResourceColumn::count('included', 'Included'),
            ResourceColumn::count('reported', 'Reported'),
            ResourceColumn::date('synced_at', 'Synced'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('metric', 'Metric', BillingMetric::class),
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
            'period_start',
        ];
    }
}
