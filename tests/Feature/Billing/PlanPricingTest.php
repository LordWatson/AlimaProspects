<?php

declare(strict_types=1);

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Services\PlanRegistry;
use Carbon\CarbonImmutable;

/**
 * The price list and the two value objects every limit in the app is decided by.
 *
 * Under `Feature` rather than `Unit` despite touching no database: the price list lives in config,
 * and `tests/Unit` in this repo is framework-free by convention.
 *
 * The cheapest tests in the module and the ones that protect the most money: the difference
 * between an allowance and a ceiling is what decides whether a paying team keeps working, and the
 * difference between a metric's unit and the AI ledger's micro-units is a factor of a thousand on
 * an invoice.
 */

// --- The price list -------------------------------------------------------------------------------

it('reads every plan from config, cheapest first', function (): void {
    $plans = app(PlanRegistry::class)->all();

    expect(array_map(static fn (PlanData $plan): string => $plan->key->value, $plans))
        ->toBe(['free', 'starter', 'team', 'scale', 'enterprise']);
});

it('offers only the plans somebody can actually buy', function (): void {
    $keys = array_map(
        static fn (PlanData $plan): string => $plan->key->value,
        app(PlanRegistry::class)->purchasable(),
    );

    // Free has nothing to buy and Enterprise is sold by a person; neither belongs on a checkout
    // button, and a pricing screen that offered them would produce a Stripe error for a reason
    // that is not the customer's fault.
    expect($keys)->toBe(['starter', 'team', 'scale']);
});

it('maps a Stripe price back to its plan, and shrugs at one it does not sell', function (): void {
    $registry = app(PlanRegistry::class);
    $team = $registry->get(PlanKey::Team);

    expect($registry->fromStripePrice($team->stripePrice)?->key)->toBe(PlanKey::Team)
        // Null rather than an exception: a team grandfathered onto a withdrawn price still has a
        // live subscription, and throwing here would lock them out of the screen that moves them.
        ->and($registry->fromStripePrice('price_withdrawn_2019'))->toBeNull()
        ->and($registry->fromStripePrice(null))->toBeNull();
});

it('prices in minor units', function (): void {
    // £49 / £129 / £379, as agreed. A float here would be a rounding bug on an invoice.
    expect(app(PlanRegistry::class)->get(PlanKey::Starter)->amount)->toBe(4_900)
        ->and(app(PlanRegistry::class)->get(PlanKey::Team)->amount)->toBe(12_900)
        ->and(app(PlanRegistry::class)->get(PlanKey::Scale)->amount)->toBe(37_900)
        ->and(app(PlanRegistry::class)->get(PlanKey::Enterprise)->amount)->toBeNull();
});

it('matches the seat counts to the published prices', function (): void {
    expect(app(PlanRegistry::class)->get(PlanKey::Starter)->seats)->toBe(1)
        ->and(app(PlanRegistry::class)->get(PlanKey::Team)->seats)->toBe(3)
        ->and(app(PlanRegistry::class)->get(PlanKey::Scale)->seats)->toBe(10)
        ->and(app(PlanRegistry::class)->get(PlanKey::Enterprise)->seats)->toBeNull();
});

// --- Allowance vs ceiling -------------------------------------------------------------------------

it('stops a plan without overage at its allowance', function (): void {
    $free = app(PlanRegistry::class)->get(PlanKey::Free);

    expect($free->overage)->toBeFalse()
        ->and($free->ceilingFor(BillingMetric::Leads))->toBe($free->leads);
});

it('lets a metered plan run to a multiple of its allowance, and no further', function (): void {
    config()->set('billing.overage.multiplier', 2.0);

    $starter = app(PlanRegistry::class)->get(PlanKey::Starter);

    // The ceiling exists so a metered plan cannot quietly become an unbounded invoice.
    expect($starter->ceilingFor(BillingMetric::Leads))->toBe(($starter->leads ?? 0) * 2);
});

it('has no ceiling at all on an unlimited plan', function (): void {
    $enterprise = app(PlanRegistry::class)->get(PlanKey::Enterprise);

    expect($enterprise->ceilingFor(BillingMetric::Leads))->toBeNull()
        ->and($enterprise->allowanceFor(BillingMetric::AiCredits))->toBeNull();
});

// --- MetricUsage ----------------------------------------------------------------------------------

it('keeps a metered plan working between the allowance and the ceiling', function (): void {
    $usage = new MetricUsage(
        metric: BillingMetric::Leads,
        used: 600,
        included: 500,
        ceiling: 1_000,
        overageAllowed: true,
    );

    // This is the whole point of overage: past the included allowance the team is working and
    // being billed, not stopped.
    expect($usage->isExhausted())->toBeFalse()
        ->and($usage->overage())->toBe(100)
        ->and($usage->remaining())->toBe(0)
        ->and($usage->headroom())->toBe(400);
});

it('stops at the ceiling even on a metered plan', function (): void {
    $usage = new MetricUsage(
        metric: BillingMetric::Leads,
        used: 1_000,
        included: 500,
        ceiling: 1_000,
        overageAllowed: true,
    );

    expect($usage->isExhausted())->toBeTrue()
        ->and($usage->headroom())->toBe(0);
});

it('bills nothing for going over on a plan that does not meter', function (): void {
    $usage = new MetricUsage(
        metric: BillingMetric::Leads,
        used: 40,
        included: 25,
        ceiling: 25,
        overageAllowed: false,
    );

    // The guard should have stopped them at 25; whatever got through is not something to invoice
    // for, because nobody agreed to pay for it.
    expect($usage->overage())->toBe(0)
        ->and($usage->isExhausted())->toBeTrue();
});

it('never shows an unlimited plan as a full bar', function (): void {
    $usage = new MetricUsage(
        metric: BillingMetric::AiCredits,
        used: 9_000_000,
        included: null,
        ceiling: null,
        overageAllowed: false,
    );

    // A bar at 100% reads as a warning. There is nothing to warn about.
    expect($usage->usedPercent())->toBe(0)
        ->and($usage->remaining())->toBeNull()
        ->and($usage->isExhausted())->toBeFalse();
});

// --- Units ----------------------------------------------------------------------------------------

it('converts AI credits to and from the ledger’s micro-units', function (): void {
    config()->set('ai-platform.credits.micros_per_credit', 1_000);

    expect(BillingMetric::AiCredits->fromMicros(2_500))->toBe(2)
        ->and(BillingMetric::AiCredits->toMicros(2))->toBe(2_000)
        // Leads are already whole leads — converting them would be a factor-of-1000 bug.
        ->and(BillingMetric::Leads->fromMicros(2_500))->toBe(2_500)
        ->and(BillingMetric::Leads->toMicros(7))->toBe(7);
});

it('survives a credit conversion rate of zero', function (): void {
    // A misconfigured `.env` must not divide by zero on every AI call.
    config()->set('ai-platform.credits.micros_per_credit', 0);

    expect(BillingMetric::AiCredits->fromMicros(5_000))->toBe(5_000);
});

// --- The period -----------------------------------------------------------------------------------

it('measures the calendar month in UTC, whatever the app timezone is', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-17 23:30:00', 'UTC'));

    $period = BillingPeriod::containing();

    expect($period->start->toDateTimeString())->toBe('2026-03-01 00:00:00')
        ->and($period->end->toDateTimeString())->toBe('2026-04-01 00:00:00')
        ->and($period->start->getTimezone()->getName())->toBe('UTC');

    CarbonImmutable::setTestNow();
});

it('steps back a month for the period that has just closed', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 00:05:00', 'UTC'));

    // The first run of a new month still has to report what December did.
    expect(BillingPeriod::containing()->previous()->start->toDateString())->toBe('2026-02-01');

    CarbonImmutable::setTestNow();
});

// --- Subscription states --------------------------------------------------------------------------

it('keeps a past-due team working while Stripe retries the card', function (): void {
    // Stripe dunning runs for a fortnight and then cancels. Cutting a team off on the first failed
    // retry turns an expired card into a churn event.
    expect(SubscriptionState::PastDue->isEntitled())->toBeTrue()
        ->and(SubscriptionState::PastDue->needsAttention())->toBeTrue()
        ->and(SubscriptionState::GracePeriod->isEntitled())->toBeTrue()
        ->and(SubscriptionState::Cancelled->isEntitled())->toBeFalse()
        ->and(SubscriptionState::None->isEntitled())->toBeFalse();
});
