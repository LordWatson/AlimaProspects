<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * A team with three leads, ready to be acted on in bulk.
 *
 * @return array{User, Team, list<int>}
 */
function bulkLeads(): array
{
    [$user, $team] = Fixtures::team();

    $ids = [];

    foreach (range(1, 3) as $i) {
        $ids[] = (int) Fixtures::lead($team, [
            'company_name' => 'Acme '.$i,
            'dedupe_key' => 'no:acme-'.$i,
        ])->getKey();
    }

    return [$user, $team, $ids];
}

it('tags a selection, writing the same timeline entries as doing it one at a time', function (): void {
    [$user, , $ids] = bulkLeads();

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => $ids,
            'tags' => ['warm'],
        ])
        ->assertRedirect();

    expect(LeadTag::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TagsChanged)->count())
        ->toBe(3)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.bulk_updated')->count())
        ->toBe(1);
});

it('keeps the tags a lead already had when adding more', function (): void {
    [$user, $team, $ids] = bulkLeads();

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => [$ids[0]],
            'tags' => ['uk'],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => $ids,
            'tags' => ['warm'],
        ])
        ->assertRedirect();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::query()->findOrFail($ids[0]));

    expect($lead->tags()->pluck('slug')->sort()->values()->all())->toBe(['uk', 'warm']);
});

it('removes a tag from a selection, matching on the normalised name', function (): void {
    [$user, $team, $ids] = bulkLeads();

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => $ids,
            'tags' => ['Warm', 'uk'],
        ])
        ->assertRedirect();

    // Case and spacing must not decide whether a tag comes off.
    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'remove_tags',
            'lead_ids' => $ids,
            'tags' => ['  WARM '],
        ])
        ->assertRedirect();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::query()->findOrFail($ids[0]));

    expect($lead->tags()->pluck('slug')->all())->toBe(['uk']);
});

it('assigns and unassigns a selection', function (): void {
    [$user, $team, $ids] = bulkLeads();

    $colleague = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'assign',
            'lead_ids' => $ids,
            'user_id' => $colleague->getKey(),
        ])
        ->assertRedirect();

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(3);

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'unassign',
            'lead_ids' => $ids,
            'user_id' => $colleague->getKey(),
        ])
        ->assertRedirect();

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('moves a selection to a stage', function (): void {
    [$user, $team, $ids] = bulkLeads();

    $this->actingAs($user)->get(route('leads.pipeline'))->assertOk();

    $working = PipelineStage::query()->withoutGlobalScopes()->where('slug', 'working')->firstOrFail();

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'move_stage',
            'lead_ids' => $ids,
            'pipeline_stage_id' => $working->getKey(),
        ])
        ->assertRedirect();

    expect(
        Lead::query()->withoutGlobalScopes()->where('pipeline_stage_id', $working->getKey())->count()
    )->toBe(3)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::StageChanged)->count())
        ->toBe(3);
});

it('never touches another team’s leads, and counts only what changed', function (): void {
    [$user, , $ids] = bulkLeads();
    [, $foreign] = Fixtures::foreignLead();

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => [...$ids, (int) $foreign->getKey()],
            'tags' => ['warm'],
        ])
        ->assertRedirect();

    // Three tagged, not four — the foreign id simply isn't in the team-scoped set.
    expect(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TagsChanged)->count())
        ->toBe(3)
        ->and($foreign->tags()->count())->toBe(0);
});

it('requires only the field the chosen action uses', function (): void {
    [$user, , $ids] = bulkLeads();

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), ['action' => 'add_tags', 'lead_ids' => $ids])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tags');

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), ['action' => 'assign', 'lead_ids' => $ids])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), ['action' => 'move_stage', 'lead_ids' => $ids])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pipeline_stage_id');

    // Tagging is not asked for a stage.
    $this->actingAs($user)
        ->postJson(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => $ids,
            'tags' => ['warm'],
        ])
        ->assertRedirect();
});

it('refuses an action it does not know, and a selection beyond the cap', function (): void {
    [$user, , $ids] = bulkLeads();

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), [
            'action' => 'suppress',
            'lead_ids' => $ids,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action');

    $max = (int) config('leads-workspace.max_bulk_leads');

    $this->actingAs($user)
        ->postJson(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => range(1, $max + 1),
            'tags' => ['warm'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead_ids');
});

it('refuses a bulk action from a read-only member', function (): void {
    [, $team, $ids] = bulkLeads();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)
        ->post(route('leads.bulk'), [
            'action' => 'add_tags',
            'lead_ids' => $ids,
            'tags' => ['nope'],
        ])
        ->assertForbidden();

    expect(LeadTag::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('does nothing, and says so, when the selection is already as asked', function (): void {
    [$user, , $ids] = bulkLeads();

    $this->actingAs($user)
        ->post(route('leads.bulk'), [
            'action' => 'unassign',
            'lead_ids' => $ids,
            'user_id' => $user->getKey(),
        ])
        ->assertRedirect();

    expect(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::Unassigned)->count())
        ->toBe(0);
});
