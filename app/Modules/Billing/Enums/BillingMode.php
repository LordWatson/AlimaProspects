<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Core\Models\Team;

/**
 * How a tenant is billed — `teams.billing_mode` (Phase 19b).
 *
 * In Billing rather than in Admin, because `PlanResolver` is the main consumer and the module that
 * owns "how does this tenant pay" should own the enum that says so. Admin sets it; Billing reads it.
 *
 * **None of these modes is "has no plan."** A `manual` or `disabled` team is fully entitled; what
 * changes is where the entitlement comes from and whether we will talk to Stripe about it. Keeping
 * those two ideas apart is the whole point of the column — conflating them is how an operator
 * switches billing off and demotes a paying customer.
 */
enum BillingMode: string
{
    /** Self-serve checkout. Today's behaviour, and the default for every existing team. */
    case Stripe = 'stripe';

    /**
     * Invoiced outside the product: no checkout, no card, no metering. Entitlement comes from a
     * {@see PlanGrant}. The Enterprise case the price list already describes with a null `amount`.
     */
    case Manual = 'manual';

    /** No billing at all — internal tenants, demos, pilots, partners. */
    case Disabled = 'disabled';

    /**
     * A team's mode, read off the column.
     *
     * A static here rather than a `billingMode()` method on `Team`, and the reason is the module
     * layering: `Team` lives in **Core**, which is the foundation every module builds on, so Core
     * importing a Billing enum would invert the dependency this codebase is arranged around. The
     * column is a plain string there; the meaning of the string is Billing's.
     *
     * Falls back to `Stripe` for an unhydrated or unrecognised value — the mode every team has
     * always had — so a column that has somehow not been read cannot switch a customer's billing
     * off by accident. Attribute access rather than `$team->billing_mode`, so an unhydrated model
     * answers instead of throwing under `shouldBeStrict()`.
     */
    public static function of(Team $team): self
    {
        $value = $team->getAttributes()['billing_mode'] ?? null;

        return is_string($value) ? (self::tryFrom($value) ?? self::Stripe) : self::Stripe;
    }

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Self-serve (Stripe)',
            self::Manual => 'Invoiced (manual)',
            self::Disabled => 'No billing',
        };
    }

    /**
     * Whether this team may reach checkout, the portal, a plan swap or a cancellation.
     */
    public function allowsSelfServe(): bool
    {
        return $this === self::Stripe;
    }

    /**
     * Whether overage is metered to Stripe for this team. A tenant nobody is charging through
     * Stripe has nothing to meter, and reporting usage against a subscription that does not exist
     * is how `billing:report-overage` ends up logging errors every morning.
     */
    public function isMetered(): bool
    {
        return $this === self::Stripe;
    }
}
