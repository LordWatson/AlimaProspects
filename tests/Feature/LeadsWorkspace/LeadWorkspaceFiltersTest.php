<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The Phase 10b list filters — stage, tag, assignee, unassigned — across every path that reads a
 * filter set: the web list, `/api/v1`, a saved view and the CSV export. They are tested together
 * because the thing most likely to go wrong is one of them disagreeing with the others, which is
 * exactly what happened to the "a lead with no stage is in the default column" rule.
 */

/**
 * Read a streamed export response into a string.
 */
function filterExportBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

it('filters by stage, and counts an unplaced lead as being in the default one', function (): void {
    [$user, $team] = Fixtures::team();

    // Opening the board gives the team its default stages.
    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();
    $working = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();

    // Never placed — `pipeline_stage_id` is null, which the board reads as the default column.
    Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);
    Fixtures::lead($team, [
        'company_name' => 'Working Ltd',
        'dedupe_key' => 'no:working',
        'pipeline_stage_id' => $working->getKey(),
    ]);

    $this->actingAs($user)
        ->get(route('leads.index', ['stages' => [$default->getKey()]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Unplaced Ltd'));

    $this->actingAs($user)
        ->get(route('leads.index', ['stages' => [$working->getKey()]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Working Ltd'));
});

it('folds unplaced leads into the default stage on the API too', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);

    // The fold is resolved inside the query, not by the controller, so every caller gets it —
    // the API used to miss it and silently drop every lead nobody had placed yet.
    $this->actingAs($user, 'sanctum')
        ->getJson(route('api.v1.leads.index', ['stages' => [$default->getKey()]]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company_name', 'Unplaced Ltd');
});

it('folds unplaced leads into the default stage in the export too', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);

    $body = filterExportBody(
        $this->actingAs($user)
            ->get(route('leads.export', ['stages' => [$default->getKey()]]))
            ->assertOk()
    );

    expect($body)->toContain('Unplaced Ltd')
        // …and the stage column names the default column rather than leaving a blank cell, so the
        // file agrees with the board about where the team's newest leads are.
        ->and($body)->toContain($default->name);
});

it('folds unplaced leads into the default stage when a saved view asks for it', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    Fixtures::lead($team, ['company_name' => 'Unplaced Ltd']);

    $view = app(TeamContext::class)->run($team, fn (): SavedView => SavedView::factory()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'name' => 'New leads',
        'filters' => ['stages' => [$default->getKey()]],
    ]));

    $this->actingAs($user)
        ->get(route('leads.index', ['view' => $view->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leads.data', 1));
});

it('filters by tag, and requires every tag asked for', function (): void {
    [$user, $team] = Fixtures::team();

    $both = Fixtures::lead($team, ['company_name' => 'Both tags']);
    $one = Fixtures::lead($team, ['company_name' => 'One tag', 'dedupe_key' => 'no:one']);
    Fixtures::lead($team, ['company_name' => 'No tags', 'dedupe_key' => 'no:none']);

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $both->getKey()]), ['tags' => ['warm', 'uk']])
        ->assertRedirect();
    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $one->getKey()]), ['tags' => ['warm']])
        ->assertRedirect();

    $warm = LeadTag::query()->withoutGlobalScopes()->where('slug', 'warm')->firstOrFail();
    $uk = LeadTag::query()->withoutGlobalScopes()->where('slug', 'uk')->firstOrFail();

    $this->actingAs($user)
        ->get(route('leads.index', ['tags' => [$warm->getKey()]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leads.data', 2));

    // Two tags means both, not either.
    $this->actingAs($user)
        ->get(route('leads.index', ['tags' => [$warm->getKey(), $uk->getKey()]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Both tags'));
});

it('filters by assignee, and by nobody at all', function (): void {
    [$owner, $team] = Fixtures::team();

    $colleague = Fixtures::member($team, TeamRole::Member);

    $mine = Fixtures::lead($team, ['company_name' => 'Mine']);
    $theirs = Fixtures::lead($team, ['company_name' => 'Theirs', 'dedupe_key' => 'no:theirs']);
    Fixtures::lead($team, ['company_name' => 'Nobody’s', 'dedupe_key' => 'no:nobody']);

    $this->actingAs($owner)
        ->put(route('leads.assignees.set', ['lead' => $mine->getKey()]), ['user_ids' => [$owner->getKey()]])
        ->assertRedirect();
    $this->actingAs($owner)
        ->put(route('leads.assignees.set', ['lead' => $theirs->getKey()]), ['user_ids' => [$colleague->getKey()]])
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('leads.index', ['assignees' => [$colleague->getKey()]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Theirs'));

    // "Nobody is working these" is a filter in its own right, not an empty assignee list.
    $this->actingAs($owner)
        ->get(route('leads.index', ['unassigned' => '1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Nobody’s'));
});

it('never matches another team’s stage, tag or assignee', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    [$foreignTeam, $foreignLead] = Fixtures::foreignLead();

    [$foreignStage, $foreignTag, $foreignAssignment] = app(TeamContext::class)->run(
        $foreignTeam,
        fn (): array => [
            PipelineStage::factory()->default()->create(['team_id' => $foreignTeam->getKey()]),
            LeadTag::factory()->named('warm')->create(['team_id' => $foreignTeam->getKey()]),
            LeadAssignment::factory()->create([
                'team_id' => $foreignTeam->getKey(),
                'lead_id' => $foreignLead->getKey(),
            ]),
        ],
    );

    // A foreign id is simply not the team's own, so each of these narrows to nothing rather than
    // reaching across the tenancy boundary.
    foreach ([
        ['stages' => [$foreignStage->getKey()]],
        ['tags' => [$foreignTag->getKey()]],
        ['assignees' => [$foreignAssignment->user_id]],
    ] as $filter) {
        $this->actingAs($user)
            ->get(route('leads.index', $filter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads.data', 0));
    }

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('offers the team’s own board, tags and people as filter options', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['warm']])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stages', 5)
            ->has('tags', 1)
            ->where('tags.0.name', 'warm')
            ->where('tags.0.leads_count', 1)
            ->has('members', 1)
            ->where('members.0.id', $user->getKey()));
});

it('keeps the new filters in the URL, on the page links and in a saved view', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $stage = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();

    foreach (range(1, 3) as $i) {
        Fixtures::lead($team, [
            'company_name' => 'Acme '.$i,
            'dedupe_key' => 'no:acme-'.$i,
            'pipeline_stage_id' => $stage->getKey(),
        ]);
    }

    $this->actingAs($user)
        ->get(route('leads.index', ['stages' => [$stage->getKey()], 'unassigned' => '1', 'per_page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.stages', [(int) $stage->getKey()])
            ->where('filters.unassigned', true)
            ->where('leads.links.next', fn (string $url): bool => str_contains($url, 'stages')
                && str_contains($url, 'unassigned')));

    $this->actingAs($user)
        ->post(route('leads.views.store'), [
            'name' => 'Working, unclaimed',
            'filters' => ['stages' => [$stage->getKey()], 'unassigned' => true],
        ])
        ->assertRedirect();

    expect(SavedView::query()->withoutGlobalScopes()->firstOrFail()->filters)
        ->toBe(['stages' => [(int) $stage->getKey()], 'unassigned' => '1']);
});
