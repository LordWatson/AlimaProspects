<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('redirects a guest away from the board', function (): void {
    $this->get('/leads/pipeline')->assertRedirect(route('login'));
});

it('gives a team the default board the first time it opens the pipeline', function (): void {
    [$user, $team] = Fixtures::team();

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('leads.pipeline'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leads/Pipeline')
            ->has('stages', 5)
            ->where('stages.0.name', 'New')
            ->where('stages.0.is_default', true)
            ->where('stages.3.outcome', 'won')
            ->where('stages.4.outcome', 'lost')
            ->where('can.manageStages', true));

    expect(PipelineStage::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(5);
});

it('does not create a second board on a second visit', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();
    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(5);
});

it('shows a lead with no stage in the default column', function (): void {
    [$user, $team] = Fixtures::team();

    // Leads created by discovery know nothing about the board, so they have no stage at all.
    $lead = Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);

    $response = $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $stages = $response->viewData('page')['props']['stages'];
    $defaultId = collect($stages)->firstWhere('is_default', true)['id'];

    $response->assertInertia(fn ($page) => $page
        ->where('counts.'.$defaultId, 1)
        ->where('cards.'.$defaultId.'.0.company_name', 'Unplaced Ltd'));

    expect($lead->pipeline_stage_id)->toBeNull();
});

it('moves a lead between columns, writing the timeline and the audit trail', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $stages = app(TeamContext::class)->run($team, fn () => PipelineStage::factory()->count(2)->sequence(
        ['name' => 'New', 'slug' => 'new', 'position' => 0, 'is_default' => true],
        ['name' => 'Working', 'slug' => 'working', 'position' => 1],
    )->create(['team_id' => $team->getKey()]));

    $target = $stages->last();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $target?->getKey(),
        ])
        ->assertRedirect(route('leads.pipeline'));

    expect((int) $lead->refresh()->pipeline_stage_id)->toBe((int) $target?->getKey());

    $activity = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::StageChanged)
        ->firstOrFail();

    expect($activity->properties)->toBe(['from' => 'New', 'to' => 'Working', 'outcome' => null])
        // A member did this, so the timeline credits them rather than Alima.
        ->and((int) $activity->user_id)->toBe((int) $user->getKey())
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.stage_moved')->count())->toBe(1);
});

it('records nothing when a lead is "moved" into the column it is already implicitly in', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    // Never placed, so `pipeline_stage_id` is null — but it is in the default column all the same.
    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->put(route('leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $default->getKey(),
        ])
        ->assertRedirect();

    expect(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::StageChanged)->count())
        ->toBe(0);
});

it('records nothing when a drag lands where it started', function (): void {
    [$user, $team] = Fixtures::team();

    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()->default()->create([
        'team_id' => $team->getKey(),
    ]));
    $lead = Fixtures::lead($team, ['pipeline_stage_id' => $stage->getKey()]);

    $this->actingAs($user)
        ->put(route('leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $stage->getKey(),
        ])
        ->assertRedirect();

    expect(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::StageChanged)->count())
        ->toBe(0);
});

it('refuses a stage move from a read-only member', function (): void {
    [, $team] = Fixtures::team();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);
    $lead = Fixtures::lead($team);
    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()->default()->create([
        'team_id' => $team->getKey(),
    ]));

    $this->actingAs($readOnly)
        ->put(route('leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $stage->getKey(),
        ])
        ->assertForbidden();

    expect($lead->refresh()->pipeline_stage_id)->toBeNull();
});

it('refuses a move into another team’s column', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    [$foreignTeam] = Fixtures::foreignLead();

    $foreignStage = app(TeamContext::class)->run($foreignTeam, fn (): PipelineStage => PipelineStage::factory()
        ->create(['team_id' => $foreignTeam->getKey()]));

    $this->actingAs($user)
        ->putJson(route('leads.stage.move', ['lead' => $lead->getKey()]), [
            'pipeline_stage_id' => $foreignStage->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pipeline_stage_id');
});

it('adds a stage to the board, on the end', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->post(route('leads.stages.store'), ['name' => 'Proposal sent'])
        ->assertRedirect();

    $stage = PipelineStage::query()->withoutGlobalScopes()->orderByDesc('position')->firstOrFail();

    expect($stage->name)->toBe('Proposal sent')
        ->and($stage->slug)->toBe('proposal-sent')
        ->and($stage->position)->toBe(5)
        ->and($stage->is_default)->toBeFalse();
});

it('keeps a stage’s slug when the team renames it', function (): void {
    [$user, $team] = Fixtures::team();

    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()
        ->named('Working', 1)
        ->create(['team_id' => $team->getKey()]));

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $stage->getKey()]), ['name' => 'In progress'])
        ->assertRedirect();

    // The slug is the stable identity a saved view or a report refers to, so a rename must not
    // move it.
    expect($stage->refresh()->name)->toBe('In progress')
        ->and($stage->slug)->toBe('working');
});

it('keeps exactly one default stage', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $other = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $other->getKey()]), [
            'name' => 'Working',
            'is_default' => true,
        ])
        ->assertRedirect();

    $defaults = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()?->slug)->toBe('working');
});

it('refuses to make an ending stage the starting one', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $won = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'won')->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $won->getKey()]), [
            'name' => 'Won',
            'outcome' => 'won',
            'is_default' => true,
        ])
        ->assertRedirect();

    // A stage can't be both where leads start and where they end; the outcome is the stronger claim.
    expect($won->refresh()->is_default)->toBeFalse()
        ->and($won->outcome)->toBe(StageOutcome::Won);
});

it('reorders the board', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $ids = PipelineStage::query()->withoutGlobalScopes()->orderBy('position')->pluck('id')->all();
    $reversed = array_reverse($ids);

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.reorder'), ['stages' => $reversed])
        ->assertRedirect();

    expect(PipelineStage::query()->withoutGlobalScopes()->orderBy('position')->pluck('id')->all())
        ->toBe($reversed);
});

it('keeps a stage the caller left out, on the end, rather than losing it', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $ids = PipelineStage::query()->withoutGlobalScopes()->orderBy('position')->pluck('id')->all();

    // A teammate added a column since this board was loaded, so the request doesn't mention it.
    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.reorder'), ['stages' => array_slice($ids, 0, 3)])
        ->assertRedirect();

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(5)
        ->and(PipelineStage::query()->withoutGlobalScopes()->orderBy('position')->pluck('id')->take(3)->all())
        ->toBe(array_slice($ids, 0, 3));
});

it('moves a removed stage’s leads to the starting stage rather than deleting them', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $working = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();
    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    $lead = Fixtures::lead($team, ['pipeline_stage_id' => $working->getKey()]);

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->delete(route('leads.stages.destroy', ['stage' => $working->getKey()]))
        ->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((int) $lead->refresh()->pipeline_stage_id)->toBe((int) $default->getKey())
        ->and(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(4);
});

it('refuses to remove the starting stage', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)
        ->deleteJson(route('leads.stages.destroy', ['stage' => $default->getKey()]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leads.default_stage');

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(5);
});

it('refuses to remove the last stage', function (): void {
    [$user, $team] = Fixtures::team();

    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()
        ->named('Only', 0)
        ->create(['team_id' => $team->getKey()]));

    $this->actingAs($user)
        ->deleteJson(route('leads.stages.destroy', ['stage' => $stage->getKey()]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leads.last_stage');

    expect(PipelineStage::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses board changes from a plain member', function (): void {
    [, $team] = Fixtures::team();

    $member = Fixtures::member($team, TeamRole::Member);
    $stage = app(TeamContext::class)->run($team, fn (): PipelineStage => PipelineStage::factory()
        ->named('Working', 1)
        ->create(['team_id' => $team->getKey()]));

    $this->actingAs($member)->post(route('leads.stages.store'), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($member)
        ->put(route('leads.stages.update', ['stage' => $stage->getKey()]), ['name' => 'Nope'])
        ->assertForbidden();
    $this->actingAs($member)
        ->delete(route('leads.stages.destroy', ['stage' => $stage->getKey()]))
        ->assertForbidden();

    // …but the same member may still move a lead between columns.
    $this->actingAs($member)
        ->get(route('leads.pipeline'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.work', true)
            ->where('can.manageStages', false));
});

it('loads the board without a lazy load', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    foreach (range(1, 4) as $i) {
        Fixtures::lead(
            $team,
            ['company_name' => 'Acme '.$i, 'dedupe_key' => 'no:acme-'.$i],
            $icp,
            ScoreGrade::B,
            65,
        );
    }

    Model::preventLazyLoading();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();
});

it('always leaves the team with a starting stage', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    // Un-ticking the box on the current starting stage would otherwise leave the board with none,
    // and every lead nobody has placed yet would vanish from it.
    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $default->getKey()]), [
            'name' => $default->name,
            'is_default' => false,
        ])
        ->assertRedirect();

    $defaults = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1);
});

it('promotes another column when the starting one is given an outcome', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $default->getKey()]), [
            'name' => $default->name,
            'outcome' => 'won',
        ])
        ->assertRedirect();

    $defaults = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()?->getKey())->not->toBe($default->getKey())
        // The promoted column is a middle stage, not another ending one.
        ->and($defaults->first()?->outcome)->toBeNull();
});

it('still shows unplaced leads on the board after the starting stage changes', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);

    $working = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.pipeline'))
        ->put(route('leads.stages.update', ['stage' => $working->getKey()]), [
            'name' => 'Working',
            'is_default' => true,
        ])
        ->assertRedirect();

    // The lead's own `pipeline_stage_id` is still null, so it follows the default column.
    $this->actingAs($user)
        ->get(route('leads.pipeline'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('counts.'.$working->getKey(), 1)
            ->where('cards.'.$working->getKey().'.0.company_name', 'Unplaced Ltd'));
});
