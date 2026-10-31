<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Actions\FindIcpAction;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\ICP\Policies\IcpPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * A valid two-signal + one-exclusion payload.
 *
 * @return array<string, mixed>
 */
function icpPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'UK growth agencies',
        'description' => 'Marketing agencies scaling up in the UK.',
        'is_active' => false,
        'criteria' => [
            ['type' => 'industry', 'value' => 'Marketing', 'weight' => 80],
            ['type' => 'geography', 'value' => 'London', 'weight' => 60],
            ['type' => 'exclusion', 'value' => 'competitor.com'],
        ],
    ], $overrides);
}

it('creates an ICP with criteria, makes the first one active and emits IcpUpdated', function (): void {
    Event::fake([IcpUpdated::class]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.store'), icpPayload())
        ->assertRedirect(route('icp.index'));

    $icp = Icp::query()->firstOrFail();

    expect($icp->name)->toBe('UK growth agencies')
        ->and($icp->is_active)->toBeTrue() // first ICP auto-activates
        ->and($icp->criteria()->count())->toBe(3);

    $exclusion = $icp->criteria()->where('type', CriterionType::Exclusion->value)->firstOrFail();
    $signal = $icp->criteria()->where('type', CriterionType::Industry->value)->firstOrFail();

    expect($exclusion->weight)->toBeNull()
        ->and($signal->weight)->toBe(80);

    Event::assertDispatched(IcpUpdated::class, fn (IcpUpdated $e): bool => $e->teamId === $icp->team_id && $e->activeIcpId === $icp->id);
});

it('keeps criteria positions in submitted order', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)->post(route('icp.store'), icpPayload())->assertRedirect();

    $icp = Icp::query()->with('criteria')->firstOrFail();

    expect($icp->criteria->pluck('value')->all())->toBe(['Marketing', 'London', 'competitor.com']);
});

it('activating a new ICP deactivates the previous active one', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);

    $this->post(route('icp.store'), icpPayload(['name' => 'First']))->assertRedirect();
    $this->post(route('icp.store'), icpPayload(['name' => 'Second', 'is_active' => true]))->assertRedirect();

    $first = Icp::query()->where('name', 'First')->firstOrFail();
    $second = Icp::query()->where('name', 'Second')->firstOrFail();

    expect($first->is_active)->toBeFalse()
        ->and($second->is_active)->toBeTrue()
        ->and(Icp::query()->where('is_active', true)->count())->toBe(1);
});

it('updates an ICP, replaces its criteria and re-emits IcpUpdated when active', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect();
    $icp = Icp::query()->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->put(route('icp.update', ['icp' => $icp->id]), icpPayload([
        'name' => 'Renamed',
        'is_active' => true,
        'criteria' => [['type' => 'company_size', 'value' => '11–50 employees', 'weight' => 40]],
    ]))->assertRedirect(route('icp.index'));

    $icp->refresh();

    expect($icp->name)->toBe('Renamed')
        ->and($icp->criteria()->count())->toBe(1)
        ->and($icp->criteria()->firstOrFail()->type)->toBe(CriterionType::CompanySize);

    Event::assertDispatched(IcpUpdated::class);
});

it('switches the active ICP via the activate endpoint and emits IcpUpdated', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload(['name' => 'First']))->assertRedirect();
    $this->post(route('icp.store'), icpPayload(['name' => 'Second']))->assertRedirect();

    $first = Icp::query()->where('name', 'First')->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->post(route('icp.activate', ['icp' => $first->id]))->assertRedirect();

    expect($first->refresh()->is_active)->toBeTrue();
    Event::assertDispatched(IcpUpdated::class, fn (IcpUpdated $e): bool => $e->activeIcpId === $first->id);
});

it('deleting the active ICP promotes the newest remaining one and emits IcpUpdated', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload(['name' => 'First']))->assertRedirect();      // active
    $this->post(route('icp.store'), icpPayload(['name' => 'Second']))->assertRedirect();     // inactive

    $first = Icp::query()->where('name', 'First')->firstOrFail();
    $second = Icp::query()->where('name', 'Second')->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->delete(route('icp.destroy', ['icp' => $first->id]))->assertRedirect(route('icp.index'));

    expect(Icp::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and($second->refresh()->is_active)->toBeTrue();

    Event::assertDispatched(IcpUpdated::class, fn (IcpUpdated $e): bool => $e->activeIcpId === $second->id);
});

it('deleting a non-active ICP changes nothing and emits no event', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload(['name' => 'First']))->assertRedirect();      // active
    $this->post(route('icp.store'), icpPayload(['name' => 'Second']))->assertRedirect();     // inactive

    $first = Icp::query()->where('name', 'First')->firstOrFail();
    $second = Icp::query()->where('name', 'Second')->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->delete(route('icp.destroy', ['icp' => $second->id]))->assertRedirect();

    expect($first->refresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(IcpUpdated::class);
});

it('validates the payload', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.store'), icpPayload([
            'name' => '',
            'criteria' => [['type' => 'industry', 'value' => '']],
        ]))
        ->assertSessionHasErrors(['name', 'criteria.0.value']);

    expect(Icp::query()->count())->toBe(0);
});

it('renders the ICP index with the team ICPs', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect();

    $this->get(route('icp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('icp/Index')
            ->has('icps', 1)
            ->where('icps.0.name', 'UK growth agencies')
            ->where('icps.0.is_active', true)
            ->has('icps.0.criteria', 3)
            ->where('can.manage', true));
});

it('forbids a read-only member from creating an ICP', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->post(route('icp.store'), icpPayload())
        ->assertForbidden();

    expect(Icp::query()->count())->toBe(0);
});

it('scopes ICPs to the active team (cross-tenant find 404s)', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $otherTeam = Team::factory()->create();
    $otherIcp = app(TeamContext::class)->run($otherTeam, fn (): Icp => Icp::query()->create(['name' => 'Theirs']));

    // The team-scoped finder cannot resolve another team's ICP.
    expect(fn () => app(FindIcpAction::class)->handle($team, (int) $otherIcp->id))
        ->toThrow(ModelNotFoundException::class);

    // And editing it over the web 404s rather than leaking.
    $this->actingAs($user)
        ->get(route('icp.edit', ['icp' => $otherIcp->id]))
        ->assertNotFound();
});

it('denies the ICP policy across teams', function (): void {
    $userA = createUserWithTeam();
    $teamB = Team::factory()->create();
    $icpB = app(TeamContext::class)->run($teamB, fn (): Icp => Icp::query()->create(['name' => 'B']));

    $policy = app(IcpPolicy::class);

    expect($policy->view($userA, $icpB))->toBeFalse()
        ->and($policy->update($userA, $icpB))->toBeFalse()
        ->and($policy->delete($userA, $icpB))->toBeFalse();
});

it('auto-assigns team_id to criteria created in a team context', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect();

    $criterion = IcpCriterion::query()->withoutGlobalScopes()->firstOrFail();

    expect($criterion->team_id)->toBe($team->getKey());
});

// --- API --------------------------------------------------------------------------------------

it('requires authentication for the ICP API', function (): void {
    $this->getJson(route('api.v1.icps.index'))->assertUnauthorized();
});

it('lists and creates ICPs over the API', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->postJson(route('api.v1.icps.store'), icpPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'UK growth agencies')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonCount(3, 'data.criteria');

    $this->actingAs($user)
        ->getJson(route('api.v1.icps.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.criteria.0.type', 'industry');
});

it('updates, activates and deletes an ICP over the API', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user)->postJson(route('api.v1.icps.store'), icpPayload(['name' => 'One']))->assertCreated();
    $this->actingAs($user)->postJson(route('api.v1.icps.store'), icpPayload(['name' => 'Two']))->assertCreated();

    $two = Icp::query()->where('name', 'Two')->firstOrFail();

    $this->actingAs($user)
        ->putJson(route('api.v1.icps.update', ['icp' => $two->id]), icpPayload(['name' => 'Two Renamed']))
        ->assertOk()
        ->assertJsonPath('data.name', 'Two Renamed');

    $this->actingAs($user)
        ->postJson(route('api.v1.icps.activate', ['icp' => $two->id]))
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $this->actingAs($user)
        ->deleteJson(route('api.v1.icps.destroy', ['icp' => $two->id]))
        ->assertNoContent();

    expect(Icp::query()->whereKey($two->id)->exists())->toBeFalse();
});

it('shows a single ICP over the API', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user)->postJson(route('api.v1.icps.store'), icpPayload())->assertCreated();
    $icp = Icp::query()->firstOrFail();

    $this->actingAs($user)
        ->getJson(route('api.v1.icps.show', ['icp' => $icp->id]))
        ->assertOk()
        ->assertJsonPath('data.id', $icp->id)
        ->assertJsonCount(3, 'data.criteria');
});

// --- Deactivation & delete-to-empty ------------------------------------------------------------

it('emits IcpUpdated(null) when the active ICP is deactivated via update', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect(); // active
    $icp = Icp::query()->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->put(route('icp.update', ['icp' => $icp->id]), icpPayload(['is_active' => false]))
        ->assertRedirect();

    expect($icp->refresh()->is_active)->toBeFalse()
        ->and(Icp::query()->where('is_active', true)->count())->toBe(0);

    Event::assertDispatched(IcpUpdated::class, fn (IcpUpdated $e): bool => $e->activeIcpId === null);
});

it('emits IcpUpdated(null) when the last (active) ICP is deleted', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect();
    $icp = Icp::query()->firstOrFail();

    Event::fake([IcpUpdated::class]);

    $this->delete(route('icp.destroy', ['icp' => $icp->id]))->assertRedirect();

    expect(Icp::query()->count())->toBe(0);
    Event::assertDispatched(IcpUpdated::class, fn (IcpUpdated $e): bool => $e->activeIcpId === null);
});

it('cascades criteria when an ICP is deleted', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);
    $this->post(route('icp.store'), icpPayload())->assertRedirect();
    $icp = Icp::query()->firstOrFail();

    expect(IcpCriterion::query()->withoutGlobalScopes()->count())->toBe(3);

    $this->delete(route('icp.destroy', ['icp' => $icp->id]))->assertRedirect();

    expect(IcpCriterion::query()->withoutGlobalScopes()->count())->toBe(0);
});

// --- Validation edges --------------------------------------------------------------------------

it('rejects a weight outside 1–100 and an unknown criterion type', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);

    $this->post(route('icp.store'), icpPayload([
        'criteria' => [['type' => 'industry', 'value' => 'SaaS', 'weight' => 500]],
    ]))->assertSessionHasErrors('criteria.0.weight');

    $this->post(route('icp.store'), icpPayload([
        'criteria' => [['type' => 'not_a_type', 'value' => 'SaaS', 'weight' => 50]],
    ]))->assertSessionHasErrors('criteria.0.type');

    expect(Icp::query()->count())->toBe(0);
});

it('rejects more than 50 criteria and a missing criteria key', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);

    $many = array_fill(0, 51, ['type' => 'industry', 'value' => 'SaaS', 'weight' => 50]);
    $this->post(route('icp.store'), icpPayload(['criteria' => $many]))
        ->assertSessionHasErrors('criteria');

    $payload = icpPayload();
    unset($payload['criteria']);
    $this->post(route('icp.store'), $payload)->assertSessionHasErrors('criteria');
});

it('nulls a weight supplied on an exclusion and defaults a blank signal weight to 50', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user);

    $this->post(route('icp.store'), icpPayload([
        'criteria' => [
            ['type' => 'industry', 'value' => 'SaaS'], // no weight → 50
            ['type' => 'exclusion', 'value' => 'acme.com', 'weight' => 90], // weight ignored → null
        ],
    ]))->assertRedirect();

    $icp = Icp::query()->with('criteria')->firstOrFail();
    $signal = $icp->criteria->firstWhere('type', CriterionType::Industry);
    $exclusion = $icp->criteria->firstWhere('type', CriterionType::Exclusion);

    expect($signal?->weight)->toBe(50)
        ->and($exclusion?->weight)->toBeNull();
});

it('returns a validation envelope over the API', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->postJson(route('api.v1.icps.store'), icpPayload(['name' => '']))
        ->assertStatus(422)
        ->assertApiValidationErrors('name');
});

// --- Auth & role enforcement -------------------------------------------------------------------

it('redirects guests away from the ICP web routes', function (): void {
    $this->get(route('icp.index'))->assertRedirect(route('login'));
    $this->get(route('icp.create'))->assertRedirect(route('login'));
});

it('forbids a read-only member from the write API routes', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    app(TeamContext::class)->run($team, fn () => Icp::query()->create(['name' => 'Existing', 'is_active' => true]));
    $icp = Icp::query()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();
    $member->refresh();

    $this->actingAs($member)->postJson(route('api.v1.icps.store'), icpPayload())->assertForbidden();
    $this->actingAs($member)->putJson(route('api.v1.icps.update', ['icp' => $icp->id]), icpPayload())->assertForbidden();
    $this->actingAs($member)->postJson(route('api.v1.icps.activate', ['icp' => $icp->id]))->assertForbidden();
    $this->actingAs($member)->deleteJson(route('api.v1.icps.destroy', ['icp' => $icp->id]))->assertForbidden();
});

// --- Cross-tenant isolation --------------------------------------------------------------------

it('never leaks or mutates another team’s ICP across web and API routes', function (): void {
    $user = createUserWithTeam();

    $otherTeam = Team::factory()->create();
    $otherIcp = app(TeamContext::class)->run($otherTeam, fn (): Icp => Icp::query()->create(['name' => 'Theirs', 'is_active' => true]));

    // API: read/update/activate/delete all 404 (scoped away), row unchanged.
    $this->actingAs($user)->getJson(route('api.v1.icps.show', ['icp' => $otherIcp->id]))->assertNotFound();
    $this->actingAs($user)->putJson(route('api.v1.icps.update', ['icp' => $otherIcp->id]), icpPayload(['name' => 'Hacked']))->assertNotFound();
    $this->actingAs($user)->postJson(route('api.v1.icps.activate', ['icp' => $otherIcp->id]))->assertNotFound();
    $this->actingAs($user)->deleteJson(route('api.v1.icps.destroy', ['icp' => $otherIcp->id]))->assertNotFound();

    // Web: edit/update/activate/destroy 404.
    $this->actingAs($user)->put(route('icp.update', ['icp' => $otherIcp->id]), icpPayload())->assertNotFound();
    $this->actingAs($user)->post(route('icp.activate', ['icp' => $otherIcp->id]))->assertNotFound();
    $this->actingAs($user)->delete(route('icp.destroy', ['icp' => $otherIcp->id]))->assertNotFound();

    expect($otherIcp->fresh()->name)->toBe('Theirs');
});

it('does not list another team’s ICPs in the web index or the API', function (): void {
    $user = createUserWithTeam();
    $this->actingAs($user)->post(route('icp.store'), icpPayload(['name' => 'Mine']))->assertRedirect();

    $otherTeam = Team::factory()->create();
    app(TeamContext::class)->run($otherTeam, fn () => Icp::query()->create(['name' => 'Theirs']));

    $this->actingAs($user)
        ->get(route('icp.index'))
        ->assertInertia(fn ($page) => $page->has('icps', 1)->where('icps.0.name', 'Mine'));

    $this->actingAs($user)
        ->getJson(route('api.v1.icps.index'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine');
});

it('scopes IcpCriterion direct queries to the active team', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $context = app(TeamContext::class);

    $context->run($teamA, function (): void {
        $icp = Icp::query()->create(['name' => 'A']);
        $icp->criteria()->create(['team_id' => $icp->team_id, 'type' => CriterionType::Industry, 'value' => 'SaaS', 'weight' => 50, 'position' => 0]);
    });

    // Under team B, team A's criterion is invisible to a direct query.
    $context->run($teamB, function (): void {
        expect(IcpCriterion::query()->count())->toBe(0);
    });
});
