<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\PurgeExpiredDataAction;
use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The retention sweep (Phase 15, docs/compliance.md §3.4).
 *
 * The two branches are the policy, so each is tested for what it removes **and** for what it
 * leaves: a sweep that deleted the accountability record along with the personal data would be as
 * wrong as one that deleted nothing.
 */
it('deletes a lead nobody ever touched, once it is past the window', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(45));

    $counts = app(PurgeExpiredDataAction::class)->handle($team);

    expect($counts[RetentionAction::Deleted->value])->toBe(1)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('leaves a lead that is inside the window', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(10));

    app(PurgeExpiredDataAction::class)->handle($team);

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
});

it('does not delete a lead the team worked, even with no logged approach', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 3650);

    $lead = app(TeamContext::class)->run($team, function () use ($team, $user): Lead {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        // A note is work. Deleting a company somebody has been nursing through a pipeline because
        // nobody pressed "log a call" would be the sweep destroying live work.
        LeadNote::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return $lead;
    });

    Fixtures::ageLead($lead, now()->subDays(90));

    $counts = app(PurgeExpiredDataAction::class)->handle($team);

    expect($counts[RetentionAction::Deleted->value])->toBe(0)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);
});

it('anonymises a worked lead that has gone quiet, and keeps the company and the approach', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    [$lead, $contact, $message, $attempt] = workedLead($team, $user);

    Fixtures::ageLead($lead, now()->subDays(200));

    $counts = app(PurgeExpiredDataAction::class)->handle($team);

    expect($counts[RetentionAction::Anonymised->value])->toBe(1);

    $lead = Lead::withoutGlobalScopes()->findOrFail($lead->getKey());

    // What stays: the company, and the fact, channel and outcome of the approach.
    expect($lead->anonymised_at)->not->toBeNull()
        ->and($lead->company_name)->toBe('Acme Ltd')
        ->and($lead->has_personal_data)->toBeFalse()
        ->and(ContactAttempt::withoutGlobalScopes()->whereKey($attempt->getKey())->exists())->toBeTrue();

    // What goes: the contact, the message text and address, the attempt note, the notes and tasks.
    expect(LeadContact::withoutGlobalScopes()->whereKey($contact->getKey())->exists())->toBeFalse();

    $message = OutreachMessage::withoutGlobalScopes()->findOrFail($message->getKey());

    expect($message->body)->toBe('')
        ->and($message->subject)->toBeNull()
        ->and($message->variants)->toBeNull()
        ->and($message->sent_to)->toBeNull()
        ->and(ContactAttempt::withoutGlobalScopes()->findOrFail($attempt->getKey())->notes)->toBeNull()
        ->and(LeadNote::withoutGlobalScopes()->where('lead_id', $lead->getKey())->count())->toBe(0)
        ->and(LeadTask::withoutGlobalScopes()->where('lead_id', $lead->getKey())->count())->toBe(0);
});

it('strips the free text from timeline entries but keeps the entries', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    [$lead] = workedLead($team, $user);

    // The entries that carry free text: a note excerpt, a task title, a news headline. Written
    // directly, because the Actions that normally write them would stamp today's date.
    app(TeamContext::class)->run($team, function () use ($team, $lead, $user): void {
        foreach ([
            [LeadActivityType::NoteAdded, ['excerpt' => 'Dana said to call back in March.']],
            [LeadActivityType::TaskCreated, ['title' => 'Call Dana back']],
            [LeadActivityType::SignalConverted, ['signal_id' => 1, 'headline' => 'Dana Buyer sells Acme Ltd']],
        ] as [$type, $properties]) {
            LeadActivity::query()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'user_id' => $user->getKey(),
                'type' => $type,
                'properties' => $properties,
                'occurred_at' => now()->subDays(200),
            ]);
        }
    });

    Fixtures::ageLead($lead, now()->subDays(200));

    app(PurgeExpiredDataAction::class)->handle($team);

    $properties = app(TeamContext::class)->run($team, fn (): array => Lead::withoutGlobalScopes()
        ->findOrFail($lead->getKey())
        ->activities()
        ->get()
        ->pluck('properties')
        ->filter()
        ->flatMap(static fn (array $p): array => array_keys($p))
        ->unique()
        ->values()
        ->all());

    // The channel, the outcome and the signal id are what the timeline renders its sentence from;
    // the note excerpt, the task title and the news headline are free text and go.
    expect($properties)->not->toContain('excerpt')
        ->and($properties)->not->toContain('title')
        ->and($properties)->not->toContain('headline')
        ->and($properties)->toContain('channel')
        ->and($properties)->toContain('signal_id');
});

it('keeps the outreach restriction when it empties the provider payload', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    [$lead] = workedLead($team, $user, [
        'raw' => [
            OutreachRestriction::RAW_KEY => OutreachRestriction::UaePriorConsent->value,
            'provider_payload' => ['officer_name' => 'Dana Buyer'],
        ],
    ]);

    Fixtures::ageLead($lead, now()->subDays(200));

    app(PurgeExpiredDataAction::class)->handle($team);

    $lead = Lead::withoutGlobalScopes()->findOrFail($lead->getKey());

    // A restriction may be added to a lead but never dropped from one (docs/compliance.md §5a).
    // Clearing `raw` wholesale would quietly reopen cold email on a lead that must never have it.
    expect(OutreachRestriction::fromRaw($lead->raw))->toBe(OutreachRestriction::UaePriorConsent)
        ->and($lead->raw)->not->toHaveKey('provider_payload');
});

it('deletes the news signal a lead was converted from', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    [$lead] = workedLead($team, $user);

    app(TeamContext::class)->run($team, fn () => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'status' => SignalStatus::Converted,
    ]));

    Fixtures::ageLead($lead, now()->subDays(200));

    app(PurgeExpiredDataAction::class)->handle($team);

    // A signal holds a named individual's name and role. `signals:prune` never sweeps a converted
    // one, so if this sweep left it behind nothing would ever remove it (docs/compliance.md §5a).
    expect(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('deletes the signals attached to a lead it deletes outright', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, function () use ($team): Lead {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'status' => SignalStatus::Converted,
        ]);

        return $lead;
    });

    Fixtures::ageLead($lead, now()->subDays(45));

    app(PurgeExpiredDataAction::class)->handle($team);

    // The foreign key is `nullOnDelete`, so without the explicit delete the signal would outlive
    // its lead with a person's name still on it and nothing left to sweep it.
    expect(Lead::withoutGlobalScopes()->count())->toBe(0)
        ->and(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('is idempotent — a second sweep finds nothing left to do', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    [$lead] = workedLead($team, $user);
    Fixtures::ageLead($lead, now()->subDays(200));

    app(PurgeExpiredDataAction::class)->handle($team);
    $second = app(PurgeExpiredDataAction::class)->handle($team);

    expect($second[RetentionAction::Anonymised->value])->toBe(0)
        ->and($second[RetentionAction::Deleted->value])->toBe(0);
});

it('skips a team that has switched the sweep off', function (): void {
    [, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => RetentionPolicy::factory()
        ->disabled()
        ->windows(30)
        ->create(['team_id' => $team->getKey()]));

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    $counts = app(PurgeExpiredDataAction::class)->handle($team);

    expect($counts['skipped'])->toBe(1)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);
});

it('sweeps a team that has never saved a policy, using the configured defaults', function (): void {
    [, $team] = Fixtures::team();

    config()->set('compliance.retention.defaults.uncontacted_lead_days', 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    // Storage limitation applies from the first day, not from the first visit to the settings
    // screen — so a team with no row is still swept.
    app(PurgeExpiredDataAction::class)->handle($team);

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('reports what it would do without doing it, on a dry run', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30, contactedDays: 60);

    $untouched = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($untouched, now()->subDays(90));

    [$worked] = workedLead($team, $user);
    Fixtures::ageLead($worked, now()->subDays(200));

    $counts = app(PurgeExpiredDataAction::class)->handle($team, dryRun: true);

    expect($counts[RetentionAction::Deleted->value])->toBe(1)
        ->and($counts[RetentionAction::Anonymised->value])->toBe(1)
        // Retention is irreversible, so a team must be able to see the damage before doing it.
        ->and(Lead::withoutGlobalScopes()->count())->toBe(2)
        ->and(Lead::withoutGlobalScopes()->whereNotNull('anonymised_at')->count())->toBe(0);
});

it('records the run on the policy and writes an audit entry with no actor', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    app(PurgeExpiredDataAction::class)->handle($team);

    $policy = RetentionPolicy::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($policy->last_swept_at)->not->toBeNull()
        ->and($policy->last_deleted_count)->toBe(1);

    $entry = AuditLog::query()->where('action', 'compliance.retention_swept')->sole();

    // Nobody did this, the policy did — and the entry carries counts and windows, never a company
    // name (docs/compliance.md §3.8, §3.9).
    expect($entry->user_id)->toBeNull()
        ->and($entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['deleted'])->toBe(1)
        ->and($entry->properties)->toHaveKey('uncontacted_days');
});

it('reads the same candidates whatever team happens to be in context', function (): void {
    [$userA, $teamA] = Fixtures::team();
    [$userB, $teamB] = Fixtures::team();

    Fixtures::retention($teamB, uncontactedDays: 30, contactedDays: 60);

    // A lead team B has worked, and left alone.
    [$worked] = workedLead($teamB, $userB);
    Fixtures::ageLead($worked, now()->subDays(200));

    expect($userA->getKey())->not->toBe($userB->getKey());

    // Sweeping B while **A** is the active team. The existence subqueries run over tenant-scoped
    // models, so without an explicit scope they would see team A and find no work on B's lead —
    // making a worked lead look untouched and sending it to the delete branch.
    $counts = app(TeamContext::class)->run(
        $teamA,
        fn (): array => app(PurgeExpiredDataAction::class)->handle($teamB),
    );

    expect($counts[RetentionAction::Deleted->value])->toBe(0)
        ->and($counts[RetentionAction::Anonymised->value])->toBe(1)
        ->and(Lead::withoutGlobalScopes()->whereKey($worked->getKey())->exists())->toBeTrue();
});

it('never sweeps another team’s leads', function (): void {
    [, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    Fixtures::retention($teamA, uncontactedDays: 30);
    Fixtures::retention($teamB, uncontactedDays: 30);

    foreach ([$teamA, $teamB] as $team) {
        $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
        Fixtures::ageLead($lead, now()->subDays(90));
    }

    app(PurgeExpiredDataAction::class)->handle($teamA);

    expect(Lead::withoutGlobalScopes()->where('team_id', $teamA->getKey())->count())->toBe(0)
        ->and(Lead::withoutGlobalScopes()->where('team_id', $teamB->getKey())->count())->toBe(1);
});

// --- The settings screen ------------------------------------------------------------------------

it('shows the windows and what tonight would cost', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    $this->actingAs($user)
        ->get(route('compliance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('compliance/Index')
            ->where('retention.uncontacted_lead_days', 30)
            ->where('retention_preview.deletable', 1)
            ->where('can.manage_retention', true));
});

it('saves the windows, clamped and audited', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->patch(route('compliance.retention.update'), [
            'is_enabled' => true,
            'uncontacted_lead_days' => 60,
            'contacted_lead_days' => 400,
        ])
        ->assertRedirect();

    $policy = RetentionPolicy::withoutGlobalScopes()->sole();

    expect($policy->uncontacted_lead_days)->toBe(60)
        ->and($policy->contacted_lead_days)->toBe(400)
        ->and($policy->updated_by)->toBe($user->getKey())
        ->and($policy->team_id)->toBe((int) $team->getKey());

    $entry = AuditLog::query()->where('action', 'compliance.retention_policy_saved')->sole();

    // The old values as well as the new ones: "who shortened retention, and from what" is the
    // question the entry exists to answer.
    expect($entry->properties['uncontacted_days'])->toBe(60)
        ->and($entry->properties)->toHaveKey('was_uncontacted_days');
});

it('refuses windows the wrong way round rather than swapping them', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->patch(route('compliance.retention.update'), [
            'is_enabled' => true,
            'uncontacted_lead_days' => 400,
            'contacted_lead_days' => 60,
        ])
        ->assertRedirect();

    // The two numbers mean different things — one deletes, one anonymises — so guessing which the
    // team meant would be guessing about deletion.
    expect(RetentionPolicy::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects a window shorter than the configured floor', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->patch(route('compliance.retention.update'), [
            'is_enabled' => true,
            'uncontacted_lead_days' => 1,
            'contacted_lead_days' => 400,
        ])
        ->assertSessionHasErrors('uncontacted_lead_days');
});

it('forbids a plain member from changing the windows', function (): void {
    [, $team] = Fixtures::team();
    $member = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($member)
        ->patch(route('compliance.retention.update'), [
            'is_enabled' => false,
            'uncontacted_lead_days' => 30,
            'contacted_lead_days' => 30,
        ])
        ->assertForbidden();

    expect(RetentionPolicy::withoutGlobalScopes()->count())->toBe(0);
});

/**
 * A lead the team has worked: a contact, a sent message, a logged approach with a note, a note, a
 * task, a board position, and a timeline carrying free text.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{Lead, LeadContact, OutreachMessage, ContactAttempt}
 */
function workedLead($team, $user, array $attributes = []): array
{
    return app(TeamContext::class)->run($team, function () use ($team, $user, $attributes): array {
        $stage = PipelineStage::factory()->create(['team_id' => $team->getKey()]);

        $lead = Lead::factory()->create([
            'company_name' => 'Acme Ltd',
            'has_personal_data' => true,
            'pipeline_stage_id' => $stage->getKey(),
            ...$attributes,
            'team_id' => $team->getKey(),
        ]);

        $contact = LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Dana Buyer',
            'email' => 'dana@acme.test',
            'phone' => '+44 20 7946 0000',
        ]);

        $message = OutreachMessage::factory()
            ->forContact((int) $contact->getKey())
            ->sent('dana@acme.test')
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'created_by' => $user->getKey(),
            ]);

        LeadNote::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'user_id' => $user->getKey(),
            'body' => 'Dana said to call back in March.',
        ]);

        LeadTask::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'created_by' => $user->getKey(),
            'title' => 'Call Dana back',
        ]);

        $attempt = Fixtures::attempt($team, $lead, now()->subDays(200), (int) $contact->getKey());

        // The draft is aged with the lead. A quiet lead's messages are old ones, and a draft
        // written *since* the window is a lead somebody is still using — which the sweep
        // deliberately leaves alone (see `DataRetention::anonymisable()`).
        $message->timestamps = false;
        $message->forceFill(['created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)])->save();
        $message->timestamps = true;

        return [$lead, $contact, $message->refresh(), $attempt];
    });
}
