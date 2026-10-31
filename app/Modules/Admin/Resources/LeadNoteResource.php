<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\LeadsWorkspace\Models\LeadNote;

/**
 * What members wrote about a prospect. Read-only: it is their text, and an operator editing it would put words in a colleague's mouth on a record their whole team reads.
 */
final class LeadNoteResource extends AdminResource
{
    public function model(): string
    {
        return LeadNote::class;
    }

    public function key(): string
    {
        return 'lead-notes';
    }

    public function label(): string
    {
        return 'Lead notes';
    }

    public function singular(): string
    {
        return 'Lead note';
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
            ResourceColumn::count('user_id', 'Author'),
            ResourceColumn::date('created_at', 'Written'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('body', 'Body'),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'body',
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
        ];
    }
}
