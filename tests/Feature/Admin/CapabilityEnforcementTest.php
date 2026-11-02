<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Auth\Actions\IssueApiTokenAction;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Actions\EnrichLeadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * **Every capability is enforced where it says it is** (Phase 19b, ADR 0015).
 *
 * `Capability::enforcedAt()` is documentation, and `CapabilityGateTest` only proved the string was
 * not empty — which is no test at all. This is the one the enum's own docblock asks for: a
 * capability that is *declared* but not *enforced* is worse than one that does not exist, because
 * the pricing page promises a gate that is not there and nobody finds out until a customer uses a
 * feature they did not buy.
 *
 * So each case is switched off by the global kill switch — the layer that beats everything except
 * suspension — and the named enforcement point is asked to refuse.
 */

function killAll(Capability ...$capabilities): void
{
    app(PlatformSettings::class)->set(
        'capabilities.disabled',
        array_map(static fn (Capability $c): string => $c->value, $capabilities),
    );

    app()->forgetScopedInstances();
    app()->forgetInstance(PlatformSettings::class);
}

it('refuses every declared capability at the web route that names it', function (): void {
    [$owner, $team] = Billing::team();

    // The web half for each capability, which `enforcedAt()` names in every case.
    $routes = [
        Capability::Signals->value => '/signals',
        Capability::AiInsights->value => '/insights',
        Capability::BusinessIntelligence->value => '/business-intel',
        Capability::CsvExport->value => '/leads/export',
    ];

    foreach (Capability::cases() as $capability) {
        $path = $routes[$capability->value] ?? null;

        // Two capabilities are enforced somewhere other than a GET, and each has its own test
        // below: `enrichment` in a queued Action with no screen at all, and `api_access` on a POST
        // (the token *index* stays open on every plan on purpose, because a downgraded team still
        // needs to clean up the tokens it holds). Anything else missing from this map is a
        // capability whose route nobody has pointed at, which is the gap this test exists to find.
        if ($path === null) {
            expect($capability)->toBeIn([Capability::Enrichment, Capability::ApiAccess]);

            continue;
        }

        killAll();
        $this->actingAs($owner)->get($path)->assertSuccessful();

        killAll($capability);

        // A redirect with the plan's sentence, not a 500 from three layers down.
        $this->actingAs($owner)
            ->get($path)
            ->assertRedirect();
    }

    expect($team)->not->toBeNull();
});

it('refuses an api token to a team whose plan excludes the API', function (): void {
    [$owner, $team] = Billing::team();

    killAll(Capability::ApiAccess);

    // In the **Action**, not only on the settings route. The mobile two-factor exchange issues a
    // token from an endpoint with no session and no team-scoped middleware, so a route-only check
    // left one way to get a credential for a plan that does not include the API.
    expect(fn () => app(IssueApiTokenAction::class)->handle($owner, 'phone', [TokenAbility::LeadsRead->value]))
        ->toThrow(CapabilityException::class);

    expect($owner->refresh()->tokens()->count())->toBe(0)
        ->and($team)->not->toBeNull();
});

it('refuses every api request once the API is switched off', function (): void {
    [$owner, $team] = Billing::team();

    $token = app(IssueApiTokenAction::class)->handle($owner, 'cli', ['*']);

    $this->withToken($token)->getJson('/api/v1/leads')->assertSuccessful();

    killAll(Capability::ApiAccess);

    // **On every request, not only at issue.** A token outlives the plan that justified it, so
    // checking at issue alone would leave the kill switch — and tenant suspension — unable to stop
    // API traffic, which is the one thing a kill switch exists to do.
    $this->withToken($token)
        ->getJson('/api/v1/leads')
        ->assertStatus(402);

    expect($team)->not->toBeNull();
});

it('refuses the insights API, not just the insights screen', function (): void {
    [$owner, $team] = Billing::team();
    $token = app(IssueApiTokenAction::class)->handle($owner, 'cli', ['*']);

    killAll(Capability::AiInsights);

    // A token scope says what a *credential* may do; a capability says what the *team* bought. A
    // token issued before a downgrade carries the first without the second, so the API needs the
    // same gate as the screen.
    $this->withToken($token)->getJson('/api/v1/insights')->assertStatus(402);

    expect($team)->not->toBeNull();
});

it('refuses enrichment inside the queued Action, where it has no request', function (): void {
    [, $team] = Billing::team();
    $lead = Fixtures::lead($team, 'Acme Logistics');

    killAll(Capability::Enrichment);

    // ADR 0012's argument, which is why the check is in the Action: a queued enrichment and a
    // scheduled watch spend a team's allowance with no request to put middleware on.
    //
    // It **returns the lead untouched rather than throwing**, unlike the other guards, and that is
    // deliberate: a plan without enrichment is not an enrichment that went wrong. Leaving
    // `enrichment_status` alone is what makes the lead enrich normally the day the team upgrades,
    // where a Failed row would need somebody to notice and reset it. Asserted here so the
    // difference is a decision on the record rather than an inconsistency somebody later "fixes".
    $before = $lead->enrichment_status;
    $returned = app(EnrichLeadAction::class)->execute($lead);

    expect($returned->is($lead))->toBeTrue()
        ->and($returned->refresh()->enrichment_status)->toBe($before)
        ->and($returned->enriched_at)->toBeNull();
});

it('refuses the token POST the settings screen submits', function (): void {
    [$owner, $team] = Billing::team();

    killAll(Capability::ApiAccess);

    // The **index** stays open on every plan by decision: a downgraded team still needs to see and
    // revoke the tokens it already holds, and taking that away would be the wrong lever entirely.
    $this->actingAs($owner)->get('/settings/api-tokens')->assertSuccessful();

    $this->actingAs($owner)
        ->post('/settings/api-tokens', ['name' => 'cli', 'abilities' => ['*']])
        ->assertRedirect();

    expect($owner->refresh()->tokens()->count())->toBe(0)
        ->and($team)->not->toBeNull();
});

it('names an enforcement point, a refusal and a label for every case', function (): void {
    foreach (Capability::cases() as $capability) {
        expect($capability->enforcedAt())->not->toBe('')
            ->and($capability->refusal())->not->toBe('')
            ->and($capability->label())->not->toBe('');
    }
});

// --- The provisioning escalation ------------------------------------------------------------------

/**
 * The fields a Support operator must not be able to set through provisioning.
 */
function provisionPayload(array $overrides = []): array
{
    return [
        'team_name' => 'Acme Logistics',
        'market' => 'uk',
        'timezone' => 'Europe/London',
        'locale' => 'en',
        'owner_email' => 'dana@acme.test',
        'owner_name' => 'Dana Owner',
        'billing_mode' => 'stripe',
        ...$overrides,
    ];
}

it('refuses a Support operator a plan grant through provisioning', function (): void {
    // **The escalation this closes.** Provisioning is behind `teams.manage`, which Support holds,
    // and it calls GrantPlanAction — whose own route is behind `billing.manage`, which Support does
    // not. Without the check, a POST here hands a tenant an unlimited-allowance plan through a
    // route that does not mention billing at all.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/provisioning', provisionPayload([
            'billing_mode' => 'manual',
            'grant_plan' => PlanKey::Enterprise->value,
            'grant_note' => 'Trying it on without the ability for it.',
        ]))
        ->assertSessionHasErrors('billing_mode');

    // Refused outright rather than silently stripped: a team provisioned without the plan the form
    // showed is worse than an error naming the ability needed.
    expect(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Team::query()->where('name', 'Acme Logistics')->exists())->toBeFalse();
});

it('refuses a Support operator a capability override through provisioning', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/provisioning', provisionPayload([
            'capabilities_on' => [Capability::Signals->value],
        ]))
        ->assertSessionHasErrors('capabilities_on');

    expect(CapabilityOverride::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('lets Support provision an ordinary self-serve tenant', function (): void {
    // The ability it does hold, and the case the screen exists for. Closing the escalation must not
    // close the job.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/provisioning', provisionPayload())
        ->assertRedirect();

    expect(Team::query()->where('name', 'Acme Logistics')->exists())->toBeTrue();
});

it('lets a super admin set all of it', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->post('/admin/provisioning', provisionPayload([
            'billing_mode' => 'manual',
            'grant_plan' => PlanKey::Enterprise->value,
            'grant_note' => 'Signed order form, 12 months, ref CS-412.',
            'capabilities_on' => [Capability::Signals->value],
        ]))
        ->assertRedirect();

    expect(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(CapabilityOverride::query()->withoutGlobalScopes()->count())->toBe(1);
});
