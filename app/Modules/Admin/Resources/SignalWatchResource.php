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
            ResourceColumn::text('event_types', 'Event types'),
            ResourceColumn::text('ignore_domains', 'Ignored domains'),
            ResourceColumn::text('location', 'Location'),
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
