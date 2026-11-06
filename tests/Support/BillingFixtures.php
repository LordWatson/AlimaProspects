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

        self::flushPlans();
    }

    /**
     * Set one of the plan's **Signals** limits on every plan at once, and flush the memo behind it.
     *
     * The Signals counterpart of {@see self::capAllowance()}, and the reason it exists is the one
     * thing S16 changed for every test that was already there: the module's own floors in
     * `config/signals.php` are now the *fallback*, and a plan's figure wins over them
     * (`PlanSignalQuotaSource`). A test that caps `signals.max_watches_per_team` and then expects a
     * refusal is capping the thing the plan overrides, so it passes a limit of 15 instead of 1 and
     * quietly stops testing anything.
     *
     * `$key` is one of the eight in a plan's `signals` block — `max_definitions`, `max_watches`,
     * `max_watchlist_entries`, `monthly_source_queries`, `monthly_preview_queries`,
     * `article_fetch_micros`, `playbooks`, `webhook_source`.
     *
     * **`null` here means unlimited**, as it does in the config itself — not "the plan says
     * nothing". The third state is {@see self::forgetSignalAllowance()}, and the two are not
     * interchangeable: an explicit null wins over the module's floor and an absent key falls back
     * to it.
     */
    public static function capSignalAllowance(string $key, int|bool|null $value): void
    {
        foreach (PlanKey::cases() as $plan) {
            config()->set("billing.plans.{$plan->value}.signals.{$key}", $value);
        }

        self::flushPlans();
    }

    /**
     * Take one of the plan's Signals limits out of the price list altogether, on every plan.
     *
     * The "no position taken" state, which is the only one that reaches the module's own floor in
     * `config/signals.php`. Worth having its own helper because the difference from passing null is
     * invisible at the call site otherwise, and it is the difference the fall-through turns on.
     */
    public static function forgetSignalAllowance(string $key): void
    {
        foreach (PlanKey::cases() as $plan) {
            // `Config\Repository` has a `set` and no `forget`, so the block is rewritten without
            // the key rather than the key being removed.
            $signals = (array) config("billing.plans.{$plan->value}.signals", []);
            unset($signals[$key]);
            config()->set("billing.plans.{$plan->value}.signals", $signals);
        }

        self::flushPlans();
    }

    /**
     * Drop the memos that sit in front of `config('billing.plans')`.
     *
     * `PlanRegistry` caches the parsed price list and `PlanResolver` memoises per team, so a test
     * that edits config without this is testing the plan the first resolution happened to see.
     */
    private static function flushPlans(): void
    {
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
