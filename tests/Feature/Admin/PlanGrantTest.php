<?php

declare(strict_types=1);

use App\Modules\Admin\Actions\GrantPlanAction;
use App\Modules\Admin\Actions\RevokePlanGrantAction;
use App\Modules\Admin\Actions\SetBillingModeAction;
use App\Modules\Admin\Actions\SetCapabilityOverrideAction;
use App\Modules\Admin\Data\PlanGrantData;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Services\OverageReporter;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * Plan grants and per-team billing modes (Phase 19b).
 *
 * The precedence is the thing under test, and it is what makes "turn Stripe off" and "take
 * somebody's plan away" two different acts: **grant (inside its window) → live Stripe subscription
 * → trial → default plan.**
 */

function grant(Team $team, PlanKey $plan, array $overrides = []): PlanGrant
{
    return app(GrantPlanAction::class)->handle($team, new PlanGrantData(
        plan: $plan,
        note: $overrides['note'] ?? 'Signed contract, ref CS-412.',
        startsAt: $overrides['startsAt'] ?? CarbonImmutable::now('UTC')->subMinute(),
        endsAt: $overrides['endsAt'] ?? null,
        seats: $overrides['seats'] ?? null,
        leads: $overrides['leads'] ?? null,
        credits: $overrides['credits'] ?? null,
    ), Fixtures::operator());
}

it('entitles a team with no Stripe subscription at all', function (): void {
    [, $team] = Fixtures::tenant();
    Billing::onFreePlan($team);

    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Free);

    grant($team, PlanKey::Scale);

    $entitlements = app(PlanResolver::class)->for($team);

    expect($entitlements->plan->key)->toBe(PlanKey::Scale)
        // Its own state, because the billing screen has to say something different: there is no
        // card to change, no invoice history and nothing to cancel.
        ->and($entitlements->state)->toBe(SubscriptionState::Granted)
        ->and($entitlements->state->isEntitled())->toBeTrue();
});

it('outranks a live Stripe subscription', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Starter);

    grant($team, PlanKey::Enterprise);

    // The order is the point: a grant is how an operator fixes a billing problem without touching
    // Stripe, and it is what lets an invoiced contract beat whatever a stale subscription says.
    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Enterprise);
});

it('gives Enterprise the numbers a contract actually says', function (): void {
    [, $team] = Fixtures::tenant();

    // The price list gives Enterprise null seats, leads and credits, which `PlanData` reads as
    // *unlimited* — but a signed contract says 25 seats and 40,000 leads.
    expect(app(PlanResolver::class)->planFor($team)->seats)->not->toBe(25);

    grant($team, PlanKey::Enterprise, ['seats' => 25, 'leads' => 40_000]);

    $plan = app(PlanResolver::class)->planFor($team);

    expect($plan->seats)->toBe(25)
        ->and($plan->leads)->toBe(40_000)
        // Null means "use the plan's own number", **not** unlimited — and Enterprise's own credits
        // are null, so this one genuinely is unlimited.
        ->and($plan->credits)->toBeNull();
});

it('stops entitling once the window closes', function (): void {
    [, $team] = Fixtures::tenant();
    Billing::onFreePlan($team);

    grant($team, PlanKey::Scale, ['endsAt' => CarbonImmutable::now('UTC')->addDays(30)]);

    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Scale);

    $this->travel(31)->days();
    app(PlanResolver::class)->forget($team);

    // The expiry is why a goodwill upgrade does not quietly become permanent.
    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Free);
});

it('does not entitle before it starts', function (): void {
    [, $team] = Fixtures::tenant();
    Billing::onFreePlan($team);

    grant($team, PlanKey::Scale, ['startsAt' => CarbonImmutable::now('UTC')->addDays(7)]);

    expect(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Free);
});

it('replaces an earlier grant rather than stacking two', function (): void {
    [, $team] = Fixtures::tenant();

    grant($team, PlanKey::Starter);
    grant($team, PlanKey::Scale);

    $inForce = PlanGrant::query()->withoutGlobalScopes()->whereNull('revoked_at')->get();

    // One in force at a time. Two overlapping grants would make the answer depend on a tiebreak
    // rather than on a decision — and the revoked one stays, because what a tenant was entitled to
    // last month is worth being able to answer.
    expect($inForce)->toHaveCount(1)
        ->and($inForce->first()->plan)->toBe(PlanKey::Scale)
        ->and(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('keeps the record when a grant is revoked', function (): void {
    [, $team] = Fixtures::tenant();
    Billing::onFreePlan($team);

    $granted = grant($team, PlanKey::Scale);

    app(RevokePlanGrantAction::class)->handle($granted, Fixtures::operator(), 'Contract ended.');

    expect($granted->refresh()->revoked_at)->not->toBeNull()
        ->and(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Free);
});

it('audits a grant with the numbers on it', function (): void {
    [, $team] = Fixtures::tenant();

    grant($team, PlanKey::Enterprise, ['seats' => 25, 'leads' => 40_000]);

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.team.plan_granted')
        ->latest('id')
        ->first();

    // The numbers, because a grant's whole point can be that it overrides them.
    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['plan'])->toBe(PlanKey::Enterprise->value)
        ->and($entry->properties['seats'])->toBe(25)
        ->and($entry->properties['leads'])->toBe(40_000);
});

// --- Billing mode ---------------------------------------------------------------------------------

it('does not change entitlement when the mode changes', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    app(SetBillingModeAction::class)->handle($team, BillingMode::Manual, Fixtures::operator());

    // The separation this column exists for. Moving a team to invoiced billing stops checkout and
    // metering; it does not take their plan away.
    expect(BillingMode::of($team->refresh()))->toBe(BillingMode::Manual)
        ->and(app(PlanResolver::class)->planFor($team)->key)->toBe(PlanKey::Scale);
});

it('offers no checkout to an invoiced tenant', function (): void {
    [$owner, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Scale);

    app(SetBillingModeAction::class)->handle($team, BillingMode::Manual, Fixtures::operator());

    $this->actingAs($owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('self_serve', false)
            ->where('can.checkout', false)
            // And they keep the plan they are contracted for.
            ->where('subscription.plan.key', PlanKey::Scale->value));
});

it('meters no overage for a tenant Stripe is not charging', function (): void {
    [, $team] = Billing::team();
    Billing::subscribe($team, PlanKey::Starter);

    app(SetBillingModeAction::class)->handle($team, BillingMode::Manual, Fixtures::operator());

    // An invoiced customer's overage is a conversation, not a metered event — and reporting usage
    // against a subscription Stripe is not billing is how the command ends up logging an error
    // every morning.
    expect(app(OverageReporter::class)->report($team))->toBe(0);
});

it('is reachable only by a role that holds billing.manage', function (): void {
    [, $team] = Fixtures::tenant();

    foreach ([AdminRole::Support, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))
            ->get('/admin/teams/'.$team->getKey().'/billing')
            ->assertForbidden();
    }

    // Finance is the role this screen exists for.
    $this->actingAs(Fixtures::operator(AdminRole::Finance))
        ->get('/admin/teams/'.$team->getKey().'/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/teams/Billing')
            ->has('entitlements')
            ->has('capabilities'));
});

it('grants from the screen, and insists on a reason', function (): void {
    [, $team] = Fixtures::tenant();
    $operator = Fixtures::operator(AdminRole::Finance);

    $this->actingAs($operator)
        ->post('/admin/teams/'.$team->getKey().'/billing/grants', [
            'plan' => PlanKey::Scale->value,
            'note' => 'short',
        ])
        ->assertSessionHasErrors('note');

    expect(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($operator)
        ->post('/admin/teams/'.$team->getKey().'/billing/grants', [
            'plan' => PlanKey::Scale->value,
            'note' => 'Signed order form, 12 months, ref CS-412.',
        ])
        ->assertRedirect();

    expect(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses to revoke another tenant’s grant through the wrong URL', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha');
    [, $beta] = Fixtures::tenant('Beta');

    $betaGrant = grant($beta, PlanKey::Scale);

    // Route binding on a tenant-owned model from outside team scope will happily find another
    // team's row — the panel has no global scope to stop it, so the controller has to.
    $this->actingAs(Fixtures::operator(AdminRole::Finance))
        ->delete('/admin/teams/'.$alpha->getKey().'/billing/grants/'.$betaGrant->getKey())
        ->assertNotFound();

    expect($betaGrant->refresh()->revoked_at)->toBeNull();
});

it('hides a grant and an override from another tenant’s queries', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha');
    [, $beta] = Fixtures::tenant('Beta');

    grant($beta, PlanKey::Scale);

    app(SetCapabilityOverrideAction::class)->handle(
        $beta,
        Capability::Signals,
        enabled: true,
        reason: 'Pilot agreed on the onboarding call.',
        expiresAt: null,
        operator: Fixtures::operator(),
    );

    // CLAUDE.md §2: every tenant model needs a test proving cross-tenant access fails. Both of
    // these carry `BelongsToTeam`, and the panel's reads work by *removing* that scope — so the
    // thing worth pinning is that the scope is still there for everybody else. A team's own
    // context must not see another's contract or another's feature exception.
    app(TeamContext::class)->run($alpha, function (): void {
        expect(PlanGrant::query()->count())->toBe(0)
            ->and(CapabilityOverride::query()->count())->toBe(0);
    });

    app(TeamContext::class)->run($beta, function (): void {
        expect(PlanGrant::query()->count())->toBe(1)
            ->and(CapabilityOverride::query()->count())->toBe(1);
    });
});

it('entitles only the team the grant names', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha');
    [, $beta] = Fixtures::tenant('Beta');
    Billing::onFreePlan($alpha);
    Billing::onFreePlan($beta);

    grant($beta, PlanKey::Enterprise, ['seats' => 25]);

    // The resolver reads without the scope, because it runs from jobs and the scheduler. That is
    // the right call and it is also the one place a missing `team_id` would silently hand one
    // tenant another's contract.
    expect(app(PlanResolver::class)->planFor($beta)->key)->toBe(PlanKey::Enterprise)
        ->and(app(PlanResolver::class)->planFor($alpha)->key)->toBe(PlanKey::Free)
        ->and(app(PlanResolver::class)->planFor($alpha)->seats)->not->toBe(25);
});

it('replaces a future-dated grant rather than stacking behind it', function (): void {
    [, $team] = Fixtures::tenant();

    grant($team, PlanKey::Enterprise, ['startsAt' => CarbonImmutable::now('UTC')->addMonth()]);
    grant($team, PlanKey::Team);

    // `inForce()` left a future-dated grant alone, so this left two unrevoked rows and which one
    // won next month became a tiebreak rather than a decision.
    expect(PlanGrant::query()->withoutGlobalScopes()->whereNull('revoked_at')->count())->toBe(1)
        ->and(PlanGrant::query()->withoutGlobalScopes()->whereNull('revoked_at')->first()->plan)
        ->toBe(PlanKey::Team);
});

it('revokes a grant once, keeping the date it actually ended', function (): void {
    [, $team] = Fixtures::tenant();
    $granted = grant($team, PlanKey::Scale);
    $operator = Fixtures::operator();

    app(RevokePlanGrantAction::class)->handle($granted, $operator, 'Contract ended.');
    $endedAt = $granted->refresh()->revoked_at;

    $this->travel(5)->days();
    app(RevokePlanGrantAction::class)->handle($granted, $operator, 'Pressed twice.');

    // Restamping would move the date a tenant's entitlement ended — the one thing the kept row
    // exists to answer — and write a second audit entry for a decision made once.
    expect($granted->refresh()->revoked_at->toIso8601String())->toBe($endedAt->toIso8601String())
        ->and(AuditLog::query()
            ->withoutGlobalScopes()
            ->where('action', 'admin.team.plan_grant_revoked')
            ->count())->toBe(1);
});
