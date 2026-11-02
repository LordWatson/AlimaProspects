<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Notifications\Models\Notification;

/**
 * What the product told members. Read-only.
 *
 * Two things about this one are unusual. It is tenant data by **column** rather than by
 * trait — the model deliberately has no global scope, so `isTenantOwned()` is overridden
 * rather than derived. And its key is a UUID, so the default sort is `created_at`:
 * ordering by a v4 identifier is ordering by randomness.
 */
final class NotificationResource extends AdminResource
{
    public function model(): string
    {
        return Notification::class;
    }

    public function key(): string
    {
        return 'notifications';
    }

    public function label(): string
    {
        return 'Notifications';
    }

    public function singular(): string
    {
        return 'Notification';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Engagement;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('team.name', 'Team'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('notifiable_id', 'Member'),
            ResourceColumn::date('read_at', 'Read'),
            ResourceColumn::date('created_at', 'Raised'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('data', 'Payload'),
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
            'created_at',
            'read_at',
        ];
    }

    public function defaultSort(): string
    {
        return 'created_at';
    }

    public function isTenantOwned(): bool
    {
        return true;
    }
}
