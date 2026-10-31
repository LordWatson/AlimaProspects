<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Actions\CompleteOnboardingAction;
use App\Modules\Onboarding\Exceptions\OnboardingException;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Onboarding\Policies\BusinessProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Post every data step with valid values, in order, as the acting user.
 */
function completeAllSteps(object $test): void
{
    $steps = [
        'company' => ['company_name' => 'Acme', 'website' => 'https://acme.test'],
        'industry' => ['industry' => 'SaaS', 'company_size' => 'small'],
        'locations' => ['locations' => ['London'], 'regions_served' => ['uk'], 'primary_market' => 'uae'],
        'offering' => ['products_services' => ['Widgets'], 'value_proposition' => 'We do widgets.'],
        'pricing' => ['pricing_currency' => 'gbp', 'pricing_min' => 100, 'pricing_max' => 500, 'customer_examples' => ['BigCo']],
    ];

    foreach ($steps as $step => $payload) {
        $test->post(route('onboarding.step', ['step' => $step]), $payload)->assertRedirect();
    }
}

it('saves a step, stores the profile and advances the resumable pointer', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('onboarding.step', ['step' => 'company']), [
            'company_name' => 'Acme Ltd',
            'website' => 'https://acme.test',
        ])
        ->assertRedirect();

    $team = $user->currentTeam()->firstOrFail();
    $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();
    $progress = OnboardingProgress::query()->where('team_id', $team->getKey())->firstOrFail();

    expect($profile->company_name)->toBe('Acme Ltd')
        ->and($progress->completed_steps)->toContain('company')
        ->and($progress->current_step->value)->toBe('industry')
        ->and($progress->isComplete())->toBeFalse();
});

it('keeps earlier steps when a later step is saved', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)->post(route('onboarding.step', ['step' => 'company']), [
        'company_name' => 'Acme Ltd',
    ])->assertRedirect();

    $this->actingAs($user)->post(route('onboarding.step', ['step' => 'industry']), [
        'industry' => 'SaaS',
        'company_size' => 'small',
    ])->assertRedirect();

    $profile = BusinessProfile::query()->firstOrFail();

    expect($profile->company_name)->toBe('Acme Ltd')
        ->and($profile->industry)->toBe('SaaS')
        ->and($profile->company_size?->value)->toBe('small');
});

it('converts the pricing range to integer minor units', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)->post(route('onboarding.step', ['step' => 'pricing']), [
        'pricing_currency' => 'gbp',
        'pricing_min' => 100,
        'pricing_max' => 500,
    ])->assertRedirect();

    $profile = BusinessProfile::query()->firstOrFail();

    expect($profile->pricing_min)->toBe(10000)
        ->and($profile->pricing_max)->toBe(50000);
});

it('validates the current step', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('onboarding.step', ['step' => 'company']), ['company_name' => ''])
        ->assertSessionHasErrors('company_name');

    expect(BusinessProfile::query()->count())->toBe(0);
});

it('resumes the wizard on the saved step', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)->post(route('onboarding.step', ['step' => 'company']), [
        'company_name' => 'Acme Ltd',
    ])->assertRedirect();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Wizard')
            ->where('progress.currentStep', 'industry')
            ->where('profile.company_name', 'Acme Ltd'));
});

it('completes onboarding, applies the market and dispatches the background check', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $this->actingAs($user);

    completeAllSteps($this);

    $this->post(route('onboarding.complete'))->assertRedirect(route('dashboard'));

    $team = $user->currentTeam()->firstOrFail();
    $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();

    expect($profile->isComplete())->toBeTrue()
        ->and($team->fresh()->market)->toBe('uae');

    Queue::assertPushed(RunBackgroundCheckJob::class);
});

it('blocks completion while steps are missing', function (): void {
    Queue::fake();

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('onboarding.complete'))
        ->assertRedirect();

    expect(OnboardingProgress::query()->whereNotNull('completed_at')->count())->toBe(0);
    Queue::assertNotPushed(RunBackgroundCheckJob::class);
});

it('forbids a read-only member from saving a step', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('onboarding.step', ['step' => 'company']), ['company_name' => 'Hacked'])
        ->assertForbidden();

    expect(BusinessProfile::query()->count())->toBe(0);
});

it('scopes the business profile to the active team (cross-tenant access fails)', function (): void {
    $context = app(TeamContext::class);

    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $profileA = $context->run($teamA, fn () => BusinessProfile::query()->create(['company_name' => 'A Ltd']));

    // Under team B, team A's profile is invisible.
    $context->run($teamB, function () use ($profileA): void {
        expect(BusinessProfile::query()->find($profileA->id))->toBeNull()
            ->and(BusinessProfile::query()->count())->toBe(0);
    });

    // team_id was auto-assigned from the active team on create.
    expect($profileA->team_id)->toBe($teamA->id);
});

it('lets an admin save a step but forbids a read-only member', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $admin = User::factory()->create();
    app(TeamMembership::class)->add($admin, $team, TeamRole::Admin);
    $admin->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($admin->refresh())
        ->post(route('onboarding.step', ['step' => 'company']), ['company_name' => 'Admin Co'])
        ->assertRedirect();

    expect(BusinessProfile::query()->firstOrFail()->company_name)->toBe('Admin Co');
});

it('bumps the profile version when onboarding is completed again', function (): void {
    Queue::fake();

    $user = createUserWithTeam();
    $this->actingAs($user);
    completeAllSteps($this);

    $this->post(route('onboarding.complete'))->assertRedirect(route('dashboard'));
    expect(BusinessProfile::query()->firstOrFail()->version)->toBe(1);

    $this->post(route('onboarding.complete'))->assertRedirect(route('dashboard'));
    expect(BusinessProfile::query()->firstOrFail()->version)->toBe(2);
});

it('throws with the missing steps when completing too early', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    expect(fn () => app(CompleteOnboardingAction::class)->handle($team, $user))
        ->toThrow(OnboardingException::class);
});

it('denies the Business Profile policy across teams', function (): void {
    $userA = createUserWithTeam();
    $teamB = Team::factory()->create();
    $profileB = app(TeamContext::class)->run($teamB, fn () => BusinessProfile::query()->create(['company_name' => 'B Ltd']));

    $policy = app(BusinessProfilePolicy::class);

    expect($policy->view($userA, $profileB))->toBeFalse()
        ->and($policy->update($userA, $profileB))->toBeFalse();
});

it('requires authentication for the read-only API', function (): void {
    $this->getJson(route('api.v1.onboarding.show'))->assertUnauthorized();
});

it('returns a null profile over the API before onboarding starts', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->getJson(route('api.v1.onboarding.show'))
        ->assertOk()
        ->assertJsonPath('data.profile', null)
        ->assertJsonPath('data.progress', null);
});

it('exposes the profile and progress over the read-only API', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    completeAllSteps($this);
    $this->post(route('onboarding.complete'))->assertRedirect();

    $this->actingAs($user)
        ->getJson(route('api.v1.onboarding.show'))
        ->assertOk()
        ->assertJsonPath('data.profile.company_name', 'Acme')
        ->assertJsonPath('data.profile.is_complete', true)
        ->assertJsonPath('data.progress.is_complete', true);
});
