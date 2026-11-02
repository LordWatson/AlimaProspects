<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('hides another team’s activities behind the global scope', function (): void {
    [$teamA, $leadA] = Fixtures::foreignLead();
    [$teamB, $leadB] = Fixtures::foreignLead();

    app(TeamContext::class)->run($teamA, fn () => LeadActivity::factory()->create([
        'team_id' => $teamA->getKey(),
        'lead_id' => $leadA->getKey(),
    ]));
    app(TeamContext::class)->run($teamB, fn () => LeadActivity::factory()->create([
        'team_id' => $teamB->getKey(),
        'lead_id' => $leadB->getKey(),
    ]));

    $visible = app(TeamContext::class)->run($teamA, fn () => LeadActivity::query()->get());

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()?->team_id)->toBe((int) $teamA->getKey())
        ->and(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('hides another team’s saved views behind the global scope', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    foreach ([$teamA, $teamB] as $team) {
        app(TeamContext::class)->run($team, fn () => SavedView::factory()->create([
            'team_id' => $team->getKey(),
            'user_id' => User::factory()->create()->getKey(),
        ]));
    }

    $visible = app(TeamContext::class)->run($teamA, fn () => SavedView::query()->get());

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()?->team_id)->toBe((int) $teamA->getKey())
        ->and(SavedView::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('never lists another team’s leads', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);
    Fixtures::foreignLead();

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Mine Ltd'));

    $this->actingAs($user)
        ->getJson(route('api.v1.leads.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('404s when a member opens another team’s lead', function (): void {
    [$user] = Fixtures::team();
    [, $foreign] = Fixtures::foreignLead();

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $foreign->getKey()]))
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson(route('api.v1.leads.show', ['lead' => $foreign->getKey()]))
        ->assertNotFound();
});

it('404s rather than 403s when a member deletes another team’s saved view', function (): void {
    [$user] = Fixtures::team();

    $foreignTeam = Team::factory()->create();
    $foreignView = app(TeamContext::class)->run($foreignTeam, fn (): SavedView => SavedView::factory()->create([
        'team_id' => $foreignTeam->getKey(),
        'user_id' => User::factory()->create()->getKey(),
    ]));

    // The team-scoped read runs before the policy, so the view's existence is never leaked.
    $this->actingAs($user)
        ->delete(route('leads.views.destroy', ['view' => $foreignView->getKey()]))
        ->assertNotFound();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('never opens another team’s saved view through the leads list', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $foreignTeam = Team::factory()->create();
    $foreignView = app(TeamContext::class)->run($foreignTeam, fn (): SavedView => SavedView::factory()->shared()->create([
        'team_id' => $foreignTeam->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'filters' => ['grades' => ['D']],
    ]));

    $this->actingAs($user)
        ->get(route('leads.index', ['view' => $foreignView->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeViewId', null)
            ->has('leads.data', 1));
});

it('takes a lead’s timeline with it when the lead is deleted', function (): void {
    [$team, $lead] = Fixtures::foreignLead();

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadActivity::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);

        $lead->delete();
    });

    expect(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps an activity’s history when the member who did it is deleted', function (): void {
    [$team, $lead] = Fixtures::foreignLead();
    $actor = User::factory()->create();

    app(TeamContext::class)->run($team, fn () => LeadActivity::factory()->by((int) $actor->getKey())->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    $actor->delete();

    $activity = LeadActivity::query()->withoutGlobalScopes()->firstOrFail();

    expect($activity->user_id)->toBeNull()
        ->and(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('keeps at most one view per member and name', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team, $user): void {
        SavedView::factory()->create([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'name' => 'Same name',
        ]);

        expect(fn () => SavedView::factory()->create([
            'team_id' => $team->getKey(),
            'user_id' => $user->getKey(),
            'name' => 'Same name',
        ]))->toThrow(QueryException::class);
    });
});

it('auto-assigns the active team when an activity is written without one', function (): void {
    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        $activity = LeadActivity::query()->create([
            'lead_id' => $lead->getKey(),
            'type' => LeadActivityType::Discovered,
            'occurred_at' => now(),
        ]);

        expect((int) $activity->team_id)->toBe((int) $team->getKey());
    });
});

/**
 * Every tenant model needs a test proving cross-tenant access fails (CLAUDE.md §2). The Phase 10b
 * tables are covered one by one here rather than trusted to the endpoint-level 404s, because a
 * model that quietly lost `BelongsToTeam` would still 404 on the endpoints that read through it
 * while leaking everywhere else.
 */
dataset('workspaceModels', [
    'pipeline stages' => [
        PipelineStage::class,
        fn (Team $team): array => ['team_id' => $team->getKey()],
    ],
    'tags' => [
        LeadTag::class,
        fn (Team $team): array => ['team_id' => $team->getKey()],
    ],
    'notes' => [
        LeadNote::class,
        fn (Team $team): array => [
            'team_id' => $team->getKey(),
            'lead_id' => Lead::factory()->create(['team_id' => $team->getKey()])->getKey(),
        ],
    ],
    'tasks' => [
        LeadTask::class,
        fn (Team $team): array => [
            'team_id' => $team->getKey(),
            'lead_id' => Lead::factory()->create(['team_id' => $team->getKey()])->getKey(),
        ],
    ],
    'assignments' => [
        LeadAssignment::class,
        fn (Team $team): array => [
            'team_id' => $team->getKey(),
            'lead_id' => Lead::factory()->create(['team_id' => $team->getKey()])->getKey(),
        ],
    ],
]);

it('hides another team’s workspace rows behind the global scope', function (string $model, Closure $attributes): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    foreach ([$teamA, $teamB] as $team) {
        app(TeamContext::class)->run($team, fn () => $model::factory()->create($attributes($team)));
    }

    $visible = app(TeamContext::class)->run($teamA, fn () => $model::query()->get());

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()?->team_id)->toBe((int) $teamA->getKey())
        ->and($model::query()->withoutGlobalScopes()->count())->toBe(2);
})->with('workspaceModels');

it('auto-assigns the active team to a workspace row written without one', function (string $model, Closure $attributes): void {
    $team = Team::factory()->create();

    app(TeamContext::class)->run($team, function () use ($team, $model, $attributes): void {
        $values = $attributes($team);
        unset($values['team_id']);

        $row = $model::factory()->create([...$values, 'team_id' => null]);

        expect((int) $row->team_id)->toBe((int) $team->getKey());
    });
})->with('workspaceModels');

it('takes a lead’s notes, tasks and assignments with it when the lead is deleted', function (): void {
    [$team, $lead] = Fixtures::foreignLead();

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadNote::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]);
        LeadTask::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]);
        LeadAssignment::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]);
        LeadTag::factory()->create(['team_id' => $team->getKey()])->leads()->attach($lead);

        $lead->delete();
    });

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadTask::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(0)
        // The tag itself survives — the team may still be using it on other leads.
        ->and(LeadTag::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('lead_lead_tag')->count())->toBe(0);
});

it('leaves a lead where it is when its stage is deleted outright', function (): void {
    [$team, $lead] = Fixtures::foreignLead();

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $stage = PipelineStage::factory()->create(['team_id' => $team->getKey()]);
        $lead->forceFill(['pipeline_stage_id' => $stage->getKey()])->save();

        // The delete Action moves the leads first; this is the foreign key being the backstop.
        $stage->delete();
    });

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($lead->refresh()->pipeline_stage_id)->toBeNull();
});

it('refuses a bulk action pointed at another team’s stage', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $foreignTeam = Team::factory()->create();
    $foreignStage = app(TeamContext::class)->run($foreignTeam, fn (): PipelineStage => PipelineStage::factory()
        ->create(['team_id' => $foreignTeam->getKey()]));

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), [
            'action' => 'move_stage',
            'lead_ids' => [$lead->getKey()],
            'pipeline_stage_id' => $foreignStage->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pipeline_stage_id');

    expect($lead->refresh()->pipeline_stage_id)->toBeNull();
});
