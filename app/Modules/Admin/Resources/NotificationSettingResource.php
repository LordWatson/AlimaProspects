<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Models\NotificationSetting;

/**
 * Digest frequency, hour and mutes. Read-only for the same reason as the preferences beside it.
 */
final class NotificationSettingResource extends AdminResource
{
    public function model(): string
    {
        return NotificationSetting::class;
    }

    public function key(): string
    {
        return 'notification-settings';
    }

    public function label(): string
    {
        return 'Digest settings';
    }

    public function singular(): string
    {
        return 'Digest setting';
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
            ResourceColumn::text('digest', 'Digest'),
            ResourceColumn::count('digest_hour', 'Hour'),
            ResourceColumn::date('digest_sent_at', 'Last sent'),
            ResourceColumn::date('muted_until', 'Muted until'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('digest', 'Digest', DigestFrequency::class),
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
