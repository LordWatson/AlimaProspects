<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Data\CreateLeadData;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Adding one lead by hand (Phase 10c) — the company a member met at an event.
 *
 * The cases that matter: the fields land where they should, the team's existing record of a company
 * is filled in rather than duplicated (including a company that arrived by CSV), the contact's
 * compliance flags are right, Read-only can't do it, and another team's column can't be targeted.
 */

/**
 * The form as a member would post it, with only what the test cares about overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manualLead(array $overrides = []): array
{
    return [
        'company_name' => 'Bright Bean Coffee',
        ...$overrides,
    ];
}

/**
 * A CSV upload, so the "added by hand and then imported" case can be proven end to end. Declared
 * here rather than reusing `LeadCsvTest`'s helper: Pest's load order between test files isn't
 * guaranteed, which is the same reason the workspace fixtures are a class.
 */
function manualLeadCsv(string $contents): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'alima-manual-csv');

    file_put_contents($path, $contents);

    return new UploadedFile($path, 'leads.csv', 'text/csv', null, true);
}

// --- The happy path ------------------------------------------------------------------------

it('adds a lead by hand, in the starting stage, attributed to the member', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.create'))
        ->post(route('leads.store'), manualLead([
            'website' => 'brightbean.co.uk',
            'company_number' => '11112222',
            'industry' => 'Hospitality',
            'employee_range' => '11-50',
            'locality' => 'London',
            'region' => 'Greater London',
            'postal_code' => 'E1 6AN',
            'country' => 'GB',
            'tags' => ['met at expo', 'Warm'],
            'note' => "Talked about their second site.\nFollow up in a fortnight.",
        ]))
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->sole();

    expect($lead->team_id)->toBe($team->id)
        // Not a provider: the team's own record of who this company is.
        ->and($lead->source)->toBe(LeadSource::Manual)
        ->and($lead->enrichment_status)->toBe(EnrichmentStatus::Pending)
        ->and($lead->company_name)->toBe('Bright Bean Coffee')
        ->and($lead->company_number)->toBe('11112222')
        // A bare domain is what someone types off a card; the scheme is added for them.
        ->and($lead->website)->toBe('https://brightbean.co.uk')
        ->and($lead->industry)->toBe('Hospitality')
        ->and($lead->employee_range)->toBe('11-50')
        ->and($lead->locality)->toBe('London')
        ->and($lead->region)->toBe('Greater London')
        ->and($lead->postal_code)->toBe('E1 6AN')
        ->and($lead->country)->toBe('GB')
        ->and($lead->collected_at)->not->toBeNull()
        // Keyed by registry number, exactly as a discovered or imported lead is.
        ->and($lead->dedupe_key)->toBe('no:11112222');

    $stage = PipelineStage::query()->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('is_default', true)
        ->sole();

    expect($lead->pipeline_stage_id)->toBe($stage->id)
        // Order is the relation's (by name, in the database's collation), so the set is what
        // matters here rather than the sequence.
        ->and($lead->tags()->pluck('name')->all())->toEqualCanonicalizing(['met at expo', 'Warm']);

    $note = LeadNote::query()->withoutGlobalScopes()->sole();

    // Kept as typed — a three-line note must not be flattened into one.
    expect($note->body)->toBe("Talked about their second site.\nFollow up in a fortnight.")
        ->and($note->user_id)->toBe($user->id);

    $activity = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::Created)
        ->sole();

    expect($activity->lead_id)->toBe($lead->id)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->properties['merged'])->toBeFalse();

    // The normal pipeline picks it up from here, exactly as it would a discovered lead.
    Queue::assertPushed(
        EnrichLeadJob::class,
        fn (EnrichLeadJob $job): bool => $job->leadId === $lead->id && $job->teamId === $team->id,
    );
});

it('needs nothing but a company name', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead())->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->sole();

    expect($lead->company_name)->toBe('Bright Bean Coffee')
        ->and($lead->website)->toBeNull()
        ->and($lead->country)->toBeNull()
        // Name-only, so the weakest key there is — and still never empty.
        ->and($lead->dedupe_key)->toBe('name:bright-bean-coffee')
        ->and($lead->contacts()->count())->toBe(0)
        // No contact means no personal data, whatever the company fields say.
        ->and($lead->has_personal_data)->toBeFalse();
});

it('records the person as a contact, with their email and phone', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'contact_name' => 'Nadia Hassan',
        'contact_title' => 'Head of Operations',
        'contact_email' => 'Nadia@brightbean.co.uk',
        'contact_phone' => '+44 20 7946 0000',
    ]))->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->sole();
    $contact = $lead->contacts()->withoutGlobalScopes()->sole();

    // Unlike a signal's contact, a card handed over at an event carries both.
    expect($contact->name)->toBe('Nadia Hassan')
        ->and($contact->title)->toBe('Head of Operations')
        ->and($contact->email)->toBe('nadia@brightbean.co.uk')
        ->and($contact->phone)->toBe('+44 20 7946 0000')
        ->and($contact->source)->toBe(CreateLeadAction::CONTACT_SOURCE)
        ->and($contact->method)->toBe(CreateLeadAction::CONTACT_METHOD)
        // A named individual is personal data, and nothing here has been verified.
        ->and($contact->is_personal)->toBeTrue()
        ->and($contact->is_role_based)->toBeFalse()
        ->and($contact->email_verification_status)->toBeNull()
        ->and($lead->has_personal_data)->toBeTrue();
});

it('treats a role mailbox with no name as the company’s front door, not a person', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'contact_email' => 'info@brightbean.co.uk',
    ]))->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->sole();
    $contact = $lead->contacts()->withoutGlobalScopes()->sole();

    expect($contact->is_role_based)->toBeTrue()
        ->and($contact->is_personal)->toBeFalse()
        ->and($lead->has_personal_data)->toBeFalse();
});

// --- The company the team already has ------------------------------------------------------

it('fills in the lead the team already had rather than making a second one', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $existing = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        // The factory derives its key from its own generated number, so an overridden number has
        // to bring the key with it.
        'dedupe_key' => 'no:11112222',
        'locality' => 'London',
        'website' => null,
        'industry' => null,
        'enrichment_status' => EnrichmentStatus::Enriched,
    ]);

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        // A different spelling of the same company, with something new and something stale.
        'company_name' => 'BRIGHT BEAN COFFEE LTD',
        'company_number' => '11112222',
        'website' => 'brightbean.co.uk',
        'locality' => 'Manchester',
        'tags' => ['met at expo'],
    ]))->assertRedirect(route('leads.show', ['lead' => $existing->getKey()]));

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);

    $existing->refresh();

    // Gaps filled; nothing the lead already held overwritten — not the name the team has on
    // record, and not the town it was already filed under.
    expect($existing->website)->toBe('https://brightbean.co.uk')
        ->and($existing->company_name)->toBe('Bright Bean Coffee')
        ->and($existing->locality)->toBe('London')
        ->and($existing->tags()->pluck('name')->all())->toBe(['met at expo']);

    $activity = LeadActivity::query()->withoutGlobalScopes()
        ->where('type', LeadActivityType::Created)
        ->sole();

    // The timeline says it was a merge, so the screen can too.
    expect($activity->properties['merged'])->toBeTrue();

    // A company the team has already enriched is not re-spent on.
    Queue::assertNotPushed(EnrichLeadJob::class);
});

it('collapses a hand-added company and the same company from a CSV into one lead', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    // Added by hand at the event…
    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'website' => 'brightbean.co.uk',
    ]))->assertRedirect();

    // …then the spreadsheet that already had them, keyed the same way.
    $this->actingAs($user)->post(route('leads.import.store'), [
        'file' => manualLeadCsv("Company,Site\nBright Bean Coffee,brightbean.co.uk\n"),
        'mapping' => ['company_name' => 'Company', 'website' => 'Site'],
    ]);

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);

    $lead = Lead::query()->withoutGlobalScopes()->sole();

    // The hand-added record keeps its attribution: on a rank tie the older row wins, and it was
    // first. Both ways in are the team's own, so neither gets to rename the other's company.
    expect($lead->source)->toBe(LeadSource::Manual)
        ->and($lead->dedupe_key)->toBe('domain:brightbean.co.uk');
});

it('does not stack up a second copy of the same contact', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $payload = manualLead([
        'company_number' => '11112222',
        'contact_name' => 'Nadia Hassan',
        'contact_email' => 'nadia@brightbean.co.uk',
    ]);

    $this->actingAs($user)->post(route('leads.store'), $payload)->assertRedirect();
    $this->actingAs($user)->post(route('leads.store'), $payload)->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->sole();

    expect($lead->contacts()->withoutGlobalScopes()->count())->toBe(1);
});

it('gives each team its own lead for the same company', function (): void {
    Queue::fake();

    [, $teamA] = Fixtures::team();
    [$userB, $teamB] = Fixtures::team();

    $leadA = Fixtures::lead($teamA, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        'dedupe_key' => 'no:11112222',
        'locality' => 'London',
    ]);

    // The key is unique **per team**, so Team B adding the same company is a lead of their own and
    // must not touch — or even see — Team A's record of it.
    $this->actingAs($userB)->post(route('leads.store'), manualLead([
        'company_number' => '11112222',
        'locality' => 'Manchester',
        'tags' => ['met at expo'],
    ]))->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(2);

    $leadB = Lead::query()->withoutGlobalScopes()
        ->where('team_id', $teamB->getKey())
        ->sole();

    expect($leadB->getKey())->not->toBe($leadA->getKey())
        ->and($leadB->locality)->toBe('Manchester');

    $leadA->refresh();

    expect($leadA->locality)->toBe('London')
        ->and($leadA->tags()->withoutGlobalScopes()->count())->toBe(0)
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('lead_id', $leadA->getKey())->count())->toBe(0);
});

it('refuses another team’s column on the API too', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team();

    $foreignStage = app(TeamContext::class)->run($otherTeam, fn (): PipelineStage => PipelineStage::factory()
        ->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user)
        ->postJson('/api/v1/leads', manualLead(['pipeline_stage_id' => $foreignStage->getKey()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pipeline_stage_id');

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps a read-only member out of the API create', function (): void {
    [, $team] = Fixtures::team();
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)
        ->postJson('/api/v1/leads', manualLead())
        ->assertForbidden();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('files the lead in the column the member picked', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $stages = app(EnsureDefaultPipelineStagesAction::class)->handle($team);
    $working = $stages->firstWhere('is_default', false);

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'pipeline_stage_id' => $working?->getKey(),
    ]))->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->sole()->pipeline_stage_id)->toBe($working?->id);
});

it('ignores an out-of-team column when the Action is called directly', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();
    [, $otherTeam] = Fixtures::team();

    $foreignStage = app(TeamContext::class)->run($otherTeam, fn (): PipelineStage => PipelineStage::factory()
        ->create(['team_id' => $otherTeam->getKey()]));

    // The request refuses this first, so the Action's own fallback only runs for a caller that
    // isn't the form — a job, or a future importer. It drops the id into the default column rather
    // than failing a create the member had every right to make.
    $result = app(CreateLeadAction::class)->handle($team, $user, CreateLeadData::fromValidated([
        'company_name' => 'Bright Bean Coffee',
        'pipeline_stage_id' => $foreignStage->getKey(),
    ]));

    $default = PipelineStage::query()->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('is_default', true)
        ->sole();

    expect($result->merged)->toBeFalse()
        ->and($result->lead->pipeline_stage_id)->toBe($default->id);
});

it('clamps a company name that is longer than its column', function (): void {
    Queue::fake();

    [$user] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        // 255 is the column; the request caps it at 255 too, so this is the boundary rather than
        // an error the member has to understand.
        'company_name' => str_repeat('a', 255),
    ]))->assertRedirect();

    expect(mb_strlen(Lead::query()->withoutGlobalScopes()->sole()->company_name))->toBe(255);
});

// --- Validation, permissions and tenancy ---------------------------------------------------

it('requires a company name', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.create'))
        ->post(route('leads.store'), ['company_name' => '   '])
        ->assertSessionHasErrors('company_name');

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a country that is not a two-letter code', function (): void {
    [$user] = Fixtures::team();

    // Better a visible error than "United Kingdom" silently stored as "UN".
    $this->actingAs($user)
        ->from(route('leads.create'))
        ->post(route('leads.store'), manualLead(['country' => 'United Kingdom']))
        ->assertSessionHasErrors('country');
});

it('refuses an email that is not one', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.create'))
        ->post(route('leads.store'), manualLead(['contact_email' => 'not-an-address']))
        ->assertSessionHasErrors('contact_email');
});

it('drops a website Alima would not render as a link', function (): void {
    Queue::fake();

    [$user] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'website' => 'javascript:alert(1)',
    ]))->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->sole()->website)->toBeNull();
});

it('keeps a read-only member out', function (): void {
    [, $team] = Fixtures::team();
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)->get(route('leads.create'))->assertForbidden();
    $this->actingAs($readOnly)->post(route('leads.store'), manualLead())->assertForbidden();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('lets a plain member add one, unlike a CSV import', function (): void {
    Queue::fake();

    [, $team] = Fixtures::team();
    $member = Fixtures::member($team, TeamRole::Member);

    // Adding one company is ordinary work; moving a team's whole list in is not.
    $this->actingAs($member)->get(route('leads.create'))->assertOk();
    $this->actingAs($member)->post(route('leads.store'), manualLead())->assertRedirect();
    $this->actingAs($member)->get(route('leads.import'))->assertForbidden();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('will not file a lead in another team’s column', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team();

    $foreignStage = app(TeamContext::class)->run($otherTeam, fn (): PipelineStage => PipelineStage::factory()
        ->create(['team_id' => $otherTeam->getKey()]));

    $this->actingAs($user)
        ->from(route('leads.create'))
        ->post(route('leads.store'), manualLead(['pipeline_stage_id' => $foreignStage->getKey()]))
        ->assertSessionHasErrors('pipeline_stage_id');

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('writes an audit entry that records no personal detail', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->post(route('leads.store'), manualLead([
        'contact_name' => 'Nadia Hassan',
        'tags' => ['warm'],
    ]))->assertRedirect();

    $entry = AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.created')->sole();

    expect($entry->team_id)->toBe($team->id)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->properties['merged'])->toBeFalse()
        // Whether a contact was recorded, never who.
        ->and($entry->properties['has_contact'])->toBeTrue()
        ->and($entry->properties['tags'])->toBe(1)
        ->and(json_encode($entry->properties))->not->toContain('Nadia');
});

// --- The API ------------------------------------------------------------------------------

it('adds a lead from /api/v1 with 201, and 200 when it merged', function (): void {
    Queue::fake();

    [$user, $team] = Fixtures::team();

    $payload = manualLead(['company_number' => '11112222', 'website' => 'brightbean.co.uk']);

    $this->actingAs($user)
        ->postJson('/api/v1/leads', $payload)
        ->assertCreated()
        ->assertJsonPath('data.company_name', 'Bright Bean Coffee')
        ->assertJsonPath('data.source', LeadSource::Manual->value);

    // The same company again is the one they already have, which is a 200, not a second 201.
    $this->actingAs($user)
        ->postJson('/api/v1/leads', $payload)
        ->assertOk()
        ->assertJsonPath('data.source', LeadSource::Manual->value);

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses an unauthenticated API create', function (): void {
    $this->postJson('/api/v1/leads', manualLead())->assertUnauthorized();
});

it('validates the API create the same way as the form', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->postJson('/api/v1/leads', ['company_name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_name');
});
