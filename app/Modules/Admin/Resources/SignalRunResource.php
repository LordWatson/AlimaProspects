<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Models\SignalRun;

/**
 * **The view deferred from Signals phase S6 to here** (`signals-plan.md` task 6,
 * `signals.md`): cost and failures per run, across tenants, read-only. Until this existed,
 * run cost was visible only per watch to the tenant that owned it, so “is the news
 * provider misbehaving for everyone or for one team?” had no answer.
 */
final class SignalRunResource extends AdminResource
{
    public function model(): string
    {
        return SignalRun::class;
    }

    public function key(): string
    {
        return 'signal-runs';
    }

    public function label(): string
    {
        return 'Signal runs';
    }

    public function singular(): string
    {
        return 'Signal run';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Signals;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::count('signal_watch_id', 'Watch'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::count('queries_count', 'Queries'),
            ResourceColumn::count('results_count', 'Results'),
            ResourceColumn::count('signals_created', 'Signals'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
            ResourceColumn::date('finished_at', 'Finished'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('error', 'Error'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', SignalRunStatus::class),
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
