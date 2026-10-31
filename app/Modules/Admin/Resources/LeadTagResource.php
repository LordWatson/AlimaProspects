<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own labels. **One of the three resources where generic delete is switched
 * on**, because a tag is a lookup row whose only dependents are pivot rows that cascade,
 * and a typo'd tag applied to two hundred leads is otherwise a tenant's afternoon.
 *
 * **Create is off**, and not by oversight: uniqueness is judged on `slug`, which is derived from the
 * name by `LeadTag::normalise()` — and deriving a column is logic, which a declarative descriptor
 * must not grow. Tags are created on first use in the workspace anyway, so there is nothing an
 * operator needs this for.
 */
final class LeadTagResource extends AdminResource
{
    public function model(): string
    {
        return LeadTag::class;
    }

    public function key(): string
    {
        return 'lead-tags';
    }

    public function label(): string
    {
        return 'Lead tags';
    }

    public function singular(): string
    {
        return 'Lead tag';
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
            ResourceColumn::text('slug', 'Slug'),
            ResourceColumn::date('created_at', 'Created'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
            'slug',
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
