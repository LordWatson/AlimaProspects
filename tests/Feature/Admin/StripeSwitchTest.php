<?php

declare(strict_types=1);

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Listeners\SyncSubscriptionFromStripe;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\DisabledBillingGateway;
use App\Modules\Billing\Services\OverageReporter;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\StripeBillingGateway;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookHandled;
use Tests\Support\AdminFixtures;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * **The test that matters most in Phase 19b.**
 *
 * The one bug here that would cost money and customers is "Stripe off" being read as "no plan": an
 * operator flips one switch during an incident and every paying customer is silently demoted to
 * Free, because `PlanResolver` would resolve "we cannot talk to Stripe" as "there is no
 * subscription". So the rule is asserted from both sides — what stops, and what must not.
 *
 * What stops: checkout, the portal, plan swaps, cancellation, resumption, metered reporting, and
 * the webhook. What does not: the plan, its allowances, the usage sync, and every guard that reads
 * them.
 */

/**
 * Turn the operator's Stripe switch off and drop the memos that would answer from before.
 */
function switchStripeOff(): void
{
    app(PlatformSettings::class)->set('billing.stripe_enabled', false);

    // `PlatformSettings` is `scoped` and the gateway binding resolves the switch per call, so a
    // fresh container instance is what a subsequent request would see.
    app()->forgetInstance(PlatformSettings::class);
    app()->forgetInstance(BillingGateway::class);
}

it('binds the live gateway while Stripe is on', function (): void {
    expect(app(StripeSwitch::class)->isEnabled())->toBeTrue()
        ->and(app(BillingGateway::class))->toBeInstanceOf(StripeBillingGateway::class);
});

it('binds a refusing gateway the moment an operator turns it off', function (): void {
    switchStripeOff();

    // A closure binding, not a class name — so the switch takes effect on the next resolution
    // rather than on the next deploy.
    expect(app(StripeSwitch::class)->isEnabled())->toBeFalse()
        ->and(app(BillingGateway::class))->toBeInstanceOf(DisabledBillingGateway::class);
});

it('refuses every write that would reach Stripe', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    switchStripeOff();

    $gateway = app(BillingGateway::class);
    $plan = app(PlanResolver::class)->planFor($team);

    $calls = [
        fn () => $gateway->checkoutUrl($team, $plan, 'https://a.test', 'https://b.test'),
        fn () => $gateway->portalUrl($team, 'https://a.test'),
        fn () => $gateway->swapPlan($team, $plan),
        fn () => $gateway->cancel($team),
        fn () => $gateway->resume($team),
        fn () => $gateway->reportOverage($team, 'price_x', 1, 'key'),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(BillingException::class);
    }
});

it('returns an empty invoice list rather than taking the billing page down', function (): void {
    [, $team] = Fixtures::team();

    switchStripeOff();

    // A read the billing screen makes unconditionally. "We cannot reach Stripe, so we cannot show
    // your invoices" should render as an empty table, not a 503.
    expect(app(BillingGateway::class)->invoices($team))->toBe([]);
});

it('does not demote a paying customer', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    $before = app(PlanResolver::class)->for($team);

    switchStripeOff();
    app(PlanResolver::class)->forget($team);

    $after = app(PlanResolver::class)->for($team);

    // **The rule.** A team on Scale stays on Scale, with its allowances intact, and goes on
    // working. `PlanResolver` reads a subscription row; whether we are currently willing to talk to
    // Stripe about it is a different question entirely.
    expect($after->plan->key)->toBe(PlanKey::Scale)
        ->and($after->plan->key)->toBe($before->plan->key)
        ->and($after->state)->toBe($before->state)
        ->and($after->plan->seats)->toBe($before->plan->seats)
        ->and($after->plan->leads)->toBe($before->plan->leads)
        ->and($after->plan->credits)->toBe($before->plan->credits);
});

it('leaves every guard reading the same allowances', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    $credits = app(CreditSource::class)->allowanceFor($team)->limitMicros;
    $leads = app(LeadQuotaSource::class)->quotaFor($team)->limit;

    switchStripeOff();
    app(PlanResolver::class)->forget($team);

    expect(app(CreditSource::class)->allowanceFor($team)->limitMicros)->toBe($credits)
        ->and(app(LeadQuotaSource::class)->quotaFor($team)->limit)->toBe($leads)
        ->and($credits)->toBeGreaterThan(0);
});

it('keeps syncing usage, because a meter is a record over the ledgers', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    switchStripeOff();

    // ADR 0012: `usage_meters` is derived from `ai_requests` and `discovery_runs`, and has nothing
    // to do with Stripe. A team's allowance, its warnings and its guards all keep working.
    app(OverageReporter::class)->report($team);

    expect(UsageMeter::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())
        ->toBeGreaterThan(0);
});

it('reports no overage, and says so rather than erroring', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // Enough usage that there would be something to report.
    Fixtures::capAllowance(BillingMetric::Leads, 1);
    app(UsageLedger::class)->sync($team);

    switchStripeOff();

    // Zero billed units, no exception: an invoiced or un-Striped customer's overage is a
    // conversation, not a metered event.
    expect(app(OverageReporter::class)->report($team))->toBe(0);
});

it('refuses the Stripe webhook route', function (): void {
    switchStripeOff();

    // Defence in depth — the listener is the real protection — but a 404 means Stripe retries the
    // event later instead of us processing it quietly now.
    $this->postJson(route('cashier.webhook'), ['type' => 'customer.subscription.updated'])
        ->assertNotFound();
});

it('ignores a webhook that somehow arrives anyway', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    $team->forceFill(['stripe_id' => 'cus_switchtest'])->save();

    switchStripeOff();

    // The listener, called directly: a misconfigured proxy or a cached route must not let a webhook
    // change a team's entitlements while an operator believes Stripe is not being listened to.
    app(SyncSubscriptionFromStripe::class)->handle(
        new WebhookHandled([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['customer' => 'cus_switchtest', 'status' => 'canceled']],
        ]),
    );

    expect(AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'billing.subscription_synced')
        ->exists())->toBeFalse();
});

it('tells the billing screen not to offer a button that would throw', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    $this->actingAs($owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.checkout', true));

    switchStripeOff();

    $this->actingAs($owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // "Stripe is off" has to read as a product state with a sentence, not as a 503 the
            // member meets after clicking.
            ->where('can.checkout', false)
            ->where('can.portal', false)
            ->where('self_serve', false)
            // And the plan is still the plan.
            ->where('subscription.plan.key', PlanKey::Scale->value));
});

it('still lets a suspended team be told apart from a Stripe outage', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    switchStripeOff();
    AdminFixtures::suspend($team);
    app(PlanResolver::class)->forget($team);

    // Two different states that both stop things, and they must not be confused: the plan is
    // untouched by either, and only suspension takes the allowance away.
    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Scale)
        ->and(app(CreditSource::class)->allowanceFor($team)->isExhausted())->toBeTrue();

    expect($team->refresh())->toBeInstanceOf(Team::class);
});
