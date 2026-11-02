<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\IcpCriterion;

/**
 * The weighted rows behind a profile. Editable weight and value, because the commonest
 * onboarding failure is a weight somebody typed as 100 meaning “everything” — which
 * drowns every other criterion and produces scores nobody can explain.
 */
final class IcpCriterionResource extends AdminResource
{
    public function model(): string
    {
        return IcpCriterion::class;
    }

    public function key(): string
    {
        return 'icp-criteria';
    }

    public function label(): string
    {
        return 'ICP criteria';
    }

    public function singular(): string
    {
        return 'ICP criterion';
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
            ResourceColumn::text('icp.name', 'Profile'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('value', 'Value'),
            ResourceColumn::count('weight', 'Weight'),
            ResourceColumn::count('position', 'Position'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('value', 'Value', ['required', 'string', 'max:255']),
            ResourceField::integer('weight', 'Weight', ['required', 'integer', 'min:0', 'max:100']),
            ResourceField::integer('position', 'Position', ['required', 'integer', 'min:0']),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('type', 'Type', CriterionType::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'value',
        ];
    }

    /**
     * @return list<string>
     */
    public function with(): array
    {
        return [
            'icp',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'weight',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }
}
