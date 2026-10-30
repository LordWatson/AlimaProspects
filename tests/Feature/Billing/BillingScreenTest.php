<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Actions\SwapPlanAction;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The billing screen and the four things a team can do from it (Phase 16).
 *
 * Authorisation is the half worth most attention: **viewing is every member's business** — when
 * discovery stops mid-afternoon, the person it stopped for should be able to see why rather than
 * file a bug — and **spending money is the Owners' and Admins'**.
 *
 * Every test runs against the fake gateway, including the ones that assert nothing about it: the
 * screen fetches invoices on render, and a suite that reached Stripe for that would fail on a
 * laptop with no network (CLAUDE.md §2 — fake external providers).
 */
beforeEach(function (): void {
    Fixtures::fakeGateway();
});

it('shows a member where the team stands', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_created' => 120,
        'completed_at' => now(),
    ]));

    $this->actingAs($owner)
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('billing/Index')
            ->where('subscription.plan.key', 'team')
            ->where('subscription.state', 'active')
            ->where('usage.0.metric', BillingMetric::Leads->value)
            ->where('usage.0.used', 120)
            ->where('usage.0.included', (int) config('billing.plans.team.leads'))
            ->where('seats.limit', 3)
            ->where('can.manage', true)
            ->has('plans', 5)
        );
});

it('syncs the meters on render, so the numbers are as of now', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_created' => 7,
        'completed_at' => now(),
    ]));

    $this->actingAs($owner)->get('/billing')->assertOk();

    // A team whose Owner opens the screen once a month should still leave a meter row behind for
    // the month, and the row should match what they were shown.
    expect(UsageMeter::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(2)
        ->and(UsageMeter::query()->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('metric', BillingMetric::Leads->value)
            ->value('used'))->toBe(7);
});

it('lets a plain member look but not spend', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    $member = Fixtures::addMember($team, TeamRole::Member);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member)
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.manage', false)
            // An invoice list is money, and a round trip to Stripe; a Member gets neither.
            ->has('invoices', 0)
        );

    $this->actingAs($member)
        ->put('/billing/plan', ['plan' => 'scale'])
        ->assertForbidden();

    $this->actingAs($member)->post('/billing/portal')->assertForbidden();
    $this->actingAs($member)->delete('/billing/subscription')->assertForbidden();
});

it('keeps the screen behind a login', function (): void {
    $this->get('/billing')->assertRedirect();
});

it('sends an unsubscribed team to Stripe Checkout', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();

    $this->actingAs($owner)
        ->post('/billing/checkout', ['plan' => 'scale'])
        ->assertRedirect('https://checkout.stripe.test/session/scale');

    $calls = $gateway->callsOf('checkout');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['plan'])->toBe('scale')
        ->and($calls[0]['success_url'])->toContain('/billing');

    // Nothing is recorded as subscribed until Stripe says the payment went through — a team that
    // opened checkout and closed the tab has not bought anything.
    expect($team->fresh()?->subscribed())->toBeFalse();

    expect(AuditLog::query()->withoutGlobalScopes()
        ->where('action', 'billing.checkout_started')->exists())->toBeTrue();
});

it('swaps a subscribed team in place rather than sending it to checkout', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $this->actingAs($owner)
        ->put('/billing/plan', ['plan' => 'scale'])
        ->assertRedirect();

    expect($gateway->callsOf('swap'))->toHaveCount(1)
        ->and($gateway->callsOf('checkout'))->toBeEmpty();
});

it('snapshots the new allowance, not the one the team just left', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    app(SwapPlanAction::class)->handle(
        $team,
        app(PlanRegistry::class)->get(PlanKey::Scale),
        $owner,
    );

    // The Action re-snapshots the meters on the **same** `$team` object the gateway just acted on.
    // Cashier caches `subscriptions` on the instance, so without dropping that cache as well as
    // the resolver's memo, this would record the Starter allowance against a Scale subscription.
    $included = UsageMeter::query()
        ->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('metric', BillingMetric::Leads->value)
        ->value('included');

    expect($included)->toBe((int) config('billing.plans.scale.leads'));
});

it('refuses a downgrade that would leave more members than seats', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    Fixtures::addMember($team);
    Fixtures::addMember($team);

    // Three members, one-seat plan. The only alternative to refusing is deciding which two
    // colleagues lose their account, which is not a billing screen's decision to make.
    $this->actingAs($owner)
        ->put('/billing/plan', ['plan' => 'starter'])
        ->assertRedirect();

    expect($gateway->callsOf('swap'))->toBeEmpty()
        ->and(app(PlanResolver::class)->for($team->fresh())->plan->key)->toBe(PlanKey::Scale);
});

it('will not sell a plan that is not for sale', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();

    // Free has nothing to buy and Enterprise is sold by a person. Stopped at validation, so
    // neither reaches Stripe as an error that is not the customer's fault.
    $this->actingAs($owner)
        ->post('/billing/checkout', ['plan' => 'enterprise'])
        ->assertSessionHasErrors('plan');

    $this->actingAs($owner)
        ->post('/billing/checkout', ['plan' => 'free'])
        ->assertSessionHasErrors('plan');

    $this->actingAs($owner)
        ->post('/billing/checkout', ['plan' => 'nonsense'])
        ->assertSessionHasErrors('plan');

    expect($gateway->calls)->toBeEmpty();
});

it('cancels at the end of the period, and can be undone', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    $this->actingAs($owner)->delete('/billing/subscription')->assertRedirect();
    $this->actingAs($owner)->post('/billing/subscription')->assertRedirect();

    expect($gateway->callsOf('cancel'))->toHaveCount(1)
        ->and($gateway->callsOf('resume'))->toHaveCount(1);

    expect(AuditLog::query()->withoutGlobalScopes()
        ->whereIn('action', ['billing.subscription_cancelled', 'billing.subscription_resumed'])
        ->count())->toBe(2);
});

it('hands the portal over to Stripe rather than collecting a card itself', function (): void {
    $gateway = Fixtures::fakeGateway();
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    $this->actingAs($owner)
        ->post('/billing/portal')
        ->assertRedirect('https://billing.stripe.test/portal/'.$team->getKey());

    expect($gateway->callsOf('portal'))->toHaveCount(1);
});

it('shows a team only its own plan and usage', function (): void {
    [$ownerA, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    Fixtures::subscribe($a, PlanKey::Starter);
    Fixtures::subscribe($b, PlanKey::Scale);

    app(TeamContext::class)->run($b, fn () => DiscoveryRun::factory()->create([
        'team_id' => $b->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_created' => 900,
        'completed_at' => now(),
    ]));

    $this->actingAs($ownerA)
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscription.plan.key', 'starter')
            ->where('usage.0.used', 0)
        );
});

it('cannot act on a team the member has left', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    // Someone whose `current_team_id` still points at a team they are no longer in.
    $outsider = User::factory()->create(['current_team_id' => $team->getKey()]);

    $this->actingAs($outsider)->get('/billing')->assertForbidden();
    $this->actingAs($outsider)->put('/billing/plan', ['plan' => 'scale'])->assertForbidden();

    expect($gateway->calls)->toBeEmpty();
});
