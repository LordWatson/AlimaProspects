<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

// --- Tags ----------------------------------------------------------------------------------

it('creates a tag on first use and attaches it', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), [
            'tags' => ['  Warm  ', 'Trade show'],
        ])
        ->assertRedirect();

    $tags = LeadTag::query()->withoutGlobalScopes()->orderBy('name')->get();

    expect($tags)->toHaveCount(2)
        ->and($tags->pluck('slug')->all())->toBe(['trade show', 'warm'])
        ->and($lead->tags()->count())->toBe(2);
});

it('treats the same tag typed differently as one tag', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $other = Fixtures::lead($team, ['dedupe_key' => 'no:other']);

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['Warm']])
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $other->getKey()]), ['tags' => ['  warm ']])
        ->assertRedirect();

    expect(LeadTag::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('records what changed about the tags, and nothing when nothing changed', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['warm', 'uk']])
        ->assertRedirect();

    // Re-saving the same set is not something the team did.
    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['uk', 'warm']])
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['warm', 'hot']])
        ->assertRedirect();

    $entries = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::TagsChanged)
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->first()?->properties)->toBe(['added' => ['uk', 'warm'], 'removed' => []])
        ->and($entries->last()?->properties)->toBe(['added' => ['hot'], 'removed' => ['uk']]);
});

it('clears a lead’s tags when sent an empty set', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['warm']])
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => []])
        ->assertRedirect();

    expect($lead->tags()->count())->toBe(0)
        // The tag itself survives — the team may still be using it elsewhere.
        ->and(LeadTag::query()->withoutGlobalScopes()->count())->toBe(1);
});

// --- Assignment ----------------------------------------------------------------------------

it('assigns and unassigns, naming who on the timeline', function (): void {
    [$owner, $team] = Fixtures::team();

    $colleague = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    $this->actingAs($owner)
        ->put(route('leads.assignees.set', ['lead' => $lead->getKey()]), [
            'user_ids' => [$owner->getKey(), $colleague->getKey()],
        ])
        ->assertRedirect();

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(2);

    $this->actingAs($owner)
        ->put(route('leads.assignees.set', ['lead' => $lead->getKey()]), [
            'user_ids' => [$colleague->getKey()],
        ])
        ->assertRedirect();

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(1);

    $assigned = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::Assigned)
        ->firstOrFail();
    $unassigned = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::Unassigned)
        ->firstOrFail();

    expect($assigned->properties['members'])->toContain($colleague->name)
        ->and($unassigned->properties['members'])->toBe([$owner->name]);
});

it('drops someone who is not in the team rather than failing the whole change', function (): void {
    [$owner, $team] = Fixtures::team();

    $colleague = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    // A validation rule catches an outsider at the edge; the Action is the backstop for a member
    // who left between page load and save.
    $this->actingAs($owner)
        ->putJson(route('leads.assignees.set', ['lead' => $lead->getKey()]), [
            'user_ids' => [$colleague->getKey(), 999_999],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_ids.1');

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('assigning the same member twice is a no-op, not a second row', function (): void {
    [$owner, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    foreach ([1, 2] as $ignored) {
        $this->actingAs($owner)
            ->put(route('leads.assignees.set', ['lead' => $lead->getKey()]), [
                'user_ids' => [$owner->getKey()],
            ])
            ->assertRedirect();
    }

    expect(LeadAssignment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::Assigned)->count())
        ->toBe(1);
});

// --- Notes ---------------------------------------------------------------------------------

it('writes a note, with a timeline excerpt and an audit row that holds no note text', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->post(route('leads.notes.store', ['lead' => $lead->getKey()]), [
            'body' => '   Spoke to the owner — call back Tuesday.   ',
        ])
        ->assertRedirect();

    $note = LeadNote::query()->withoutGlobalScopes()->firstOrFail();
    $activity = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::NoteAdded)
        ->firstOrFail();
    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.note_added')->firstOrFail();

    expect($note->body)->toBe('Spoke to the owner — call back Tuesday.')
        ->and((int) $note->user_id)->toBe((int) $user->getKey())
        ->and($activity->properties['excerpt'])->toContain('Spoke to the owner')
        // The trail records that a note exists and how long it is, never what it says.
        ->and($audit->properties)->toHaveKey('length')
        ->and(json_encode($audit->properties))->not->toContain('Spoke to the owner');
});

it('takes the timeline excerpt with the note when it is deleted', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->post(route('leads.notes.store', ['lead' => $lead->getKey()]), ['body' => 'Private detail.'])
        ->assertRedirect();

    $note = LeadNote::query()->withoutGlobalScopes()->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->delete(route('leads.notes.destroy', ['note' => $note->getKey()]))
        ->assertRedirect();

    // The excerpt is a copy of the note, so leaving it would leave a deleted note readable.
    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::NoteAdded)->count())
        ->toBe(0);
});

it('refuses to let a teammate delete someone else’s note, but lets an admin', function (): void {
    [$owner, $team] = Fixtures::team();

    $member = Fixtures::member($team, TeamRole::Member);
    $admin = Fixtures::member($team, TeamRole::Admin);
    $lead = Fixtures::lead($team);

    $this->actingAs($owner)
        ->post(route('leads.notes.store', ['lead' => $lead->getKey()]), ['body' => 'Mine.'])
        ->assertRedirect();

    $note = LeadNote::query()->withoutGlobalScopes()->firstOrFail();

    $this->actingAs($member)
        ->delete(route('leads.notes.destroy', ['note' => $note->getKey()]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->delete(route('leads.notes.destroy', ['note' => $note->getKey()]))
        ->assertRedirect();

    expect(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('validates a note', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->postJson(route('leads.notes.store', ['lead' => $lead->getKey()]), ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');

    $this->actingAs($user)
        ->postJson(route('leads.notes.store', ['lead' => $lead->getKey()]), [
            'body' => str_repeat('x', 5_001),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

// --- Tasks ---------------------------------------------------------------------------------

it('adds a task with a due date and a reminder', function (): void {
    [$user, $team] = Fixtures::team();

    $colleague = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->post(route('leads.tasks.store', ['lead' => $lead->getKey()]), [
            'title' => '  Call them back  ',
            'assigned_to' => $colleague->getKey(),
            'due_at' => '2026-11-05 09:00',
            'remind_at' => '2026-11-04 09:00',
        ])
        ->assertRedirect();

    $task = LeadTask::query()->withoutGlobalScopes()->firstOrFail();

    expect($task->title)->toBe('Call them back')
        ->and($task->status)->toBe(LeadTaskStatus::Open)
        ->and((int) $task->assigned_to)->toBe((int) $colleague->getKey())
        ->and($task->due_at?->toDateTimeString())->toBe('2026-11-05 09:00:00')
        // Stored now, swept by Notifications later — and not yet sent.
        ->and($task->remind_at?->toDateTimeString())->toBe('2026-11-04 09:00:00')
        ->and($task->reminded_at)->toBeNull()
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TaskCreated)->count())
        ->toBe(1);
});

it('accepts a reminder with no due date', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->post(route('leads.tasks.store', ['lead' => $lead->getKey()]), [
            'title' => 'Chase on Friday',
            'remind_at' => '2026-11-06 09:00',
        ])
        ->assertRedirect();

    $task = LeadTask::query()->withoutGlobalScopes()->firstOrFail();

    expect($task->due_at)->toBeNull()
        ->and($task->remind_at)->not->toBeNull();
});

it('ticks a task off, and does nothing the second time', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    foreach ([1, 2] as $ignored) {
        $this->actingAs($user)
            ->from(route('leads.show', ['lead' => $lead->getKey()]))
            ->put(route('leads.tasks.complete', ['task' => $task->getKey()]), ['done' => true])
            ->assertRedirect();
    }

    expect($task->refresh()->status)->toBe(LeadTaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull()
        ->and((int) $task->completed_by)->toBe((int) $user->getKey())
        // A double-tap on a phone must not put two "Task done" entries on the lead.
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TaskCompleted)->count())
        ->toBe(1);
});

it('re-opens a task, clearing the completion but keeping the history', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->done()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    app(TeamContext::class)->run($team, fn () => LeadActivity::factory()
        ->type(LeadActivityType::TaskCompleted, ['title' => $task->title])
        ->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->getKey(),
        ]));

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->put(route('leads.tasks.complete', ['task' => $task->getKey()]), ['done' => false])
        ->assertRedirect();

    expect($task->refresh()->status)->toBe(LeadTaskStatus::Open)
        ->and($task->completed_at)->toBeNull()
        // It genuinely was done at that moment, and history isn't edited.
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TaskCompleted)->count())
        ->toBe(1);
});

it('lets a colleague tick off someone else’s task', function (): void {
    [, $team] = Fixtures::team();

    $assignee = Fixtures::member($team, TeamRole::Member);
    $colleague = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()
        ->assignedTo((int) $assignee->getKey())
        ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]));

    // A task only one absent person can close is a task that stays open.
    $this->actingAs($colleague)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->put(route('leads.tasks.complete', ['task' => $task->getKey()]), ['done' => true])
        ->assertRedirect();

    expect($task->refresh()->status)->toBe(LeadTaskStatus::Done);
});

it('refuses a task tick from a read-only member', function (): void {
    [, $team] = Fixtures::team();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);
    $lead = Fixtures::lead($team);

    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    $this->actingAs($readOnly)
        ->put(route('leads.tasks.complete', ['task' => $task->getKey()]), ['done' => true])
        ->assertForbidden();

    expect($task->refresh()->status)->toBe(LeadTaskStatus::Open);
});

it('deletes a task and its timeline entries', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $this->actingAs($user)
        ->post(route('leads.tasks.store', ['lead' => $lead->getKey()]), ['title' => 'Call them'])
        ->assertRedirect();

    $task = LeadTask::query()->withoutGlobalScopes()->firstOrFail();

    $this->actingAs($user)
        ->from(route('leads.show', ['lead' => $lead->getKey()]))
        ->delete(route('leads.tasks.destroy', ['task' => $task->getKey()]))
        ->assertRedirect();

    expect(LeadTask::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::TaskCreated)->count())
        ->toBe(0);
});

it('keeps an open task when the member it was given to is deleted', function (): void {
    [, $team] = Fixtures::team();

    $assignee = Fixtures::member($team, TeamRole::Member);
    $lead = Fixtures::lead($team);

    app(TeamContext::class)->run($team, fn () => LeadTask::factory()
        ->assignedTo((int) $assignee->getKey())
        ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]));

    $assignee->delete();

    $task = LeadTask::query()->withoutGlobalScopes()->firstOrFail();

    expect($task->assigned_to)->toBeNull()
        ->and($task->status)->toBe(LeadTaskStatus::Open);
});

// --- Read-only across the board -------------------------------------------------------------

it('refuses every write from a read-only member', function (): void {
    [, $team] = Fixtures::team();

    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);
    $lead = Fixtures::lead($team);

    $this->actingAs($readOnly)
        ->put(route('leads.tags.sync', ['lead' => $lead->getKey()]), ['tags' => ['nope']])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->put(route('leads.assignees.set', ['lead' => $lead->getKey()]), ['user_ids' => []])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->post(route('leads.notes.store', ['lead' => $lead->getKey()]), ['body' => 'nope'])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->post(route('leads.tasks.store', ['lead' => $lead->getKey()]), ['title' => 'nope'])
        ->assertForbidden();

    // …and the detail screen tells the client not to offer the controls.
    $this->actingAs($readOnly)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.work', false));
});

it('404s rather than touching another team’s lead', function (): void {
    [$user] = Fixtures::team();
    [, $foreign] = Fixtures::foreignLead();

    $this->actingAs($user)
        ->put(route('leads.tags.sync', ['lead' => $foreign->getKey()]), ['tags' => ['sneaky']])
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('leads.notes.store', ['lead' => $foreign->getKey()]), ['body' => 'sneaky'])
        ->assertNotFound();

    expect(LeadTag::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadNote::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('renders the team’s work on the detail screen', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadNote::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'body' => 'Rang them.',
        ]);

        LeadTask::factory()->overdue()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'title' => 'Chase',
        ]);
    });

    $this->actingAs($user)
        ->get(route('leads.show', ['lead' => $lead->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notes', 1)
            ->where('notes.0.body', 'Rang them.')
            ->where('notes.0.can_delete', true)
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Chase')
            ->where('tasks.0.is_overdue', true)
            ->has('stages', 5)
            ->has('members', 1)
            ->where('can.work', true));
});
