<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\Entitlements;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;

/**
 * Turns a team's Stripe subscription (or the absence of one) into the {@see Entitlements} the rest
 * of the app enforces against. The single place that answers "what is this team allowed to do".
 *
 * Memoised per team for the life of the **request or job**, because the guards ask on every AI call
 * and every discovery run and the answer cannot change mid-request — except when this module itself
 * changes it, which is what {@see self::forget()} is for.
 *
 * Bound `scoped()`, not `singleton()`, and the difference matters: a singleton lives for the whole
 * `queue:work` process, and three of the four enforcement points run in a worker. A team that
 * upgraded at ten past nine would have gone on being refused until somebody restarted the worker,
 * and a team whose trial lapsed would have kept its allowance just as long. Scoped instances are
 * cleared between jobs (and between Octane requests), so the memo lives exactly as long as the
 * thing it is memoising for.
 */
final class PlanResolver
{
    /**
     * The Cashier subscription type. One per team: Alima sells a plan, not a basket of products,
     * and a second type would be a second thing to reason about on every screen.
     */
    public const string TYPE = 'default';

    /**
     * @var array<int, Entitlements>
     */
    private array $memo = [];

    public function __construct(private readonly PlanRegistry $plans) {}

    public function for(Team $team): Entitlements
    {
        $id = (int) $team->getKey();

        return $this->memo[$id] ??= $this->resolve($team);
    }

    /**
     * The plan whose allowances are in force for a team — the common case, and the only thing the
     * guards need.
     */
    public function planFor(Team $team): PlanData
    {
        return $this->for($team)->plan;
    }

    /**
     * Drop the memo after something changed the team's subscription, so the next read sees it.
     * Called by the Actions and the webhook controller, never by a caller merely reading.
     */
    public function forget(?Team $team = null): void
    {
        if ($team instanceof Team) {
            unset($this->memo[(int) $team->getKey()]);

            return;
        }

        $this->memo = [];
    }

    /**
     * The team's one subscription, read with an explicit query rather than through Cashier's
     * `subscription()` helper.
     *
     * Two reasons, both of which bit. Cashier's helper reads `$this->subscriptions`, which (a) is a
     * **lazy relation**, so on a team hydrated as part of a collection — the two console commands
     * walk every team — `Model::preventLazyLoading()` turns a usage sync into an exception outside
     * production; and (b) is **cached on the instance**, so an Action that swaps a plan and then
     * re-reads entitlements on the same `$team` object would snapshot the plan the team just left.
     * A query answers correctly in both cases and reads one row instead of every subscription the
     * team has ever had.
     *
     * Public because {@see StripeBillingGateway} needs the same lookup for the same reasons.
     */
    public function subscriptionFor(Team $team): ?Subscription
    {
        return Subscription::query()
            ->where((new Team)->getForeignKey(), $team->getKey())
            ->where('type', self::TYPE)
            // Cashier's own relation orders this way, and a team should never have two rows of one
            // type anyway — if it somehow does, the newest is the live one.
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolve(Team $team): Entitlements
    {
        $subscription = $this->subscriptionFor($team);

        if ($subscription instanceof Subscription && $subscription->valid()) {
            return $this->fromSubscription($subscription);
        }

        // A card-less trial: no subscription row at all, just `teams.trial_ends_at`. Every team
        // gets one at creation, which is why a brand-new tenant can use the product before anyone
        // has typed a card number.
        if ($team->onGenericTrial()) {
            return new Entitlements(
                plan: $this->plans->trial(),
                state: SubscriptionState::Trialing,
                trialEndsAt: $this->immutable($team->trial_ends_at),
                endsAt: null,
                stripePrice: null,
            );
        }

        // A subscription row that is no longer valid — cancelled and past its grace period, or one
        // Stripe gave up on. The team keeps its data and drops to the free allowance; it does not
        // get locked out of an account it may be about to resubscribe to.
        if ($subscription instanceof Subscription) {
            return new Entitlements(
                plan: $this->plans->default(),
                state: SubscriptionState::Cancelled,
                trialEndsAt: null,
                endsAt: $this->immutable($subscription->ends_at),
                stripePrice: $subscription->stripe_price,
            );
        }

        return Entitlements::none($this->plans->default());
    }

    private function fromSubscription(Subscription $subscription): Entitlements
    {
        $plan = $this->plans->fromStripePrice($subscription->stripe_price) ?? $this->fallbackPaidPlan();

        return new Entitlements(
            plan: $plan,
            state: $this->stateOf($subscription),
            trialEndsAt: $subscription->onTrial() ? $this->immutable($subscription->trial_ends_at) : null,
            endsAt: $this->immutable($subscription->ends_at),
            stripePrice: $subscription->stripe_price,
        );
    }

    /**
     * What to entitle a paying team to when its Stripe price isn't in the price list any more —
     * a plan we have withdrawn, or an Enterprise price created for one customer.
     *
     * The cheapest paid plan, not the free one. Somebody is being charged; dropping them to the
     * free allowance because *we* retired a price would stop their work for our bookkeeping.
     * Erring low rather than high is the other half of that: it protects the margin while the
     * mismatch is noticed.
     */
    private function fallbackPaidPlan(): PlanData
    {
        $purchasable = $this->plans->purchasable();

        return $purchasable[0] ?? $this->plans->default();
    }

    private function stateOf(Subscription $subscription): SubscriptionState
    {
        return match (true) {
            $subscription->pastDue() => SubscriptionState::PastDue,
            $subscription->onGracePeriod() => SubscriptionState::GracePeriod,
            $subscription->onTrial() => SubscriptionState::Trialing,
            default => SubscriptionState::Active,
        };
    }

    private function immutable(mixed $value): ?CarbonImmutable
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc() : null;
    }
}
