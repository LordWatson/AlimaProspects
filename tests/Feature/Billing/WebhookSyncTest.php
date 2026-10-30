<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Stripe's webhooks, with Stripe faked (Phase 16).
 *
 * Nothing here talks to Stripe: the tests post the payload Stripe would have posted to the route
 * Cashier registers, and `cashier.webhook.secret` is unset in the test environment so the
 * signature middleware stands down. What is being proved is that **a change made outside the app
 * reaches it** — a plan changed in the customer portal, a card that failed its last retry — and
 * that the team's entitlements move with it.
 */
function webhook(array $payload): TestResponse
{
    return test()->postJson('stripe/webhook', $payload);
}

function subscriptionPayload(Team $team, string $type, array $object = []): array
{
    return [
        'id' => 'evt_test',
        'type' => $type,
        'data' => [
            'object' => array_merge([
                'id' => 'sub_'.$team->getKey(),
                'customer' => $team->stripe_id,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'items' => [
                    'data' => [[
                        'id' => 'si_test',
                        'price' => [
                            'id' => (string) config('billing.plans.scale.stripe_price'),
                            'product' => 'prod_scale',
                        ],
                        'quantity' => 1,
                    ]],
                ],
            ], $object),
        ],
    ];
}

it('moves a team onto the plan Stripe says it is on', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    expect(app(PlanResolver::class)->for($team)->plan->key)->toBe(PlanKey::Starter);

    // An upgrade made in Stripe's customer portal never passes through this app at all.
    webhook(subscriptionPayload($team, 'customer.subscription.updated'))->assertOk();

    app(PlanResolver::class)->forget();

    expect(app(PlanResolver::class)->for($team->fresh())->plan->key)->toBe(PlanKey::Scale);
});

it('re-snapshots the allowance the team now has', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    webhook(subscriptionPayload($team, 'customer.subscription.updated'))->assertOk();

    $meter = UsageMeter::query()
        ->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('metric', BillingMetric::Leads->value)
        ->firstOrFail();

    // `included` has to follow the plan, or the usage bar keeps measuring against the plan the
    // team left.
    expect($meter->included)->toBe((int) config('billing.plans.scale.leads'));
});

it('records who changed it, when nobody in the app did', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    webhook(subscriptionPayload($team, 'customer.subscription.updated'))->assertOk();

    $audit = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('action', 'billing.subscription_synced')
        ->firstOrFail();

    // "Why did my plan change on the 4th" has to be answerable, and no user was logged in.
    expect($audit->user_id)->toBeNull()
        ->and($audit->properties['source'] ?? null)->toBe('stripe_webhook')
        ->and($audit->properties['plan'] ?? null)->toBe('scale');
});

it('follows a cancellation through to the free allowance', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    webhook(subscriptionPayload($team, 'customer.subscription.deleted'))->assertOk();

    app(PlanResolver::class)->forget();

    $entitlements = app(PlanResolver::class)->for($team->fresh());

    expect($entitlements->plan->key)->toBe(PlanKey::Free)
        ->and($entitlements->state->isEntitled())->toBeFalse();
});

it('keeps a past-due team entitled when Stripe says the payment failed', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale);

    webhook(subscriptionPayload($team, 'customer.subscription.updated', ['status' => 'past_due']))
        ->assertOk();

    app(PlanResolver::class)->forget();

    $entitlements = app(PlanResolver::class)->for($team->fresh());

    expect($entitlements->state->value)->toBe('past_due')
        ->and($entitlements->state->isEntitled())->toBeTrue()
        ->and($entitlements->plan->key)->toBe(PlanKey::Scale);
});

it('shrugs at a webhook for a customer it has never heard of', function (): void {
    // A test event, or another environment pointed at this endpoint. A 500 here makes Stripe
    // retry it for three days.
    [, $team] = Fixtures::team();

    $payload = subscriptionPayload($team, 'customer.subscription.updated');
    $payload['data']['object']['customer'] = 'cus_nobody';

    webhook($payload)->assertOk();

    expect(UsageMeter::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does not touch another team when one team’s subscription changes', function (): void {
    [, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    Fixtures::subscribe($a, PlanKey::Starter);
    Fixtures::subscribe($b, PlanKey::Starter);

    webhook(subscriptionPayload($a, 'customer.subscription.updated'))->assertOk();

    app(PlanResolver::class)->forget();

    expect(app(PlanResolver::class)->for($b->fresh())->plan->key)->toBe(PlanKey::Starter)
        ->and(Subscription::query()->where('team_id', $b->getKey())->firstOrFail()->stripe_price)
        ->toBe((string) config('billing.plans.starter.stripe_price'));
});
