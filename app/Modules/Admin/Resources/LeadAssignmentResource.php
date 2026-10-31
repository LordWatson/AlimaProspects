<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;

/**
 * Who owns which prospect. Read-only: reassignment is the workspace's own operation, and it writes the activity row this table's readers rely on.
 */
final class LeadAssignmentResource extends AdminResource
{
    public function model(): string
    {
        return LeadAssignment::class;
    }

    public function key(): string
    {
        return 'lead-assignments';
    }

    public function label(): string
    {
        return 'Lead assignments';
    }

    public function singular(): string
    {
        return 'Lead assignment';
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
            ResourceColumn::count('lead_id', 'Lead'),
            ResourceColumn::count('user_id', 'Assignee'),
            ResourceColumn::count('assigned_by', 'Assigned by'),
            ResourceColumn::date('assigned_at', 'When'),
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
            'assigned_at',
        ];
    }
}
