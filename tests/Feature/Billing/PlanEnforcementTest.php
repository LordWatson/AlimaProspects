<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\AiPlatform\Services\AiCreditGuard;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\PlanCreditSource;
use App\Modules\Billing\Services\PlanLeadQuotaSource;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Services\DiscoveryLimitGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The three seams Billing fills in, and the limits behind them (Phase 16).
 *
 * These are tested through the **existing guards** rather than through Billing's own services,
 * because that is the claim worth proving: Phase 5 and Phase 7 shipped `AiCreditGuard` and
 * `DiscoveryLimitGuard` against stubs, and the promise was that real limits would arrive as a
 * binding with no caller changing. A test that called `PlanCreditSource` directly would pass
 * whether or not that promise had been kept.
 */

/**
 * Record `$leads` new companies for a team this period, the way a finished discovery run does.
 */
function recordLeads(Team $team, int $leads): void
{
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_created' => $leads,
        'completed_at' => now(),
    ]));
}

/**
 * Record `$credits` of AI spend for a team this period, the way a finished AI call does.
 */
function recordCredits(Team $team, int $credits): void
{
    app(TeamContext::class)->run($team, fn () => AiRequest::factory()->create([
        'team_id' => $team->getKey(),
        'status' => AiRequestStatus::Success,
        'cost_micros' => BillingMetric::AiCredits->toMicros($credits),
        'created_at' => now(),
    ]));
}

// --- The bindings ---------------------------------------------------------------------------------

it('replaces the Phase 5 and Phase 7 stubs with plan-backed sources', function (): void {
    expect(app(CreditSource::class))->toBeInstanceOf(PlanCreditSource::class)
        ->and(app(LeadQuotaSource::class))->toBeInstanceOf(PlanLeadQuotaSource::class);
});

// --- Lead discovery -------------------------------------------------------------------------------

it('blocks discovery once a free team has used its lead allowance', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);

    $allowance = (int) config('billing.plans.free.leads');
    recordLeads($team, $allowance);

    // The Free plan has no overage: it stops at the allowance, which is what a free tier is for.
    expect(fn () => app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team))
        ->toThrow(LeadSourcingException::class);
});

it('lets a metered plan keep discovering past its allowance', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $allowance = (int) config('billing.plans.starter.leads');
    recordLeads($team, $allowance + 10);

    // Paying for overage means the work continues and the excess is billed — stopping them at the
    // allowance would be charging for something we then refuse to do.
    app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team);

    expect(app(UsageLedger::class)->usage($team, BillingMetric::Leads)->overage())->toBe(10);
});

it('stops a metered plan at the overage ceiling', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $allowance = (int) config('billing.plans.starter.leads');
    $multiplier = (float) config('billing.overage.multiplier');

    recordLeads($team, (int) ceil($allowance * $multiplier));

    // The ceiling is what stops a metered plan becoming an invoice nobody agreed to.
    expect(fn () => app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team))
        ->toThrow(LeadSourcingException::class);
});

it('clamps a run to the companies the team can still have rather than refusing it', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);

    $allowance = (int) config('billing.plans.free.leads');
    recordLeads($team, $allowance - 4);

    // A team with four left who asks for fifty gets four. Refusing the run outright would make
    // the last of an allowance unusable.
    expect(app(DiscoveryLimitGuard::class)->quotaFor($team)->clamp(50))->toBe(4);
});

it('never limits a team on an unlimited plan', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Enterprise);

    recordLeads($team, 500_000);

    app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team);

    expect(app(DiscoveryLimitGuard::class)->quotaFor($team)->limit)->toBeNull()
        ->and(app(DiscoveryLimitGuard::class)->quotaFor($team)->clamp(1_000))->toBe(1_000);
});

// --- AI credits -----------------------------------------------------------------------------------

it('blocks AI work once a free team has spent its credits', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);

    recordCredits($team, (int) config('billing.plans.free.credits'));

    expect(fn () => app(AiCreditGuard::class)->ensureWithinAllowance($team))
        ->toThrow(AiCreditExceededException::class);
});

it('lets a metered plan keep spending past its included credits', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Team);

    recordCredits($team, (int) config('billing.plans.team.credits') + 500);

    app(AiCreditGuard::class)->ensureWithinAllowance($team);

    expect(app(UsageLedger::class)->usage($team, BillingMetric::AiCredits)->overage())->toBe(500);
});

it('leaves system work with no team alone', function (): void {
    // A scheduled task with no tenant has no plan to enforce against; refusing it would stop the
    // platform's own housekeeping.
    app(AiCreditGuard::class)->ensureWithinAllowance(null);
})->throwsNoExceptions();

// --- Trials ---------------------------------------------------------------------------------------

it('gives a brand-new team the trial plan’s allowances with no card', function (): void {
    [, $team] = Fixtures::team();

    $entitlements = app(PlanResolver::class)->for($team);

    expect($entitlements->isTrialing())->toBeTrue()
        ->and($entitlements->isSubscribed())->toBeFalse()
        ->and($entitlements->plan->key->value)->toBe(config('billing.trial.plan'))
        ->and($entitlements->trialDaysLeft())->toBeGreaterThan(0);
});

it('drops a team to the free allowance when the trial lapses, without locking it out', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);

    $entitlements = app(PlanResolver::class)->for($team);

    expect($entitlements->plan->key)->toBe(PlanKey::Free)
        ->and($entitlements->state->isEntitled())->toBeFalse();

    // Still allowed to work, just not much — the free allowance is a working allowance, not a wall.
    app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team);
});

it('keeps entitling a team whose card has failed while Stripe retries', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale, status: 'past_due');

    recordLeads($team, (int) config('billing.plans.free.leads') + 1);

    // On the free allowance this would already have stopped. The point is that a failed payment
    // does not instantly demote a customer Stripe is still chasing.
    app(DiscoveryLimitGuard::class)->ensureWithinAllowance($team);

    expect(app(PlanResolver::class)->for($team)->plan->key)->toBe(PlanKey::Scale);
});

it('falls back to the free allowance once a subscription has actually ended', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale, status: 'canceled', endsAt: now()->subDay());

    expect(app(PlanResolver::class)->for($team)->plan->key)->toBe(PlanKey::Free);
});

it('keeps a cancelled team on its plan until the period it paid for ends', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Scale, endsAt: now()->addDays(9));

    $entitlements = app(PlanResolver::class)->for($team);

    expect($entitlements->plan->key)->toBe(PlanKey::Scale)
        ->and($entitlements->state->value)->toBe('grace_period')
        ->and($entitlements->state->isEntitled())->toBeTrue();
});

// --- Tenancy --------------------------------------------------------------------------------------

it('meters each team separately', function (): void {
    [, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    Fixtures::onFreePlan($a);
    Fixtures::onFreePlan($b);

    recordLeads($a, (int) config('billing.plans.free.leads'));

    // One team exhausting its allowance must never spend another's — the single most expensive
    // bug this module could have.
    expect(fn () => app(DiscoveryLimitGuard::class)->ensureWithinAllowance($a))
        ->toThrow(LeadSourcingException::class);

    app(DiscoveryLimitGuard::class)->ensureWithinAllowance($b);

    expect(app(UsageLedger::class)->usage($b, BillingMetric::Leads)->used)->toBe(0);
});

it('keeps a team’s meter rows out of another team’s reach', function (): void {
    [, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    app(TeamContext::class)->run($a, fn () => app(UsageLedger::class)->sync($a));
    app(TeamContext::class)->run($b, fn () => app(UsageLedger::class)->sync($b));

    // `usage_meters` is tenant-owned, so `BelongsToTeam`'s global scope is the first line and the
    // rule in ADR 0006 says every such model needs a test proving a cross-tenant read fails. Four
    // rows exist; a query run with B active may only see two of them.
    expect(UsageMeter::query()->withoutGlobalScopes()->count())->toBe(4);

    $visible = app(TeamContext::class)->run($b, fn (): array => UsageMeter::query()
        ->pluck('team_id')
        ->unique()
        ->values()
        ->all());

    expect($visible)->toBe([(int) $b->getKey()]);

    // And a row written while B is active is stamped with B, never left for the scope to guess.
    $meter = app(TeamContext::class)->run(
        $b,
        fn (): UsageMeter => app(UsageLedger::class)->meter($b, BillingMetric::Leads),
    );

    expect($meter->team_id)->toBe((int) $b->getKey());
});

it('does not count another team’s AI spend', function (): void {
    [, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    recordCredits($a, 5_000);

    expect(app(UsageLedger::class)->usage($b, BillingMetric::AiCredits)->used)->toBe(0)
        ->and(app(UsageLedger::class)->usage($a, BillingMetric::AiCredits)->used)->toBe(5_000);
});
