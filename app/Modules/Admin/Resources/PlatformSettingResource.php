<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\AdminAbility;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Admin\Models\PlatformSetting;

/**
 * The operator overrides, as rows (Phase 19b).
 *
 * The settings **screen** is where a value is changed; this is where the question "what has
 * somebody deviated from config, and who?" is answered. They are different questions, and the
 * second one is the reason this is in the registry at all — the screen shows every declared key and
 * cannot distinguish a default from a default somebody chose.
 *
 * Read-only: a value has to be cast through its declared type, compared against the default, have
 * its cache busted and be audited with what it replaced. That is `PlatformSettings::set()`, and
 * routing it through the generic writer would skip every part of it.
 *
 * Behind `platform.write`, because the rows are the platform's switches rather than a tenant's data.
 */
final class PlatformSettingResource extends AdminResource
{
    public function model(): string
    {
        return PlatformSetting::class;
    }

    public function key(): string
    {
        return 'platform-settings';
    }

    public function label(): string
    {
        return 'Setting overrides';
    }

    public function singular(): string
    {
        return 'Setting override';
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
            ResourceColumn::text('key', 'Key'),
            ResourceColumn::text('value', 'Value'),
            ResourceColumn::count('updated_by', 'Changed by'),
            ResourceColumn::date('updated_at', 'Changed'),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return ['key'];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return ['id', 'key', 'updated_at'];
    }
}
