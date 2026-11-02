<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;

/**
 * Logged approaches, including the ones made off-platform. Read-only — it is the tenant's own record of who they spoke to and when.
 */
final class ContactAttemptResource extends AdminResource
{
    public function model(): string
    {
        return ContactAttempt::class;
    }

    public function key(): string
    {
        return 'contact-attempts';
    }

    public function label(): string
    {
        return 'Contact attempts';
    }

    public function singular(): string
    {
        return 'Contact attempt';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Outreach;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::count('lead_id', 'Lead'),
            ResourceColumn::text('channel', 'Channel'),
            ResourceColumn::text('outcome', 'Outcome'),
            ResourceColumn::date('occurred_at', 'When'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('notes', 'Notes'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('outcome', 'Outcome', ContactOutcome::class),
            ResourceFilter::enum('channel', 'Channel', OutreachChannel::class),
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
            'occurred_at',
        ];
    }
}
