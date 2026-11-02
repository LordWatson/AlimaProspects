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

    /**
     * Read **the platform's own numbers** — MRR, churn, the onboarding funnel, and gross margin per
     * team (Phase 19c).
     *
     * Its own ability rather than {@see self::ReadTenantData} or {@see self::ManageBilling},
     * because it is neither. The figures are aggregates and name **no person**, so reading them is
     * not the privacy event `tenant.read` audits; and they are not only money, so putting them
     * behind the Finance ability would have hidden the onboarding funnel — the one report that
     * says whether the product works — from everybody except Finance.
     *
     * They are not anonymous, though, and the distinction is worth stating: the margin and
     * per-tenant MRR reports name **companies**, with what each pays and what each costs. That is
     * commercially sensitive and every operator role can see it. What they never carry is a
     * person's name, address or record, which is the line `tenant.read` is drawn on.
     */
    case ReadPlatformReports = 'reports.read';

    /**
     * Run the platform's operational levers: retry or drop a failed job, reset a provider's circuit
     * breaker, add an address to the global do-not-contact list (Phase 19c).
     *
     * Separate from {@see self::ManageSettings} because these are **incident** actions rather than
     * product decisions — the person on support at 2am needs them and does not need the Stripe
     * switch. Separate from {@see self::WriteTenantData} because none of them is a tenant's record:
     * each one reaches every tenant at once, which is why each is audited with no team and the
     * operator's name.
     *
     * Note what is *not* here: nothing in this ability can lift a suppression. There is no route
     * and no Action for it anywhere — see `AddPlatformSuppressionAction`.
     */
    case ManageOperations = 'operations.manage';

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
            self::ReadPlatformReports => 'Read platform reporting',
            self::ManageOperations => 'Run platform operations',
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
