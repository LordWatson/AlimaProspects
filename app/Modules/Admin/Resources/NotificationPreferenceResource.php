<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;

/**
 * Per-member, per-team channel choices. Read-only: these are somebody's own consent decisions about being contacted, and an operator flipping one on their behalf is the opposite of a preference.
 */
final class NotificationPreferenceResource extends AdminResource
{
    public function model(): string
    {
        return NotificationPreference::class;
    }

    public function key(): string
    {
        return 'notification-preferences';
    }

    public function label(): string
    {
        return 'Notification preferences';
    }

    public function singular(): string
    {
        return 'Notification preference';
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
            ResourceColumn::count('user_id', 'Member'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('in_app', 'In app'),
            ResourceColumn::text('mail', 'Email'),
            ResourceColumn::text('push', 'Push'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('type', 'Type', NotificationType::class),
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
        ];
    }
}
