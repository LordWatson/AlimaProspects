<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('saves the current filters as a named view', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.views.store'), [
            'name' => '  Grade A in the UK  ',
            'is_shared' => false,
            'filters' => ['grades' => ['A'], 'country' => 'GB', 'sort' => 'fit'],
        ])
        ->assertRedirect(route('leads.index'));

    $view = SavedView::query()->withoutGlobalScopes()->firstOrFail();

    expect($view->name)->toBe('Grade A in the UK')
        ->and($view->team_id)->toBe((int) $team->getKey())
        ->and($view->user_id)->toBe((int) $user->getKey())
        ->and($view->filters)->toBe(['grades' => ['A'], 'country' => 'GB', 'sort' => 'fit'])
        ->and($view->is_shared)->toBeFalse()
        // The trail records the shape of the view, never the team's search terms.
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.view_saved')->count())->toBe(1);
});

it('replaces the filters when a view is saved again under the same name', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.views.store'), [
        'name' => 'My view',
        'filters' => ['grades' => ['A']],
    ])->assertRedirect();

    $this->actingAs($user)->post(route('leads.views.store'), [
        'name' => 'My view',
        'filters' => ['grades' => ['B', 'C']],
    ])->assertRedirect();

    $views = SavedView::query()->withoutGlobalScopes()->get();

    expect($views)->toHaveCount(1)
        ->and($views->first()?->filters)->toBe(['grades' => ['B', 'C']]);
});

it('lets a member keep the same view name in two different teams', function (): void {
    [$user, $teamA] = Fixtures::team();

    $teamB = Team::factory()->create();
    app(TeamMembership::class)->add($user, $teamB, TeamRole::Admin);

    $this->actingAs($user)->post(route('leads.views.store'), [
        'name' => 'Grade A in the UK',
        'filters' => ['grades' => ['A']],
    ])->assertRedirect();

    $user->switchTeam($teamB);

    // The unique key is (team, user, name), and the upsert looks all three up — otherwise the
    // team-scoped lookup would miss team A's row and the insert would hit the constraint.
    $this->actingAs($user->refresh())->post(route('leads.views.store'), [
        'name' => 'Grade A in the UK',
        'filters' => ['grades' => ['B']],
    ])->assertRedirect();

    $views = SavedView::query()->withoutGlobalScopes()->orderBy('team_id')->get();

    expect($views)->toHaveCount(2)
        ->and($views->pluck('team_id')->all())->toBe([(int) $teamA->getKey(), (int) $teamB->getKey()])
        ->and($views->first()?->filters)->toBe(['grades' => ['A']])
        ->and($views->last()?->filters)->toBe(['grades' => ['B']]);
});

it('opens a saved view without a lazy load when the member has several', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team);

    $views = app(TeamContext::class)->run($team, fn () => SavedView::factory()->count(3)->sequence(
        ['name' => 'First'],
        ['name' => 'Second'],
        ['name' => 'Third'],
    )->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
    ]));

    // With more than one view in the collection, authorising the opened one must not reach for its
    // team lazily — that is the case the single-view tests above can't catch.
    Model::preventLazyLoading();

    $this->actingAs($user)
        ->get(route('leads.index', ['view' => $views->first()?->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('savedViews', 3)
            ->where('activeViewId', $views->first()?->getKey()));
});

it('drops a filter it no longer recognises instead of storing it', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.views.store'), [
        'name' => 'Mixed',
        // `grades` is real; the extra key is not part of the filter shape.
        'filters' => ['grades' => ['A'], 'colour' => 'purple'],
    ])->assertRedirect();

    expect(SavedView::query()->withoutGlobalScopes()->firstOrFail()->filters)
        ->toBe(['grades' => ['A']]);
});

it('opens a saved view, using its filters rather than the URL’s', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Strong fit'], $icp, ScoreGrade::A, 92);
    Fixtures::lead($team, ['company_name' => 'Weak fit', 'dedupe_key' => 'no:weak'], $icp, ScoreGrade::D, 9);

    $view = app(TeamContext::class)->run($team, fn (): SavedView => SavedView::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'name' => 'Only the best',
        'filters' => ['grades' => ['A']],
    ]));

    $this->actingAs($user)
        // A stale query string must not override the view the member asked for.
        ->get(route('leads.index', ['view' => $view->getKey(), 'grades' => ['D']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Strong fit')
            ->where('activeViewId', $view->getKey())
            ->where('filters.grades', ['A']));
});

it('opens the plain list when the view id is stale', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team);

    $this->actingAs($user)
        ->get(route('leads.index', ['view' => 999_999]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('activeViewId', null));
});

it('offers a teammate’s shared view but not their private one', function (): void {
    [$owner, $team] = Fixtures::team();
    $colleague = Fixtures::member($team, TeamRole::Member);

    app(TeamContext::class)->run($team, function () use ($team, $owner): void {
        SavedView::factory()->shared()->create([
            'team_id' => $team->getKey(),
            'user_id' => $owner->getKey(),
            'name' => 'Team favourites',
        ]);
        SavedView::factory()->create([
            'team_id' => $team->getKey(),
            'user_id' => $owner->getKey(),
            'name' => 'Just mine',
        ]);
    });

    $this->actingAs($colleague)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('savedViews', 1)
            ->where('savedViews.0.name', 'Team favourites')
            ->where('savedViews.0.is_mine', false)
            ->where('savedViews.0.owner', $owner->name));
});

it('removes a member’s own view', function (): void {
    [$user, $team] = Fixtures::team();

    $view = app(TeamContext::class)->run($team, fn (): SavedView => SavedView::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
    ]));

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->delete(route('leads.views.destroy', ['view' => $view->getKey()]))
        ->assertRedirect(route('leads.index'));

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.view_deleted')->count())->toBe(1);
});

it('refuses to let a teammate delete someone else’s shared view', function (): void {
    [$owner, $team] = Fixtures::team();
    $colleague = Fixtures::member($team, TeamRole::Member);

    $view = app(TeamContext::class)->run($team, fn (): SavedView => SavedView::factory()->shared()->create([
        'team_id' => $team->getKey(),
        'user_id' => $owner->getKey(),
    ]));

    $this->actingAs($colleague)
        ->delete(route('leads.views.destroy', ['view' => $view->getKey()]))
        ->assertForbidden();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets an admin tidy up a view left behind by someone else', function (): void {
    [$owner, $team] = Fixtures::team();
    $admin = Fixtures::member($team, TeamRole::Admin);

    $view = app(TeamContext::class)->run($team, fn (): SavedView => SavedView::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $owner->getKey(),
    ]));

    $this->actingAs($admin)
        ->from(route('leads.index'))
        ->delete(route('leads.views.destroy', ['view' => $view->getKey()]))
        ->assertRedirect();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a view from a read-only member', function (): void {
    [, $team] = Fixtures::team();
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)
        ->post(route('leads.views.store'), ['name' => 'Nope', 'filters' => []])
        ->assertForbidden();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($readOnly)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.saveView', false));
});

it('validates the view', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->postJson(route('leads.views.store'), ['filters' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)
        ->postJson(route('leads.views.store'), [
            'name' => str_repeat('x', 81),
            'filters' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)
        ->postJson(route('leads.views.store'), [
            'name' => 'Bad filter',
            'filters' => ['grades' => ['Z']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('filters.grades.0');
});

it('removes a member’s views when the account goes', function (): void {
    [$user, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => SavedView::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
    ]));

    $user->delete();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0);
});
