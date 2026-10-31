<?php

declare(strict_types=1);

use App\Modules\Admin\Actions\SetCapabilityOverrideAction;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Admin\Services\GovernedCapabilityGate;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * Plan-driven capabilities (Phase 19b, ADR 0015).
 *
 * **The resolution order is the feature**, so it is asserted layer by layer and then in collision:
 * suspension beats the global kill switch beats the per-team override beats the plan beats Core's
 * permissive default. Every one of those four can be the reason a feature is off, and a product
 * where somebody has to guess which is a product nobody can support.
 */

function gate(): CapabilityGate
{
    // A fresh instance each time, because the governed gate memoises per team for the life of a
    // request — which is correct there and useless here.
    app()->forgetInstance(CapabilityGate::class);
    app()->forgetInstance(PlatformSettings::class);

    return app(CapabilityGate::class);
}

function killSwitch(array $capabilities): void
{
    app(PlatformSettings::class)->set('capabilities.disabled', $capabilities);
    app()->forgetInstance(PlatformSettings::class);
}

// --- The plan ------------------------------------------------------------------------------------

it('reads a capability off the plan', function (): void {
    [, $team] = Billing::team();

    // Signals is Team and up — which is what the price list's own copy promises.
    Billing::subscribe($team, PlanKey::Starter);
    expect(gate()->allows($team, Capability::Signals))->toBeFalse();

    // `moveToPlan`, not a second `subscribe`: a team has one subscription, so the unique
    // `stripe_id` is correct and this is what a plan swap actually does to the row.
    Billing::moveToPlan($team, PlanKey::Team);
    expect(gate()->allows($team, Capability::Signals))->toBeTrue();
});

it('leaves a capability on when no plan declares any', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    // A plan with no `capabilities` key at all falls through to Core's permissive default. A
    // missing configuration is not a product decision, and the alternative would switch six
    // features off for everybody the moment this shipped.
    config()->set('billing.plans.starter.capabilities', null);
    app(PlanRegistry::class)->flush();

    expect(gate()->allows($team, Capability::Signals))->toBeTrue();
});

// --- The per-team override -----------------------------------------------------------------------

it('lets an override give a feature the plan excludes', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    expect(gate()->allows($team, Capability::Signals))->toBeFalse();

    // The pilot customer. A row rather than a plan edit, so the reason and the expiry travel with
    // the exception instead of giving Signals to every Starter customer.
    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: true,
        reason: 'Signals pilot agreed on the onboarding call; review in January.',
        expiresAt: null,
        operator: Fixtures::operator(),
    );

    expect(gate()->allows($team, Capability::Signals))->toBeTrue();
});

it('lets an override take away a feature the plan includes', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    expect(gate()->allows($team, Capability::Signals))->toBeTrue();

    // The other direction, which is why `enabled` is a boolean rather than the row's existence: a
    // customer whose own compliance team asked us to switch something off.
    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: false,
        reason: 'Their compliance team asked us to switch it off pending review.',
        expiresAt: null,
        operator: Fixtures::operator(),
    );

    expect(gate()->allows($team, Capability::Signals))->toBeFalse();
});

it('stops honouring an override once it expires', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: true,
        reason: 'Two-week pilot agreed on the call.',
        expiresAt: now()->addDays(14),
        operator: Fixtures::operator(),
    );

    expect(gate()->allows($team, Capability::Signals))->toBeTrue();

    $this->travel(15)->days();

    // The expiry is the point: a goodwill exception should stop being one on its own, rather than
    // becoming permanent because nobody remembered.
    expect(gate()->allows($team, Capability::Signals))->toBeFalse();
});

it('goes back to the plan when an override is cleared', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);
    $operator = Fixtures::operator();

    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: false,
        reason: 'Switched off for a review.',
        expiresAt: null,
        operator: $operator,
    );
    expect(gate()->allows($team, Capability::Signals))->toBeFalse();

    app(SetCapabilityOverrideAction::class)->clear($team, Capability::Signals, $operator);

    expect(gate()->allows($team, Capability::Signals))->toBeTrue();
});

// --- The order ------------------------------------------------------------------------------------

it('lets the kill switch beat a per-team override', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: true,
        reason: 'Explicitly granted yesterday, and it must not survive an incident.',
        expiresAt: null,
        operator: Fixtures::operator(),
    );

    killSwitch([Capability::Signals->value]);

    // **The order that matters during an incident.** An operator reaching for a kill switch is
    // dealing with something broken, and having to hunt down every team override first is how an
    // incident becomes an outage.
    expect(gate()->allows($team, Capability::Signals))->toBeFalse()
        // And only the one named.
        ->and(gate()->allows($team, Capability::AiInsights))->toBeTrue();
});

it('lets suspension beat everything, including the override and the plan', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    app(SetCapabilityOverrideAction::class)->handle(
        $team,
        Capability::Signals,
        enabled: true,
        reason: 'Granted, and irrelevant the moment the tenant is suspended.',
        expiresAt: null,
        operator: Fixtures::operator(),
    );

    Fixtures::suspend($team);

    // A suspended team is not a feature question.
    foreach (Capability::cases() as $capability) {
        expect(gate()->allows($team, $capability))->toBeFalse();
    }
});

// --- Where it bites --------------------------------------------------------------------------------

it('refuses a capability from a queued job with no request behind it', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter); // No Signals.

    $watch = app(TeamContext::class)->run(
        $team,
        fn (): SignalWatch => SignalWatch::factory()->create(['team_id' => $team->getKey()]),
    );

    // ADR 0012's argument, which is why the check is in the Action and not in middleware: a queued
    // discovery job and a scheduled watch spend a team's allowance with no request to guard.
    expect(fn () => app(RunSignalWatchAction::class)->handle($watch))
        ->toThrow(CapabilityException::class, 'not included on your plan');

    // And it is refused **before** the run is claimed, so there is no failed row to read as the
    // news provider having broken.
    expect(SignalRun::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses the screen too, with a sentence about the plan', function (): void {
    [$owner, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    // The courtesy half: a member who clicks something their plan excludes gets a sentence, not a
    // half-rendered page or a 500 from three layers down.
    $this->actingAs($owner)->get(route('signals.index'))->assertRedirect();

    Billing::moveToPlan($team, PlanKey::Team);

    $this->actingAs($owner)->get(route('signals.index'))->assertOk();
});

it('tells the nav what not to advertise', function (): void {
    [$owner, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A sidebar link to a screen that will refuse is a promise the product then breaks.
            ->where('capabilities.signals', false)
            ->where('capabilities.ai_insights', true));
});

it('declares an enforcement point for every capability', function (): void {
    // The rule this enum is governed by: a capability that is declared but not enforced is worse
    // than one that does not exist, because the pricing page would promise a gate that is not
    // there and nobody would find out until a customer used a feature they had not bought.
    foreach (Capability::cases() as $capability) {
        expect($capability->enforcedAt())->not->toBe('')
            ->and($capability->refusal())->not->toBe('')
            ->and($capability->label())->not->toBe('');
    }
});

it('is only overridable by a role that holds settings.manage', function (): void {
    [, $team] = Fixtures::tenant();

    foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))
            ->post('/admin/teams/'.$team->getKey().'/capabilities', [
                'capability' => Capability::Signals->value,
                'enabled' => '1',
                'reason' => 'Trying it on without the ability for it.',
            ])
            ->assertForbidden();
    }

    expect(CapabilityOverride::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('memoises per team within a request and forgets between them', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    $instance = app(CapabilityGate::class);
    expect($instance->allows($team, Capability::Signals))->toBeTrue();

    killSwitch([Capability::Signals->value]);

    // Still memoised, which is correct inside one request — the guards ask on every discovery run
    // and the answer must not change mid-request.
    expect($instance->allows($team, Capability::Signals))->toBeTrue();

    if ($instance instanceof GovernedCapabilityGate) {
        $instance->forget();
    }

    expect(gate()->allows($team, Capability::Signals))->toBeFalse();

    expect($team)->toBeInstanceOf(Team::class);
});
