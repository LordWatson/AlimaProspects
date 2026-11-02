<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;

/**
 * Trigger events found in the news, several of which name a real person. Read-only, and
 * for the same reason as lead contacts: a signal is erased through the Signals module's
 * own exporter, which knows not to tear the provenance out from under a converted lead.
 */
final class SignalResource extends AdminResource
{
    public function model(): string
    {
        return Signal::class;
    }

    public function key(): string
    {
        return 'signals';
    }

    public function label(): string
    {
        return 'Signals';
    }

    public function singular(): string
    {
        return 'Signal';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Signals;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('event_type', 'Event'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::text('subject_company_name', 'Company'),
            ResourceColumn::text('source_name', 'Source'),
            ResourceColumn::date('published_at', 'Published'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('headline', 'Headline'),
            ResourceColumn::text('summary', 'Summary'),
            ResourceColumn::text('subject_person_name', 'Person'),
            ResourceColumn::text('subject_person_role', 'Role'),
            ResourceColumn::money('amount_minor', 'Amount', 'currency'),
            ResourceColumn::text('source_url', 'Source URL'),
            ResourceColumn::text('dismiss_reason', 'Dismiss reason'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', SignalStatus::class),
            ResourceFilter::enum('event_type', 'Event type', SignalEventType::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'headline',
            'subject_company_name',
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
            'published_at',
            'created_at',
        ];
    }
}
