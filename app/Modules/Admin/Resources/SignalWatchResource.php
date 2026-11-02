<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's standing trigger-event searches (ADR 0011). `is_active` and the cadence are
 * editable, which is the lever for a watch that is burning a team's allowance faster than
 * it is finding anything — switching it off beats suspending the whole tenant.
 */
final class SignalWatchResource extends AdminResource
{
    public function model(): string
    {
        return SignalWatch::class;
    }

    public function key(): string
    {
        return 'signal-watches';
    }

    public function label(): string
    {
        return 'Signal watches';
    }

    public function singular(): string
    {
        return 'Signal watch';
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
            ResourceColumn::text('name', 'Name'),
            ResourceColumn::text('market', 'Market'),
            ResourceColumn::text('cadence', 'Cadence'),
            ResourceColumn::text('is_active', 'Active'),
            ResourceColumn::date('last_run_at', 'Last run'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            // The watched types are **not** here any more. Since S7 they are rows in
            // `signal_watch_definition` (ADR 0018 section 5), and the registry's columns read a
            // column or an eager-loaded `relation.attribute` — neither of which is a count over a
            // pivot. Teaching the registry `withCount` for one read-only detail panel is more
            // machinery than the answer is worth, and `/admin/signals` already shows each signal's
            // own `event_key`, which is the question an operator actually asks.
            ResourceColumn::text('ignore_domains', 'Ignored domains'),
            // `locations` and `regions` are JSON lists since S8 (ADR 0019), which the registry's
            // text column renders as the raw array. That is honest and readable for an operator
            // answering "where is this watch looking" - the alternative would be a formatter for
            // one read-only detail panel. `market` stays in the index columns above, where it means
            // the team's declared compliance regime rather than where the watch looks.
            ResourceColumn::text('locations', 'Places'),
            ResourceColumn::text('regions', 'Regions'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
            ResourceField::enum('cadence', 'Cadence', SignalWatchCadence::class, ['required']),
            ResourceField::boolean('is_active', 'Active'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::boolean('is_active', 'Active'),
            ResourceFilter::enum('cadence', 'Cadence', SignalWatchCadence::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
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
            'name',
            'last_run_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
