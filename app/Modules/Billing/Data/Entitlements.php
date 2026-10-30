<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Services\PlanResolver;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Everything the rest of the app needs to know about a team's billing, resolved once by
 * {@see PlanResolver}: which plan's allowances apply, why, and until when.
 *
 * `plan` is the plan whose **entitlements are in force**, which is not always the plan the team is
 * paying for: a team in trial gets the trial plan's allowances with no subscription at all, and a
 * team whose subscription has lapsed gets the free plan's while its cancelled subscription row is
 * still sitting there. Anything that asks "what is this team allowed to do" wants `plan`; only the
 * billing screen wants to distinguish the two, which is what `state` is for.
 */
final class Entitlements extends Data
{
    public function __construct(
        public readonly PlanData $plan,
        public readonly SubscriptionState $state,
        /** When the trial ends — set whenever `state` is Trialing, null otherwise. */
        public readonly ?CarbonImmutable $trialEndsAt,
        /** When a cancelled subscription stops working, during the grace period. */
        public readonly ?CarbonImmutable $endsAt,
        /** The Stripe price the team is actually subscribed to, if any. */
        public readonly ?string $stripePrice,
    ) {}

    /**
     * A team with no subscription and no trial left: the free allowance, and the state a pricing
     * page is being shown to.
     */
    public static function none(PlanData $plan): self
    {
        return new self(
            plan: $plan,
            state: SubscriptionState::None,
            trialEndsAt: null,
            endsAt: null,
            stripePrice: null,
        );
    }

    public function isTrialing(): bool
    {
        return $this->state === SubscriptionState::Trialing;
    }

    /**
     * Whether somebody is paying us — as opposed to being entitled, which a trial also is.
     */
    public function isSubscribed(): bool
    {
        return in_array($this->state, [
            SubscriptionState::Active,
            SubscriptionState::PastDue,
            SubscriptionState::GracePeriod,
        ], strict: true);
    }

    /**
     * Whole days of trial left, floored at zero. Null when not on trial.
     */
    public function trialDaysLeft(): ?int
    {
        if ($this->trialEndsAt === null) {
            return null;
        }

        return max(0, (int) ceil(CarbonImmutable::now('UTC')->diffInDays($this->trialEndsAt, false)));
    }
}
