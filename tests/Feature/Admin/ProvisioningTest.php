<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Admin\Actions\ProvisionTeamAction;
use App\Modules\Admin\Data\ProvisionTeamData;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Models\CapabilityOverride;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Models\Icp;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * Onboarding a business from the panel (Phase 19b).
 *
 * The claim is that a provisioned tenant is **indistinguishable from one that signed up** — which
 * is why the Action calls Auth's own Actions rather than writing teams and invitations itself, and
 * why these tests check the things those Actions are responsible for (the Owner membership, the
 * trial, the invitation, the audit entry) rather than just that rows exist.
 */

function provisionData(array $overrides = []): ProvisionTeamData
{
    return new ProvisionTeamData(
        teamName: $overrides['teamName'] ?? 'Acme Logistics',
        market: $overrides['market'] ?? Market::Uk,
        timezone: $overrides['timezone'] ?? 'Europe/London',
        locale: $overrides['locale'] ?? SupportedLocale::English,
        ownerEmail: $overrides['ownerEmail'] ?? 'dana@acme.test',
        ownerName: $overrides['ownerName'] ?? 'Dana Buyer',
        billingMode: $overrides['billingMode'] ?? BillingMode::Stripe,
        grantPlan: $overrides['grantPlan'] ?? null,
        grantSeats: $overrides['grantSeats'] ?? null,
        grantLeads: $overrides['grantLeads'] ?? null,
        grantCredits: null,
        grantNote: $overrides['grantNote'] ?? null,
        trialDays: $overrides['trialDays'] ?? null,
        companyWebsite: $overrides['companyWebsite'] ?? null,
        industry: $overrides['industry'] ?? null,
        valueProposition: $overrides['valueProposition'] ?? null,
        icpName: $overrides['icpName'] ?? null,
        capabilitiesOn: $overrides['capabilitiesOn'] ?? [],
    );
}

it('creates a tenant that looks exactly like one that signed up', function (): void {
    Notification::fake();

    $team = app(ProvisionTeamAction::class)->handle(provisionData(), Fixtures::operator());

    $owner = User::query()->where('email', 'dana@acme.test')->firstOrFail();

    expect($team->name)->toBe('Acme Logistics')
        ->and($team->market)->toBe(Market::Uk->value)
        // Auth's `CreateTeamAction` added the Owner through `TeamMembership` — so the role, the
        // pivot and the `team.created` audit entry are all the ones a signup produces.
        ->and($owner->roleOn($team))->toBe(TeamRole::Owner)
        // And Billing's own model event gave it the trial, rather than us writing one.
        ->and($team->trial_ends_at)->not->toBeNull();

    expect(AuditLog::query()->withoutGlobalScopes()->where('action', 'team.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'admin.team.provisioned')->exists())->toBeTrue();
});

it('sends the owner a link to set their own password, and never chooses one', function (): void {
    Notification::fake();

    app(ProvisionTeamAction::class)->handle(provisionData(), Fixtures::operator());

    $owner = User::query()->where('email', 'dana@acme.test')->firstOrFail();

    // **A reset link, not a team invitation** — and the distinction is not cosmetic.
    // `CreateTeamAction` has already made this person the team's Owner, so there is nobody left to
    // invite: Auth's invitation Action refuses an existing member, which is correct of it. What the
    // account actually lacks is a credential, so provisioning reuses 19a's reset Action.
    Notification::assertSentTo($owner, ResetPassword::class);

    // The row's password is a random string nobody — including us — can sign in with, and nothing
    // anywhere accepts one for a provisioned account.
    expect(Hash::check('password', (string) $owner->password))->toBeFalse()
        // Not verified either: the link they follow is what proves they hold the address, and
        // marking it verified here would be us vouching for an address sales typed.
        ->and($owner->hasVerifiedEmail())->toBeFalse();
});

it('audits the first credential like every later one', function (): void {
    Notification::fake();

    app(ProvisionTeamAction::class)->handle(provisionData(), Fixtures::operator());

    // The reason for reusing the Action rather than calling the broker: a provisioned account's
    // first way in leaves the same trail as an operator pressing "send reset link" on day ninety.
    expect(AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.user.password_reset_sent')
        ->exists())->toBeTrue();
});

it('sends the link only after the transaction commits', function (): void {
    Notification::fake();

    // Not a mock of `afterCommit` — the real proof is that a rolled-back provisioning leaves
    // nobody holding a link into a team that does not exist.
    try {
        DB::transaction(function (): void {
            app(ProvisionTeamAction::class)->handle(provisionData(), Fixtures::operator());

            throw new RuntimeException('something later went wrong');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Team::query()->where('name', 'Acme Logistics')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('puts an invoiced customer on their contracted plan, with no trial', function (): void {
    Notification::fake();

    $team = app(ProvisionTeamAction::class)->handle(provisionData([
        'billingMode' => BillingMode::Manual,
        'grantPlan' => PlanKey::Enterprise,
        'grantSeats' => 25,
        'grantLeads' => 40_000,
        'grantNote' => 'Signed order form, ref CS-412.',
    ]), Fixtures::operator());

    $plan = app(PlanResolver::class)->planFor($team);

    expect(BillingMode::of($team))->toBe(BillingMode::Manual)
        ->and($plan->key)->toBe(PlanKey::Enterprise)
        ->and($plan->seats)->toBe(25)
        ->and($plan->leads)->toBe(40_000)
        // An invoiced customer's contract has already started; a free fortnight on top is one
        // nobody agreed to give away.
        ->and($team->trial_ends_at)->toBeNull()
        ->and(PlanGrant::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('honours an explicit trial length, including none', function (): void {
    Notification::fake();

    $team = app(ProvisionTeamAction::class)->handle(
        provisionData(['trialDays' => 30, 'ownerEmail' => 'a@acme.test']),
        Fixtures::operator(),
    );

    expect($team->trial_ends_at?->isAfter(now()->addDays(29)))->toBeTrue();

    $none = app(ProvisionTeamAction::class)->handle(
        provisionData(['trialDays' => 0, 'teamName' => 'No Trial Ltd', 'ownerEmail' => 'b@acme.test']),
        Fixtures::operator(),
    );

    // Zero and null are different answers, which is why the field is nullable rather than an int.
    expect($none->trial_ends_at)->toBeNull();
});

it('lands the owner on a half-done onboarding rather than a blank form', function (): void {
    Notification::fake();

    $team = app(ProvisionTeamAction::class)->handle(provisionData([
        'companyWebsite' => 'https://acme.test',
        'industry' => 'Logistics',
        'valueProposition' => 'Same-day pallets across the Midlands.',
        'icpName' => 'Mid-market logistics',
    ]), Fixtures::operator());

    $profile = BusinessProfile::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->first();
    $icp = Icp::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->first();

    expect($profile)->not->toBeNull()
        ->and($profile->industry)->toBe('Logistics')
        // **Not** marked complete: the wizard is where the Owner confirms what somebody else wrote
        // down about their business, and a completed profile would skip it.
        ->and($profile->completed_at)->toBeNull()
        ->and($icp)->not->toBeNull()
        // And the ICP is inactive, because one with no criteria would score every company the same.
        ->and($icp->is_active)->toBeFalse();
});

it('applies capability overrides at provisioning', function (): void {
    Notification::fake();

    $team = app(ProvisionTeamAction::class)->handle(
        provisionData(['capabilitiesOn' => [Capability::Signals->value]]),
        Fixtures::operator(),
    );

    $override = CapabilityOverride::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->first();

    expect($override)->not->toBeNull()
        ->and($override->capability)->toBe(Capability::Signals)
        ->and($override->enabled)->toBeTrue()
        // With a reason, because every override carries one — even one nobody typed.
        ->and($override->reason)->not->toBe('');
});

// --- The dry run -----------------------------------------------------------------------------------

it('writes nothing when asked what it would do', function (): void {
    Notification::fake();

    $plan = app(ProvisionTeamAction::class)->preview(provisionData());

    expect($plan->teamName)->toBe('Acme Logistics')
        ->and($plan->ownerExists)->toBeFalse()
        ->and(Team::query()->where('name', 'Acme Logistics')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'dana@acme.test')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('says out loud when the owner already has an account', function (): void {
    $existing = createUserWithTeam(['email' => 'dana@acme.test', 'name' => 'Dana Existing']);

    $plan = app(ProvisionTeamAction::class)->preview(provisionData());

    // The failure this exists to prevent: emailing a stranger an invitation into somebody else's
    // business because an operator mistyped an address.
    expect($plan->ownerExists)->toBeTrue()
        ->and($plan->ownerName)->toBe('Dana Existing')
        ->and($plan->ownerTeamCount)->toBe(1)
        ->and($plan->summary())->toContain('add the existing account');

    expect($existing->refresh()->teams()->count())->toBe(1);
});

it('adds an existing customer to a second team without inviting them again', function (): void {
    Notification::fake();

    $existing = createUserWithTeam(['email' => 'dana@acme.test']);

    $team = app(ProvisionTeamAction::class)->handle(provisionData(), Fixtures::operator());

    expect($existing->refresh()->roleOn($team))->toBe(TeamRole::Owner)
        ->and($existing->teams()->count())->toBe(2);

    // They already know how to sign in — a "set your password" invitation would be nonsense.
    Notification::assertNothingSent();
});

it('describes the whole plan in one sentence', function (): void {
    $plan = app(ProvisionTeamAction::class)->preview(provisionData([
        'billingMode' => BillingMode::Manual,
        'grantPlan' => PlanKey::Scale,
    ]));

    // Assembled server-side so the dry run and the confirmation cannot describe the same plan
    // differently.
    expect($plan->summary())->toContain('Acme Logistics')
        ->and($plan->summary())->toContain('invoiced billing')
        ->and($plan->summary())->toContain('scale')
        ->and($plan->summary())->toContain('no trial');
});

it('warns when an invoiced tenant is being created with no grant', function (): void {
    $plan = app(ProvisionTeamAction::class)->preview(provisionData([
        'billingMode' => BillingMode::Manual,
    ]));

    expect($plan->summary())->toContain('no plan granted');
});

// --- Access -----------------------------------------------------------------------------------------

it('is reachable only by a role that can manage teams', function (): void {
    foreach ([AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))->get('/admin/provisioning')->assertForbidden();
    }

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->get('/admin/provisioning')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/provisioning/Create'));
});

it('provisions from the screen, dry run first', function (): void {
    Notification::fake();
    $operator = Fixtures::operator();

    $payload = [
        'team_name' => 'Acme Logistics',
        'market' => Market::Uk->value,
        'timezone' => 'Europe/London',
        'locale' => SupportedLocale::English->value,
        'owner_email' => 'dana@acme.test',
        'owner_name' => 'Dana Buyer',
        'billing_mode' => BillingMode::Stripe->value,
    ];

    $this->actingAs($operator)
        ->post('/admin/provisioning/preview', $payload)
        ->assertRedirect();

    expect(Team::query()->where('name', 'Acme Logistics')->exists())->toBeFalse();

    $this->actingAs($operator)->post('/admin/provisioning', $payload)->assertRedirect();

    expect(Team::query()->where('name', 'Acme Logistics')->exists())->toBeTrue();
});

it('refuses a provisioning with no owner address', function (): void {
    $this->actingAs(Fixtures::operator())
        ->post('/admin/provisioning', [
            'team_name' => 'Acme Logistics',
            'market' => Market::Uk->value,
            'timezone' => 'Europe/London',
            'locale' => SupportedLocale::English->value,
            'owner_name' => 'Dana Buyer',
            'billing_mode' => BillingMode::Stripe->value,
        ])
        ->assertSessionHasErrors('owner_email');

    expect(Team::query()->where('name', 'Acme Logistics')->exists())->toBeFalse();
});
