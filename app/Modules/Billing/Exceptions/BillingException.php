<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Billing, rendered centrally (bootstrap/app.php): a friendly message for the
 * member, a stable machine code for the API envelope. Each cause is a named constructor so the code
 * is set in one place.
 *
 * Nothing here ever carries a Stripe message through to the user. Stripe's wording is written for
 * developers, it changes without notice, and it occasionally names the merchant's own account —
 * {@see self::gatewayFailed()} logs the detail and says something a customer can act on.
 */
final class BillingException extends DomainException
{
    protected string $errorCode = 'billing.invalid';

    /**
     * Somebody asked for a plan that isn't in the price list.
     */
    public static function unknownPlan(string $plan): self
    {
        $e = new self('That plan isn’t available.');
        $e->errorCode = 'billing.unknown_plan';

        return $e;
    }

    /**
     * The plan exists but cannot be bought from the app — Free (nothing to buy) or Enterprise
     * (sold by a person).
     */
    public static function planNotPurchasable(string $plan): self
    {
        $e = new self('That plan isn’t something you can switch to here — talk to us and we’ll set it up.');
        $e->errorCode = 'billing.plan_not_purchasable';

        return $e;
    }

    /**
     * A portal, swap or cancel was asked for on a team that has never subscribed.
     */
    /**
     * Stripe is switched off for this deployment (Phase 19b).
     *
     * **It is not a statement about the team's plan.** A team on Scale whose operator has turned
     * Stripe off is still on Scale, with its allowances intact — what is unavailable is checkout,
     * the portal, a plan swap, a cancellation and metered reporting. The message says so, because
     * the first thing a member will assume is that their account has been downgraded.
     */
    public static function stripeDisabled(): self
    {
        $e = new self(
            'Payments are temporarily unavailable. Your plan and everything on it are unaffected — '
            .'get in touch and we will sort it out.'
        );
        $e->errorCode = 'billing.disabled';
        $e->status = 503;

        return $e;
    }

    public static function noSubscription(): self
    {
        $e = new self('This team doesn’t have a subscription yet.');
        $e->errorCode = 'billing.no_subscription';

        return $e;
    }

    /**
     * A downgrade that would leave more members than the target plan has seats. Refused rather than
     * applied, because the alternative is deciding for them which colleague loses access.
     */
    public static function tooManySeats(int $members, int $seats): self
    {
        $e = new self(
            "That plan includes {$seats} seats and this team has {$members} members. Remove the extra members first, then switch."
        );
        $e->errorCode = 'billing.too_many_seats';

        return $e;
    }

    /**
     * Stripe was unreachable, or answered in a way we cannot interpret — **the outcome is unknown**.
     * The real reason goes to the `billing` log channel.
     */
    public static function gatewayFailed(): self
    {
        $e = new self('We couldn’t reach our payment provider. Please try again in a moment.');
        $e->errorCode = 'billing.gateway_failed';
        $e->status = 503;

        return $e;
    }

    /**
     * Stripe **definitely did not record** a usage report — it answered, and the answer was no.
     *
     * A separate code from {@see self::gatewayFailed()} for one reason: it is the only signal that
     * tells `OverageReporter` the units are safe to send again. "We don't know" and "it was
     * refused" demand opposite responses, and collapsing them into one error is how the same units
     * get billed twice.
     */
    public static function overageRefused(): self
    {
        $e = new self('Our payment provider refused a usage report. Nothing was charged.');
        $e->errorCode = 'billing.overage_refused';
        $e->status = 503;

        return $e;
    }

    /**
     * An overage price exists but has no Stripe meter behind it, so it cannot take usage at all.
     *
     * A misconfiguration of the product rather than anything a customer did, and the one failure
     * here that will never come right on its own — so it is never retried.
     */
    public static function overagePriceHasNoMeter(string $price): self
    {
        $e = new self('Usage billing isn’t set up correctly. We’ve been told, and nobody has been charged.');
        $e->errorCode = 'billing.overage_price_has_no_meter';

        return $e;
    }
}
