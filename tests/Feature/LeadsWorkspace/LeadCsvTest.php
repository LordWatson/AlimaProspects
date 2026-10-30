<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\ImportLeadsAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * A CSV upload built from raw text, so a test can be as awkward about encoding and delimiters as a
 * real spreadsheet is.
 */
function csvUpload(string $contents, string $name = 'leads.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alima-csv');

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/**
 * Read a streamed export response into a string.
 */
function exportBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

// --- Import --------------------------------------------------------------------------------

it('imports leads, placing them in the starting stage', function (): void {
    [$user, $team] = Fixtures::team();

    $csv = <<<'CSV'
    Company,Number,Site,Town
    Bright Bean Coffee,11112222,brightbean.test,London
    Dubai Dates,,dubaidates.test,Dubai
    CSV;

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => [
                'company_name' => 'Company',
                'company_number' => 'Number',
                'website' => 'Site',
                'locality' => 'Town',
            ],
        ])
        ->assertRedirect();

    $leads = Lead::query()->withoutGlobalScopes()->orderBy('id')->get();
    $default = PipelineStage::query()->withoutGlobalScopes()->where('is_default', true)->firstOrFail();

    expect($leads)->toHaveCount(2)
        ->and($leads->first()?->company_name)->toBe('Bright Bean Coffee')
        ->and($leads->first()?->company_number)->toBe('11112222')
        ->and($leads->first()?->locality)->toBe('London')
        ->and((int) $leads->first()?->pipeline_stage_id)->toBe((int) $default->getKey())
        // An imported lead is never passed off as enriched; the normal pipeline fills it in.
        ->and($leads->first()?->enrichment_status)->toBe(EnrichmentStatus::Pending)
        ->and($leads->first()?->dedupe_key)->toBe('no:11112222')
        // No company number, so the domain is the identity.
        ->and($leads->last()?->dedupe_key)->toBe('domain:dubaidates.test')
        ->and(LeadActivity::query()->withoutGlobalScopes()->where('type', LeadActivityType::Imported)->count())
        ->toBe(2);
});

it('fills in a lead it already has rather than duplicating it', function (): void {
    [$user, $team] = Fixtures::team();

    $existing = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        'dedupe_key' => 'no:11112222',
        'website' => 'brightbean.test',
        'locality' => null,
        'industry' => 'Coffee roasting',
    ]);

    $csv = <<<'CSV'
    Company,Number,Town,Industry
    Bright Bean Coffee,11112222,London,Catering
    CSV;

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => [
                'company_name' => 'Company',
                'company_number' => 'Number',
                'locality' => 'Town',
                'industry' => 'Industry',
            ],
        ])
        ->assertRedirect();

    $existing->refresh();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1)
        // A gap is filled…
        ->and($existing->locality)->toBe('London')
        // …but what the platform already knew is not overwritten by the team's older spreadsheet.
        ->and($existing->industry)->toBe('Coffee roasting')
        ->and(LeadActivity::query()->withoutGlobalScopes()->firstOrFail()->properties)
        ->toBe(['merged' => true]);
});

it('imports a contact and tags alongside the company', function (): void {
    [$user] = Fixtures::team();

    $csv = <<<'CSV'
    Company,Contact,Role,Email,Labels
    Bright Bean Coffee,Hello Desk,Owner,HELLO@brightbean.test,"warm; trade show"
    CSV;

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => [
                'company_name' => 'Company',
                'contact_name' => 'Contact',
                'contact_title' => 'Role',
                'contact_email' => 'Email',
                'tags' => 'Labels',
            ],
        ])
        ->assertRedirect();

    $contact = LeadContact::query()->withoutGlobalScopes()->firstOrFail();

    expect($contact->name)->toBe('Hello Desk')
        ->and($contact->email)->toBe('hello@brightbean.test')
        ->and($contact->source)->toBe('csv_import')
        // Nothing from a spreadsheet is marked verified: it has had no MX check.
        ->and($contact->email_verification_status)->toBeNull()
        ->and(LeadTag::query()->withoutGlobalScopes()->pluck('slug')->sort()->values()->all())
        ->toBe(['trade show', 'warm']);
});

it('skips a row with no company name and says which', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Town\nBright Bean,London\n,Dubai\nDubai Dates,Dubai\n";

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => ['company_name' => 'Company', 'locality' => 'Town'],
        ])
        ->assertRedirect();

    // The bad row doesn't roll the good ones back.
    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(2);

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.imported')->firstOrFail();

    expect($audit->properties['created'])->toBe(2)
        ->and($audit->properties['skipped'])->toBe(1)
        // The trail records which fields were mapped, never the team's headings or their data.
        ->and($audit->properties['fields'])->toBe(['company_name', 'locality']);
});

it('reads a semicolon-delimited file, a BOM and Windows-1252 text', function (): void {
    [$user] = Fixtures::team();

    // What Excel on a European Windows machine actually writes.
    $csv = "\u{FEFF}Company;Town\n".mb_convert_encoding(
        "Café Crème;Lyon\n",
        'Windows-1252',
        'UTF-8',
    );

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => ['company_name' => 'Company', 'locality' => 'Town'],
        ])
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

    expect($lead->company_name)->toBe('Café Crème')
        ->and($lead->locality)->toBe('Lyon');
});

it('pads a row that is short rather than dropping the lead', function (): void {
    [$user] = Fixtures::team();

    // The trailing columns being empty is normal, and losing the row would lose the lead.
    $csv = "Company,Number,Town\nBright Bean\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => [
                'company_name' => 'Company',
                'company_number' => 'Number',
                'locality' => 'Town',
            ],
        ])
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

    expect($lead->company_name)->toBe('Bright Bean')
        ->and($lead->locality)->toBeNull();
});

it('keeps a two-letter country code and discards a country name', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Country\nBright Bean,gb\nDubai Dates,United Arab Emirates\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => ['company_name' => 'Company', 'country' => 'Country'],
        ])
        ->assertRedirect();

    $leads = Lead::query()->withoutGlobalScopes()->orderBy('id')->get();

    expect($leads->first()?->country)->toBe('GB')
        // The column is char(2), and silently truncating "United…" to "Un" would be worse than
        // leaving it out.
        ->and($leads->last()?->country)->toBeNull();
});

it('refuses a mapping that names something an import may not write', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->postJson(route('leads.import.store'), [
            'file' => csvUpload("Company,Team\nBright Bean,9\n"),
            'mapping' => ['company_name' => 'Company', 'team_id' => 'Team'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mapping');

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('requires a company-name column', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->postJson(route('leads.import.store'), [
            'file' => csvUpload("Company,Town\nBright Bean,London\n"),
            'mapping' => ['locality' => 'Town'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mapping.company_name');
});

it('refuses an import from a plain member, and offers it to an admin', function (): void {
    [, $team] = Fixtures::team();

    $member = Fixtures::member($team, TeamRole::Member);
    $admin = Fixtures::member($team, TeamRole::Admin);

    $csv = "Company\nBright Bean\n";

    // An import can add thousands of rows, so it is Owner/Admin rather than plain `work`.
    $this->actingAs($member)
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => ['company_name' => 'Company'],
        ])
        ->assertForbidden();

    $this->actingAs($member)->get(route('leads.import'))->assertForbidden();

    $this->actingAs($admin)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($csv),
            'mapping' => ['company_name' => 'Company'],
        ])
        ->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('renders the import screen with the fields it can map', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->get(route('leads.import'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leads/Import')
            ->where('fields.0', 'company_name')
            ->where('maxRows', (int) config('leads-workspace.csv.max_import_rows')));
});

// --- Export --------------------------------------------------------------------------------

it('exports the team’s leads as a CSV with the importable column set', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        'website' => 'brightbean.test',
        'locality' => 'London',
        'country' => 'GB',
    ], $icp, ScoreGrade::A, 88);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Hello Desk',
            'email' => 'hello@brightbean.test',
            'is_role_based' => true,
            'is_personal' => false,
        ]);
    });

    $response = $this->actingAs($user)->get(route('leads.export'))->assertOk();

    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = exportBody($response);

    expect($body)->toStartWith("\u{FEFF}".implode(',', LeadCsvExporter::COLUMNS))
        ->and($body)->toContain('Bright Bean Coffee')
        ->and($body)->toContain('11112222')
        ->and($body)->toContain('hello@brightbean.test')
        ->and($body)->toContain(',A,88,')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.exported')->count())
        ->toBe(1);
});

it('exports only what the list is filtered to', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Strong fit'], $icp, ScoreGrade::A, 92);
    Fixtures::lead($team, ['company_name' => 'Weak fit', 'dedupe_key' => 'no:weak'], $icp, ScoreGrade::D, 8);

    $body = exportBody(
        $this->actingAs($user)->get(route('leads.export', ['grades' => ['A']]))->assertOk()
    );

    expect($body)->toContain('Strong fit')
        ->and($body)->not->toContain('Weak fit');
});

it('round-trips: an export imports back as the same leads, not duplicates', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        'dedupe_key' => 'no:11112222',
    ]);
    Fixtures::lead($team, [
        'company_name' => 'Dubai Dates',
        'company_number' => '33334444',
        'dedupe_key' => 'no:33334444',
    ]);

    $body = exportBody($this->actingAs($user)->get(route('leads.export'))->assertOk());

    // The export's own column names map straight back, which is the whole point of matching the
    // two column sets.
    $mapping = [];

    foreach (LeadCsvExporter::COLUMNS as $column) {
        if (in_array($column, ImportLeadsAction::FIELDS, strict: true)) {
            $mapping[$column] = $column;
        }
    }

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.import.store'), [
            'file' => csvUpload($body, 'round-trip.csv'),
            'mapping' => $mapping,
        ])
        ->assertRedirect();

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.imported')->firstOrFail();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($audit->properties['created'])->toBe(0)
        ->and($audit->properties['merged'])->toBe(2);
});

it('refuses an export from a plain member', function (): void {
    [, $team] = Fixtures::team();

    $member = Fixtures::member($team, TeamRole::Member);

    // An export takes the team's whole list, contacts included, out of the platform.
    $this->actingAs($member)->get(route('leads.export'))->assertForbidden();
});

it('never exports another team’s leads', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);
    [, $foreign] = Fixtures::foreignLead();

    $body = exportBody($this->actingAs($user)->get(route('leads.export'))->assertOk());

    expect($body)->toContain('Mine Ltd')
        ->and($body)->not->toContain($foreign->company_name);
});
