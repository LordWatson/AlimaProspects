<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * The plans Alima sells, cheapest first. The value is the key under `config('billing.plans')` and
 * the string a Stripe price maps back to, so it is a stable identifier: renaming a case renames a
 * plan everywhere, which is the point of not using magic strings (CLAUDE.md §2).
 *
 * The *entitlements* of a plan live in config, not here. A case is an identity; an allowance is a
 * price-list decision that changes without a deploy touching this file.
 */
enum PlanKey: string
{
    /** No subscription, no trial — what a team falls back to, not a thing anybody buys. */
    case Free = 'free';

    case Starter = 'starter';

    case Team = 'team';

    case Scale = 'scale';

    /** Sold by a person, not a checkout button. */
    case Enterprise = 'enterprise';

    /**
     * Whether a team can put itself on this plan without talking to anybody. Free is excluded for
     * the opposite reason to Enterprise: there is nothing to check out.
     */
    public function isSelfServe(): bool
    {
        return match ($this) {
            self::Starter, self::Team, self::Scale => true,
            self::Free, self::Enterprise => false,
        };
    }

    /**
     * Whether being on this plan means somebody is paying us.
     */
    public function isPaid(): bool
    {
        return $this !== self::Free;
    }

    /**
     * Rank, for comparing plans (upgrade vs downgrade) without hard-coding an order at each call.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Starter => 1,
            self::Team => 2,
            self::Scale => 3,
            self::Enterprise => 4,
        };
    }
}
