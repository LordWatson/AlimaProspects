<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Notifications\Enums\PushPlatform;
use App\Modules\Notifications\Models\PushDevice;

/**
 * Registered handsets. **The third resource where delete is switched on**, and the one it
 * was switched on for: a stale token is a push that silently fails forever, and a handset
 * with no signal cannot always deregister itself at sign-out. No `team_id` — a device
 * belongs to a person, not to one of their teams.
 */
final class PushDeviceResource extends AdminResource
{
    public function model(): string
    {
        return PushDevice::class;
    }

    public function key(): string
    {
        return 'push-devices';
    }

    public function label(): string
    {
        return 'Push devices';
    }

    public function singular(): string
    {
        return 'Push device';
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
            ResourceColumn::count('user_id', 'Member'),
            ResourceColumn::text('platform', 'Platform'),
            ResourceColumn::date('last_used_at', 'Last used'),
            ResourceColumn::date('created_at', 'Registered'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('platform', 'Platform', PushPlatform::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'last_used_at',
        ];
    }

    public function allowsDelete(): bool
    {
        return true;
    }
}
