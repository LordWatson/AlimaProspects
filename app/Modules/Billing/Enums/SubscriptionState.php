<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * What a team's billing relationship currently *is*, reduced from Cashier's Stripe status plus the
 * generic trial, to the handful of states the product actually behaves differently in.
 *
 * Deliberately not Stripe's status list: `incomplete_expired`, `unpaid` and `canceled` all mean the
 * same thing to this app (nobody is paying, fall back to the free allowance), and a screen that
 * showed the difference would be showing the customer our plumbing.
 */
enum SubscriptionState: string
{
    /** Never subscribed, and the trial is over (or never started). */
    case None = 'none';

    /** A free trial with no card attached — Cashier's "generic trial". */
    case Trialing = 'trialing';

    /** Paying, in good standing. */
    case Active = 'active';

    /** A payment has failed. Still entitled, for now — see {@see self::isEntitled()}. */
    case PastDue = 'past_due';

    /** Cancelled, but paid up to the end of the period. */
    case GracePeriod = 'grace_period';

    /** Cancelled and over. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No subscription',
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::PastDue => 'Payment failed',
            self::GracePeriod => 'Ending soon',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the team gets its plan's entitlements right now.
     *
     * **`PastDue` counts.** A failed card is usually an expiry, not a refusal to pay, and cutting a
     * team off the hour Stripe's first retry fails turns a billing hiccup into a support incident
     * and a churn event. Stripe retries for a fortnight and then cancels the subscription, at which
     * point the state becomes `Cancelled` and this returns false on its own — the dunning window is
     * Stripe's job, and we do not need to reimplement it to be strict a week early.
     */
    public function isEntitled(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue, self::GracePeriod => true,
            self::None, self::Cancelled => false,
        };
    }

    /**
     * Whether the screen should say something urgent about it.
     */
    public function needsAttention(): bool
    {
        return $this === self::PastDue;
    }
}
