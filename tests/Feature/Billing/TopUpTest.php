<?php

declare(strict_types=1);

use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\UsageEntry;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Buying more allowance outright (PL4).
 *
 * The purchase and the grant are **two halves with Stripe in between**, and both halves are tested
 * here because the rule that matters spans them: nothing is granted until the money arrives, and
 * the money arriving twice grants once.
 */
beforeEach(function (): void {
    config()->set('billing.topups.email_reveal', [
        ['quantity' => 250, 'amount' => 1_500, 'stripe_price' => 'price_topup_email'],
    ]);
});

function topUpPayload(Team $team, array $metadata, string $eventId = 'evt_topup', string $status = 'paid'): array
{
    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test',
                'customer' => $team->stripe_id,
                'payment_status' => $status,
                'metadata' => $metadata,
            ],
        ],
    ];
}

function paidTopUp(Team $team, string $eventId = 'evt_topup', int $quantity = 250): array
{
    return topUpPayload($team, [
        'alima_topup_metric' => BillingMetric::EmailReveal->value,
        'alima_topup_quantity' => (string) $quantity,
    ], $eventId);
}

it('sends an Owner to Stripe for the option they chose, and grants nothing yet', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    $gateway = Fixtures::fakeGateway();

    $this->actingAs($owner)
        ->post('/billing/topup', ['metric' => 'email_reveal', 'option' => 0])
        ->assertRedirect('https://checkout.stripe.test/topup/price_topup_email');

    $call = collect($gateway->calls)->firstWhere('operation', 'topup');

    expect($call['stripe_price'])->toBe('price_topup_email')
        ->and($call['quantity'])->toBe(250)
        ->and($call['metadata']['alima_topup_metric'])->toBe('email_reveal');

    // **Nothing granted.** A grant written here is an allowance given to somebody who closed the
    // tab at the card form.
    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'billing.topup_started')->count())->toBe(1);
});

it('refuses to be told which Stripe price to charge', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::fakeGateway();

    // An option index that names nothing is a 404: the request was well formed and the thing it
    // named has gone. The important half is that there is no way to send a price at all.
    $this->actingAs($owner)
        ->post('/billing/topup', ['metric' => 'email_reveal', 'option' => 7])
        ->assertNotFound();

    // And a metric nothing is sold in is a validation failure, from the enum's own answer.
    $this->actingAs($owner)
        ->post('/billing/topup', ['metric' => 'export_rows', 'option' => 0])
        ->assertSessionHasErrors('metric');
});

it('will not let a Member spend the team’s money', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::fakeGateway();

    $member = Fixtures::addMember($team);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member)
        ->post('/billing/topup', ['metric' => 'email_reveal', 'option' => 0])
        ->assertForbidden();
});

it('grants the allowance when Stripe says it was paid', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::capAllowance(BillingMetric::EmailReveal, 10);

    $this->postJson('stripe/webhook', paidTopUp($team))->assertOk();

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(UsageAllowance::class)->entitled($team, BillingMetric::EmailReveal))->toBe(260);
        expect(app(UsageAllowance::class)->added($team, BillingMetric::EmailReveal))->toBe(250);
    });

    $entry = UsageEntry::query()->withoutGlobalScopes()->firstOrFail();

    expect($entry->direction)->toBe(LedgerDirection::TopUp)
        ->and($entry->idempotency_key)->toBe('stripe:evt_topup');

    expect(AuditLog::query()->where('action', 'billing.topup_granted')->count())->toBe(1);
});

it('grants once however many times Stripe delivers the event', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::capAllowance(BillingMetric::EmailReveal, 10);

    // Stripe redelivers on a timeout, and an operator can replay an event from the dashboard.
    $this->postJson('stripe/webhook', paidTopUp($team))->assertOk();
    $this->postJson('stripe/webhook', paidTopUp($team))->assertOk();

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(UsageAllowance::class)->added($team, BillingMetric::EmailReveal))->toBe(250);
    });

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('grants nothing for a session that completed without being paid', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $payload = topUpPayload($team, [
        'alima_topup_metric' => BillingMetric::EmailReveal->value,
        'alima_topup_quantity' => '250',
    ], status: 'unpaid');

    $this->postJson('stripe/webhook', $payload)->assertOk();

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('ignores a checkout session that is not one of ours', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // A subscription checkout, or a session created against the same Stripe account by something
    // else. There is nothing for this listener to say about it.
    $this->postJson('stripe/webhook', topUpPayload($team, []))->assertOk();

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('has no button to press when the Stripe prices are not configured', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::fakeGateway();

    // The catalogue's own rule: a deployment that has not created the prices gets no button,
    // rather than a button that fails at Stripe with the customer's card details already typed in.
    config()->set('billing.topups.email_reveal', [['quantity' => 250, 'amount' => 1_500]]);

    $this->actingAs($owner)->get('/billing')->assertInertia(
        fn ($page) => $page->where('topups', [])
    );

    $this->actingAs($owner)
        ->post('/billing/topup', ['metric' => 'email_reveal', 'option' => 0])
        ->assertNotFound();
});

it('grants nothing when Stripe is switched off', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // A webhook that slipped past the middleware — a misconfigured proxy, a route cached before the
    // switch flipped — must not change what a team may spend while an operator believes Stripe is
    // not being listened to. Bound rather than rebooted, because this listener reads the switch at
    // call time rather than at registration.
    app()->instance(StripeSwitch::class, new class implements StripeSwitch
    {
        public function isEnabled(): bool
        {
            return false;
        }
    });

    // **404 from the middleware, which is the outer of the two defences** — and the inner one is
    // what this test is really about: the listener reads the switch itself, so a webhook that got
    // past the route would still grant nothing.
    $this->postJson('stripe/webhook', paidTopUp($team))->assertNotFound();

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('ignores a top-up for a customer this deployment has never heard of', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // A test event, or a customer belonging to another environment pointed at this endpoint.
    $payload = paidTopUp($team);
    $payload['data']['object']['customer'] = 'cus_someone_else';

    $this->postJson('stripe/webhook', $payload)->assertOk();

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses to grant an event it cannot recognise again', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // No event id means no idempotency, and a redelivery would grant twice. Refusing is the safe
    // direction: the units are recoverable by hand, a double grant is not.
    $payload = paidTopUp($team);
    unset($payload['id']);

    $this->postJson('stripe/webhook', $payload)->assertOk();

    expect(UsageEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('re-snapshots the meter so a top-up shows on the billing screen', function (): void {
    [$owner, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::fakeGateway();
    Fixtures::capAllowance(BillingMetric::EmailReveal, 10);

    $this->postJson('stripe/webhook', paidTopUp($team))->assertOk();

    // `usage_meters.included` is re-snapshotted on every sync, which the screen does before it
    // renders — so the bar the Owner lands back on after Checkout is the one their purchase moved.
    $this->actingAs($owner)->get('/billing')->assertOk();

    $meter = UsageMeter::query()
        ->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('metric', BillingMetric::EmailReveal->value)
        ->firstOrFail();

    expect($meter->included)->toBe(260);
});
