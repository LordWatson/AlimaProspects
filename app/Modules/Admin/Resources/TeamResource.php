<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\ResourceGroup;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant itself, and the row every other resource's `team_id` points at.
 *
 * Editable here because a support request to fix a misspelled company name or a wrong
 * timezone should not need a deploy — and because the four fields below are the ones with
 * no behaviour hanging off them beyond display and date arithmetic. **Suspension is not
 * one of them**: it goes through `SuspendTeamAction`, which records who and why, and a
 * `suspended_at` the registry could set would be a suspension with neither.
 *
 * No create and no delete. Provisioning a tenant is Phase 19b's `ProvisionTeamAction`
 * (a team, an owner, a plan and an invitation, in one transaction); deleting one is a
 * cascade across forty tables that belongs to a considered erasure flow, not a button.
 */
final class TeamResource extends AdminResource
{
    public function model(): string
    {
        return Team::class;
    }

    public function key(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return 'Teams';
    }

    public function singular(): string
    {
        return 'Team';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Tenants;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('name', 'Name'),
            ResourceColumn::text('market', 'Market'),
            ResourceColumn::text('timezone', 'Timezone'),
            ResourceColumn::text('locale', 'Locale'),
            ResourceColumn::date('suspended_at', 'Suspended'),
            ResourceColumn::date('trial_ends_at', 'Trial ends'),
            ResourceColumn::date('created_at', 'Created'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('suspension_reason', 'Suspension reason'),
            ResourceColumn::text('stripe_id', 'Stripe customer'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
            ResourceField::enum('market', 'Market', Market::class, ['required']),
            ResourceField::text('timezone', 'Timezone', ['required', 'string', 'timezone']),
            ResourceField::enum('locale', 'Locale', SupportedLocale::class, ['required']),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('market', 'Market', Market::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
            'stripe_id',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'name',
            'created_at',
            'suspended_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
