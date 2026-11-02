<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Database\Eloquent\Model;

/**
 * Members' saved filter sets. Delete is switched on here too: a shared view with a broken
 * filter shows the whole team an empty list, and the member who saved it may have left.
 */
final class SavedViewResource extends AdminResource
{
    public function model(): string
    {
        return SavedView::class;
    }

    public function key(): string
    {
        return 'saved-views';
    }

    public function label(): string
    {
        return 'Saved views';
    }

    public function singular(): string
    {
        return 'Saved view';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Workspace;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('name', 'Name'),
            ResourceColumn::count('user_id', 'Owner'),
            ResourceColumn::text('is_shared', 'Shared'),
            ResourceColumn::date('created_at', 'Created'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('filters', 'Filters'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
            ResourceField::boolean('is_shared', 'Shared with the team'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::boolean('is_shared', 'Shared'),
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

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function allowsDelete(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
