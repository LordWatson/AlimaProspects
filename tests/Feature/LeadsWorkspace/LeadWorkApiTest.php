<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The mobile app's half of the workspace (docs/modules/leads-workspace.md): stage move, note, task,
 * tags and assignment. These go through the **same Actions** as the web controllers, so the tests
 * here check the API contract rather than re-proving the behaviour.
 */
it('refuses an unauthenticated caller', function (): void {
    $this->getJson(route('api.v1.leads.stages'))->assertUnauthorized();
    $this->putJson(route('api.v1.leads.stage.move', ['lead' => 1]))->assertUnauthorized();
    $this->postJson(route('api.v1.leads.notes.store', ['lead' => 1]))->assertUnauthorized();
});

it('returns the team’s board, creating it if this is the first time', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->getJson(route('api.v1.leads.stages'))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'position', 'is_default', 'outcome']]])
        ->assertJsonPath('data.0.name', 'New')
        ->assertJsonPath('data.0.is_default', true);

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(5);
});

it('moves a lead and returns it with its new stage', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $stages = $this->actingAs($user)
        ->getJson(route('api.v1.leads.stages'))
        ->assertOk()
        ->json('data');

    $working = collect($stages)->firstWhere('slug', 'working');

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $working['id'],
        ])
        ->assertOk()
        ->assertJsonPath('data.stage.name', 'Working');

    expect((int) $lead->refresh()->pipeline_stage_id)->toBe((int) $working['id'])
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::StageChanged)->count())
        ->toBe(1);
});

it('writes a note and returns it, 201', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->postJson(route('api.v1.leads.notes.store', ['lead' => $lead->getKey()]), [
            'body' => 'Rang them from the car.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Rang them from the car.')
        ->assertJsonPath('data.author', $user->name)
        ->assertJsonPath('data.can_delete', true);

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('adds a task and returns it, 201', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->postJson(route('api.v1.leads.tasks.store', ['lead' => $lead->getKey()]), [
            'title' => 'Call them back',
            'assigned_to' => $user->getKey(),
            'due_at' => '2026-11-05 09:00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Call them back')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.assignee.name', $user->name)
        ->assertJsonPath('data.is_overdue', false);
});

it('ticks a task off and returns its new state', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.tasks.complete', ['task' => $task->getKey()]), ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'done');

    expect($task->refresh()->status)->toBe(LeadTaskStatus::Done);
});

it('sets a lead’s tags and returns the whole set', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.tags.sync', ['lead' => $lead->getKey()]), [
            'tags' => ['warm', 'uk'],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'uk');
});

it('sets who is working a lead and returns the lead', function (): void {
    [$user, $team] = Fixtures::team();

    $colleague = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.assignees.set', ['lead' => $lead->getKey()]), [
            'user_ids' => [$colleague->getKey()],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.assignees')
        ->assertJsonPath('data.assignees.0.name', $colleague->name);

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a write from a read-only member', function (): void {
    [, $team] = Fixtures::team();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);
    $lead = Fixtures::lead($team);

    $this->actingAs($readOnly)
        ->postJson(route('api.v1.leads.notes.store', ['lead' => $lead->getKey()]), ['body' => 'nope'])
        ->assertForbidden();

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('404s rather than touching another team’s lead or task', function (): void {
    [$user] = Fixtures::team();
    [$foreignTeam, $foreignLead] = Fixtures::foreignLead();

    $foreignTask = app(TeamContext::class)->run($foreignTeam, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $foreignTeam->getKey(),
        'lead_id' => $foreignLead->getKey(),
    ]));

    $this->actingAs($user)
        ->postJson(route('api.v1.leads.notes.store', ['lead' => $foreignLead->getKey()]), ['body' => 'sneaky'])
        ->assertNotFound();

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.tasks.complete', ['task' => $foreignTask->getKey()]), ['done' => true])
        ->assertNotFound();

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($foreignTask->refresh()->status)->toBe(LeadTaskStatus::Open);
});

it('carries the workspace fields on a lead from the list and detail endpoints', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->putJson(route('api.v1.leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['warm']])
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('api.v1.leads.index'))
        ->assertOk()
        ->assertJsonPath('data.0.tags.0.name', 'warm')
        ->assertJsonStructure(['data' => [['stage', 'tags', 'assignees', 'open_tasks_count']]]);
});
