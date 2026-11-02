<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Database\Eloquent\Model;

/**
 * Follow-ups members set themselves. The title, due date and status are editable — a reminder stuck in the past is a common support ask and harmless to move.
 */
final class LeadTaskResource extends AdminResource
{
    public function model(): string
    {
        return LeadTask::class;
    }

    public function key(): string
    {
        return 'lead-tasks';
    }

    public function label(): string
    {
        return 'Lead tasks';
    }

    public function singular(): string
    {
        return 'Lead task';
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
            ResourceColumn::text('title', 'Title'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::date('due_at', 'Due'),
            ResourceColumn::date('completed_at', 'Completed'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('title', 'Title', ['required', 'string', 'max:255']),
            ResourceField::enum('status', 'Status', LeadTaskStatus::class, ['required']),
            ResourceField::date('due_at', 'Due'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', LeadTaskStatus::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'title',
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
            'due_at',
            'created_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('title');
    }
}
