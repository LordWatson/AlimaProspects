<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Admin\Models\Announcement;

/**
 * Everything we have ever told our tenants (Phase 19b).
 *
 * Read-only in the registry, and the write path is the announcements screen — because the title and
 * body are **per-locale maps** and the targeting is two optional lists, none of which a declarative
 * descriptor can render as a form field (`AdminResource`'s rule: descriptors return data).
 *
 * Behind `platform.write` rather than `tenant.read`: these rows are ours, not a tenant's, and the
 * record of what we have said publicly is not a support engineer's business.
 *
 * Retired announcements stay. "When did we say the product would be down?" is a question somebody
 * asks afterwards, which is why the retire Action sets `ends_at` instead of deleting.
 */
final class AnnouncementResource extends AdminResource
{
    public function model(): string
    {
        return Announcement::class;
    }

    public function key(): string
    {
        return 'announcements';
    }

    public function label(): string
    {
        return 'Announcements';
    }

    public function singular(): string
    {
        return 'Announcement';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Platform;
    }

    public function ability(): AdminAbility
    {
        return AdminAbility::ManagePlatformData;
    }

    public function isTenantOwned(): bool
    {
        return false;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('level', 'Level'),
            ResourceColumn::date('starts_at', 'From'),
            ResourceColumn::date('ends_at', 'Until'),
            ResourceColumn::text('is_dismissible', 'Dismissible'),
            ResourceColumn::text('notify', 'Notified'),
            ResourceColumn::date('notified_at', 'Notified at'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('title', 'Title (per locale)'),
            ResourceColumn::text('body', 'Body (per locale)'),
            ResourceColumn::text('plans', 'Plans targeted'),
            ResourceColumn::text('markets', 'Markets targeted'),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('level', 'Level', AnnouncementLevel::class),
            ResourceFilter::boolean('notify', 'Notified'),
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return ['id', 'starts_at', 'ends_at'];
    }
}
