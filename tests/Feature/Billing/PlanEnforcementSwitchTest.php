<?php

declare(strict_types=1);

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\Auth\Contracts\SeatLimitSource;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\OverageReporter;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * The two switches that turn billing off (`config/billing.php`).
 *
 * They answer **different questions**, and this file is arranged around that because conflating
 * them is the documented mistake: `stripe.enabled` is *will we talk to Stripe*, and
 * `enforce_plans` is *does a plan decide what a team may do*. Neither implies the other.
 *
 * Both default to **on**, so the rest of the suite — and any deployment that does not say otherwise
 * — exercises the enforced behaviour.
 */

/**
 * Boot the application again with these environment variables.
 *
 * Both switches are read in `BillingServiceProvider::register()` — the right place for a deployment
 * switch, and the reason a test cannot simply set config and carry on. Two consequences, both
 * learned the hard way here:
 *
 *  - It has to be the **environment**, not `config()`. Rebuilding the container reloads config from
 *    the files, which discards a runtime override before the provider ever reads it.
 *  - Rebuilding drops the in-memory SQLite connection, schema and all, so it re-migrates. Fixtures
 *    are therefore created **after** this call, never before.
 *
 * (If this file ever fails with the switch apparently ignored, check for a cached config:
 * `artisan optimize:clear`. `composer test` clears it; a bare `artisan test` does not.)
 *
 * @param  array<string, string>  $env
 */
function reboot(array $env): void
{
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    test()->refreshApplication();

    Artisan::call('migrate');
}

/**
 * Put the environment back.
 *
 * Without this the next *file* in the same process inherits the switch, which would turn plan
 * enforcement off for a suite that is asserting it.
 */
afterEach(function (): void {
    foreach (['BILLING_ENFORCE_PLANS', 'BILLING_STRIPE_ENABLED'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

// --- Plan enforcement off ----------------------------------------------------------------------

it('allows every capability when plans are not enforced, on the most restricted plan there is', function (): void {
    reboot(['BILLING_ENFORCE_PLANS' => 'false']);

    [, $team] = Billing::team();

    // Free declares the fewest capabilities, so it is the plan that proves the switch rather than
    // the defaults.
    Billing::onFreePlan($team);

    $gate = app(CapabilityGate::class);

    foreach (Capability::cases() as $capability) {
        expect($gate->allows($team->refresh(), $capability))
            ->toBeTrue($capability->value.' should not be gated when plans are not enforced');
    }
});

it('leaves discovery unquotaed and seats unlimited when plans are not enforced', function (): void {
    reboot(['BILLING_ENFORCE_PLANS' => 'false']);

    [, $team] = Billing::team();
    Billing::onFreePlan($team);

    // What is left standing is the permissive default each owning module ships —
    // `StubLeadQuotaSource` and `UnlimitedSeatLimit` — because Billing never binds over them.
    expect(app(LeadQuotaSource::class)->quotaFor($team->refresh())->limit)->toBeNull()
        ->and(app(SeatLimitSource::class)->seatsFor($team->refresh()))->toBeNull();
});

it('still applies the AI credit allowance, because that is a cost guard and not a plan', function (): void {
    reboot(['BILLING_ENFORCE_PLANS' => 'false']);

    [, $team] = Billing::team();

    // `StubCreditSource` answers from `ai-platform.credits.*`, which every tenant has had since
    // Phase 6. Turning plans off must not quietly make AI spend infinite.
    expect(app(CreditSource::class)->allowanceFor($team->refresh())->limitMicros)
        ->toBeGreaterThan(0);
});

it('still knows what plan a team is on, so the screens and reporting keep working', function (): void {
    reboot(['BILLING_ENFORCE_PLANS' => 'false']);

    [, $team] = Billing::team();
    Billing::onFreePlan($team);

    // Enforcement is off; the plan itself is not gone. The billing screen, the pricing page and
    // platform reporting all read this.
    expect(app(PlanResolver::class)->for($team->refresh())->plan->key)->toBe(PlanKey::Free);
});

it('still refuses a capability an operator has turned off', function (): void {
    reboot(['BILLING_ENFORCE_PLANS' => 'false']);

    [, $team] = Billing::team();

    // `GovernedCapabilityGate` decorates Core's default whether or not plans are enforced: an
    // operator's kill switch is a deliberate act, not a plan limit.
    app(PlatformSettings::class)->set('capabilities.disabled', [Capability::SignalComposer->value]);

    app()->forgetInstance(CapabilityGate::class);
    app()->forgetInstance(PlatformSettings::class);

    expect(app(CapabilityGate::class)->allows($team->refresh(), Capability::SignalComposer))
        ->toBeFalse();
});

// --- Enforcement on, which is what ships -------------------------------------------------------

it('gates on the plan by default, so the switch has to be asked for', function (): void {
    [, $team] = Billing::team();
    Billing::onFreePlan($team);

    expect(config('billing.enforce_plans'))->toBeTrue()
        // Free has a lead allowance, so the quota is a number rather than null.
        ->and(app(LeadQuotaSource::class)->quotaFor($team->refresh())->limit)->not->toBeNull();
});

// --- Stripe off --------------------------------------------------------------------------------

it('refuses every Stripe write when Stripe is switched off in config', function (): void {
    reboot(['BILLING_STRIPE_ENABLED' => 'false']);

    [, $team] = Billing::team();

    expect(app(StripeSwitch::class)->isEnabled())->toBeFalse();

    $gateway = app(BillingGateway::class);

    // A read answers empty; every write refuses by name rather than timing out inside a checkout.
    expect($gateway->invoices($team->refresh()))->toBe([]);

    $plan = app(PlanRegistry::class)->get(PlanKey::Team);

    expect(fn () => $gateway->checkoutUrl($team->refresh(), $plan, 'https://a', 'https://b'))
        ->toThrow(BillingException::class);
});

it('leaves entitlement alone when Stripe is off but plans are enforced', function (): void {
    reboot(['BILLING_STRIPE_ENABLED' => 'false', 'BILLING_ENFORCE_PLANS' => 'true']);

    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    // The invoiced-customer case the module was designed for: we will not talk to Stripe, and a
    // team on Scale is still on Scale with its allowances intact.
    expect(app(PlanResolver::class)->for($team->refresh())->plan->key)->toBe(PlanKey::Scale)
        ->and(app(StripeSwitch::class)->isEnabled())->toBeFalse();
});

it('rejects the Stripe webhook when Stripe is off', function (): void {
    reboot(['BILLING_STRIPE_ENABLED' => 'false']);

    // **404, not 503**, deliberately: the refusal tells an unauthenticated caller nothing about
    // whether the endpoint is there. See `RefuseStripeWhenDisabled`.
    $this->postJson('/stripe/webhook', ['type' => 'invoice.paid'])->assertNotFound();
});

it('does not meter overage to Stripe when it is off', function (): void {
    reboot(['BILLING_STRIPE_ENABLED' => 'false']);

    [, $team] = Billing::team();

    // A no-op with a log line, not an error: reporting usage against a subscription that does not
    // exist is how the nightly command ends up failing every morning.
    expect(app(OverageReporter::class)->report($team->refresh()))->toBe(0);
});
