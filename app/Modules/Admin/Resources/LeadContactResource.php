<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Models\LeadContact;

/**
 * Named people at a prospect company — the most sensitive rows in the product. Read-only
 * in the registry **by design**: a contact is erased through Compliance's
 * `EraseSubjectDataAction`, which also suppresses the identity so the next discovery run
 * does not re-source the person who asked to be forgotten. A generic delete here would
 * remove the row and quietly skip that, which is worse than doing nothing.
 */
final class LeadContactResource extends AdminResource
{
    public function model(): string
    {
        return LeadContact::class;
    }

    public function key(): string
    {
        return 'lead-contacts';
    }

    public function label(): string
    {
        return 'Lead contacts';
    }

    public function singular(): string
    {
        return 'Lead contact';
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
            ResourceColumn::text('title', 'Title'),
            ResourceColumn::text('email', 'Email'),
            ResourceColumn::text('email_verification_status', 'Email status'),
            ResourceColumn::text('source', 'Source'),
            ResourceColumn::date('collected_at', 'Collected'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('email_verification_status', 'Email status', EmailVerificationStatus::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
            'email',
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
            'collected_at',
        ];
    }
}
