<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's board columns. Editable, because a stage named by mistake is visible to the whole team and awkward for them to fix if the default flag landed on the wrong one.
 */
final class PipelineStageResource extends AdminResource
{
    public function model(): string
    {
        return PipelineStage::class;
    }

    public function key(): string
    {
        return 'pipeline-stages';
    }

    public function label(): string
    {
        return 'Pipeline stages';
    }

    public function singular(): string
    {
        return 'Pipeline stage';
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
            ResourceColumn::count('position', 'Position'),
            ResourceColumn::text('is_default', 'Default'),
            ResourceColumn::text('outcome', 'Outcome'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
            ResourceField::integer('position', 'Position', ['required', 'integer', 'min:0']),
            ResourceField::boolean('is_default', 'Default stage'),
            ResourceField::enum('outcome', 'Outcome', StageOutcome::class),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('outcome', 'Outcome', StageOutcome::class),
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
            'position',
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
