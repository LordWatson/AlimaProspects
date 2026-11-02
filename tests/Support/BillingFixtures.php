<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Testing\FakeBillingGateway;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use DateTimeInterface;
use Laravel\Cashier\Subscription;

/**
 * Shared set-up for the Billing tests, and the plan fixtures the Lead Sourcing and AI Platform
 * tests need now that their quota stubs have been replaced by plan-backed sources (Phase 16).
 *
 * A class rather than global helpers, for the same reason as {@see ComplianceFixtures}: these are
 * needed across several modules' test directories, and PSR-4 makes that explicit instead of
 * depending on which test file Pest loaded first.
 *
 * **Nothing here touches Stripe.** Subscriptions are written straight into the `subscriptions`
 * table, which is exactly what Cashier's webhook handler does, and the gateway is the
 * {@see FakeBillingGateway}.
 */
final class BillingFixtures
{
    /**
     * A verified Owner with their own team, on the card-less trial every new team starts on.
     *
     * @return array{User, Team}
     */
    public static function team(): array
    {
        $user = createUserWithTeam();

        return [$user, $user->currentTeam()->firstOrFail()];
    }

    /**
     * Bind the fake gateway and hand it back for assertions.
     */
    public static function fakeGateway(): FakeBillingGateway
    {
        $fake = new FakeBillingGateway;

        app()->instance(BillingGateway::class, $fake);

        return $fake;
    }

    /**
     * Put a team on a plan, by writing the subscription row Stripe's webhook would have written.
     *
     * Also clears the team's generic trial, because a trialling team is entitled to the *trial*
     * plan whatever it is subscribed to — leaving it set would silently make every "on the Starter
     * plan" test assert against the Team plan's allowances instead.
     */
    public static function subscribe(
        Team $team,
        PlanKey $plan,
        string $status = 'active',
        ?DateTimeInterface $endsAt = null,
        ?DateTimeInterface $trialEndsAt = null,
    ): Subscription {
        $team->forceFill(['stripe_id' => 'cus_'.$team->getKey(), 'trial_ends_at' => null])->save();

        /** @var Subscription $subscription */
        $subscription = $team->subscriptions()->create([
            'type' => PlanResolver::TYPE,
            'stripe_id' => 'sub_'.$team->getKey(),
            'stripe_status' => $status,
            'stripe_price' => self::priceFor($plan),
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => $endsAt,
        ]);

        app(PlanResolver::class)->forget($team);

        // The capability gate is `scoped` and memoises per team (ADR 0015), and a test container
        // is not rebuilt between `$this->get()` calls the way a real request is. Dropping the
        // scoped instances is what makes the next request in the same test see the new plan —
        // which is what a browser would see, because its next request is a new container.
        app()->forgetScopedInstances();

        return $subscription;
    }

    /**
     * The Stripe price a plan is sold at, inventing one for Enterprise.
     *
     * Enterprise genuinely has no public price — it is sold per customer, and `.env` sets
     * `STRIPE_PRICE_ENTERPRISE` once a deal is signed. A test that needs a team *on* it has to
     * supply that price itself, so this does what signing the deal would: writes one into the
     * price list and flushes the registry's memo.
     */
    private static function priceFor(PlanKey $plan): string
    {
        $price = config("billing.plans.{$plan->value}.stripe_price");

        if (is_string($price) && $price !== '') {
            return $price;
        }

        $price = "price_test_{$plan->value}";

        config()->set("billing.plans.{$plan->value}.stripe_price", $price);
        app(PlanRegistry::class)->flush();

        return $price;
    }

    /**
     * Move a team onto a different plan, as a swap would.
     *
     * `subscribe()` twice on one team collides on the unique `stripe_id`, which is right — a team
     * has one subscription — but makes "check it on Starter, then upgrade and check again" need two
     * teams. This replaces the row instead, which is what the Stripe webhook does in production.
     */
    public static function moveToPlan(Team $team, PlanKey $plan): Subscription
    {
        Subscription::query()->where('team_id', $team->getKey())->delete();

        return self::subscribe($team, $plan);
    }

    /**
     * A team past its trial with no subscription — the free allowance.
     */
    public static function onFreePlan(Team $team): void
    {
        $team->forceFill(['trial_ends_at' => now()->subDay()])->save();

        app(PlanResolver::class)->forget($team);
    }

    /**
     * Set one metric's monthly allowance on every plan at once, and flush the memo behind it.
     *
     * What tests outside this module reach for when they need a team that has run out — "no AI
     * credit at all" used to be one line of `ai-platform.credits` config against the Phase 5 stub,
     * and since Phase 16 the allowance comes from the plan instead. Setting it on every plan means
     * the caller does not have to know which plan the team happens to be on.
     */
    public static function capAllowance(BillingMetric $metric, ?int $amount): void
    {
        foreach (PlanKey::cases() as $plan) {
            config()->set("billing.plans.{$plan->value}.{$metric->allowanceKey()}", $amount);
        }

        app(PlanRegistry::class)->flush();
        app(PlanResolver::class)->forget();
    }

    /**
     * Add a second person to a team, for the seat tests.
     */
    public static function addMember(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->create();

        app(TeamMembership::class)->add($user, $team, $role);

        return $user;
    }
}
