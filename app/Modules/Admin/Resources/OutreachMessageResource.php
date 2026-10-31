<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Models\OutreachMessage;

/**
 * Everything drafted or sent to a prospect. Read-only without exception: a sent message is
 * the tenant's accountability record for an approach somebody received, and an edited copy
 * of it would be a forgery of what we did on their behalf.
 */
final class OutreachMessageResource extends AdminResource
{
    public function model(): string
    {
        return OutreachMessage::class;
    }

    public function key(): string
    {
        return 'outreach-messages';
    }

    public function label(): string
    {
        return 'Outreach messages';
    }

    public function singular(): string
    {
        return 'Outreach message';
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
            ResourceColumn::text('channel', 'Channel'),
            ResourceColumn::text('status', 'Status'),
            ResourceColumn::text('sent_to', 'Sent to'),
            ResourceColumn::date('sent_at', 'Sent'),
            ResourceColumn::count('cost_micros', 'Cost (micros)'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('subject', 'Subject'),
            ResourceColumn::text('body', 'Body'),
            ResourceColumn::text('provider_message_id', 'Provider id'),
            ResourceColumn::text('error_message', 'Error'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('status', 'Status', OutreachMessageStatus::class),
            ResourceFilter::enum('channel', 'Channel', OutreachChannel::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'subject',
            'sent_to',
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
            'sent_at',
            'created_at',
        ];
    }
}
