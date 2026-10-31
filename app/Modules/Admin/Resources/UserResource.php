<?php

declare(strict_types=1);

namespace App\Modules\Admin\Resources;

use App\Models\User;
use App\Modules\Admin\Data\ResourceColumn;
use App\Modules\Admin\Data\ResourceField;
use App\Modules\Admin\Data\ResourceFilter;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\ResourceGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * People — customers and operators alike, because they are one table (ADR 0014).
 *
 * **The email address is deliberately not editable here.** Changing it in place would
 * hand an account to a new address with no verification step, which is an account
 * takeover with an audit entry. The customer changes it in their own settings, where
 * `ProfileController` clears `email_verified_at`; an operator who needs to help does it
 * through the user operations screen, which sends a link rather than setting anything.
 *
 * `admin_role` is not editable either, and not by omission: it is not fillable on the
 * model at all, so no mass assignment in the product can grant platform powers.
 */
final class UserResource extends AdminResource
{
    public function model(): string
    {
        return User::class;
    }

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users';
    }

    public function singular(): string
    {
        return 'User';
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
            ResourceColumn::text('email', 'Email'),
            ResourceColumn::text('admin_role', 'Platform role'),
            ResourceColumn::date('email_verified_at', 'Verified'),
            ResourceColumn::date('two_factor_confirmed_at', 'Two-factor'),
            ResourceColumn::date('created_at', 'Joined'),
        ];
    }

    /**
     * @return list<ResourceField>
     */
    public function fields(): array
    {
        return [
            ResourceField::text('name', 'Name', ['required', 'string', 'max:255']),
        ];
    }

    /**
     * @return list<ResourceFilter>
     */
    public function filters(): array
    {
        return [
            ResourceFilter::enum('admin_role', 'Platform role', AdminRole::class),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchable(): array
    {
        return [
            'name',
            'email',
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
            'email',
            'created_at',
        ];
    }

    public function allowsUpdate(): bool
    {
        return true;
    }

    public function isTenantOwned(): bool
    {
        return false;
    }

    public function title(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }
}
