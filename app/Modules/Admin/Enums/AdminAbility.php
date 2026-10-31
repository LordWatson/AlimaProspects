<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Services\Impersonator;

/**
 * What an operator may do in the panel (Phase 19a, ADR 0014).
 *
 * A separate axis from {@see AdminRole}: the role is who somebody is, the ability is what that lets
 * them do, and the mapping between the two lives in `config/admin.php` so it is a reviewed diff
 * rather than a `match` buried in a middleware. Every admin route declares exactly one ability, and
 * `AdminRouteContractTest` fails the build if one does not.
 *
 * Reading a tenant's prospect list, impersonating a customer, suspending a team and turning Stripe
 * off are genuinely different powers, which is the whole reason this is an enum and not a boolean
 * on `users`. A support engineer needs the first and the second; nobody needs the fourth to answer
 * a ticket.
 */
enum AdminAbility: string
{
    /** Open the panel at all. Every operator has it; it is what the dashboard checks. */
    case Access = 'platform.access';

    /** Read tenant-owned records across teams — a privacy event, and audited as one. */
    case ReadTenantData = 'tenant.read';

    /** Create and update records through the resource registry. */
    case WriteTenantData = 'tenant.write';

    /**
     * Delete a record through the generic writer. Separate from writing because a delete is the one
     * generic operation that cannot be undone and that no module Action is watching.
     */
    case DeleteTenantData = 'tenant.delete';

    /** Password resets, 2FA resets, session and token revocation, email verification. */
    case ManageUsers = 'users.manage';

    /** Suspension, membership and ownership — the operations that change who controls a tenant. */
    case ManageTeams = 'teams.manage';

    /** Plans, grants and subscriptions. Mostly Phase 19b; the ability is declared here. */
    case ManageBilling = 'billing.manage';

    /** Platform settings and the Stripe switches (Phase 19b). */
    case ManageSettings = 'settings.manage';

    /**
     * Change a **platform-wide** record — one that is ours rather than any tenant's, and whose
     * edit therefore changes behaviour for every customer at once.
     *
     * Separate from {@see self::WriteTenantData} because the two are not the same trust at all.
     * Fixing a misspelled company name for the customer on the phone is a support task; rewriting
     * the AI prompt registry is a release, and it lands in every tenant's next AI call. Resources
     * declare it through `AdminResource::ability()`.
     */
    case ManagePlatformData = 'platform.write';

    /** Sign in as a customer. See {@see Impersonator}. */
    case Impersonate = 'impersonate';

    /** Read the cross-tenant audit trail — including other operators' entries. */
    case ReadAudit = 'audit.read';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Open the admin panel',
            self::ReadTenantData => 'Read tenant data',
            self::WriteTenantData => 'Change tenant data',
            self::DeleteTenantData => 'Delete tenant data',
            self::ManageUsers => 'Manage users',
            self::ManageTeams => 'Manage teams',
            self::ManageBilling => 'Manage billing',
            self::ManageSettings => 'Manage platform settings',
            self::ManagePlatformData => 'Change platform-wide records',
            self::Impersonate => 'Impersonate a user',
            self::ReadAudit => 'Read the audit trail',
        };
    }

    /**
     * The gate name this ability is registered under, so a controller reads
     * `Gate::allows('admin.impersonate')` rather than assembling the string itself.
     */
    public function gate(): string
    {
        return 'admin.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
