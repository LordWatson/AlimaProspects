<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Enums\ResourceGroup;
use Laravel\Cashier\Subscription;

/**
 * Cashier's subscription rows, keyed on `team_id` because the tenant is the billable party
 * (ADR 0006, ADR 0012). Read-only here: Stripe is the system of record, and a
 * `stripe_status` edited in our database would make the app believe something Stripe does
 * not. Plan changes go through `SwapPlanAction`, which talks to Stripe and lets the
 * webhook write the row back.
 *
 * Tenant-owned by column rather than by trait — it is a vendor model and carries no
 * `BelongsToTeam` — so `isTenantOwned()` is overridden rather than derived, and `teamIdOf()`
 * still finds the `team_id` attribute Cashier writes.
 */
final class SubscriptionResource extends AdminResource
{
    public function model(): string
    {
        return Subscription::class;
    }

    public function key(): string
    {
        return 'subscriptions';
    }

    public function label(): string
    {
        return 'Subscriptions';
    }

    public function singular(): string
    {
        return 'Subscription';
    }

    public function group(): ResourceGroup
    {
        return ResourceGroup::Billing;
    }

    /**
     * @return list<ResourceColumn>
     */
    public function columns(): array
    {
        return [
            ResourceColumn::text('owner.name', 'Team'),
            ResourceColumn::text('type', 'Type'),
            ResourceColumn::text('stripe_status', 'Status'),
            ResourceColumn::text('stripe_price', 'Price'),
            ResourceColumn::count('quantity', 'Quantity'),
            ResourceColumn::date('trial_ends_at', 'Trial ends'),
            ResourceColumn::date('ends_at', 'Ends'),
        ];
    }

    /**
     * @return list<ResourceColumn>
     */
    public function detailColumns(): array
    {
        return [
            ResourceColumn::text('stripe_id', 'Stripe id'),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'stripe_id',
            'stripe_price',
        ];
    }

    /**
     * @return list<string>
     */
    public function sortable(): array
    {
        return [
            'id',
            'created_at',
        ];
    }

    public function isTenantOwned(): bool
    {
        return true;
    }

    /**
     * Cashier relates the billable party as `owner`, not `team` — which is why the column above
     * reads `owner.name`. Naming the relation here is not optional: the index renders a page of
     * rows, so without the eager load it is an N+1 that `preventLazyLoading` turns into an
     * exception rather than a slow page.
     *
     * @return list<string>
     */
    public function with(): array
    {
        return ['owner'];
    }
}
