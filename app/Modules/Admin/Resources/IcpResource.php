<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\ICP\Models\Icp;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's definition of who it wants to sell to. Name and description are editable; the weighted
 * criteria are their own resource.
 *
 * **Creatable**, which in the registry is a deliberate and rare thing: it is permitted only where
 * every column the table requires is a field the descriptor declares. Here that is `name` alone —
 * everything else is nullable or defaulted — so a row created through the generic writer is a valid
 * row. Where that is not true (`lead-tags` needs a derived slug, `pipeline-stages` needs a position)
 * create stays off and the workspace's own flow is the way in.
 */
final class IcpResource extends AdminResource
{
    public function model(): string
    {
        return Icp::class;
    }

    public function key(): string
    {
        return 'icps';
    }

    public function label(): string
    {
        return 'Ideal customer profiles';
    }

    public function singular(): string
    {
        return 'Ideal customer profile';
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
            ResourceColumn::text('name', 'Name'),
            ResourceColumn::text('is_active', 'Active'),
            ResourceColumn::date('created_at', 'Created'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('description', 'Description'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
            ResourceField::textarea('description', 'Description'),
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
            'created_at',
        ];
    }

    public function allowsCreate(): bool
    {
        return true;
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
