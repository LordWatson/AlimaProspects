<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadsWorkspace\Enums\ExportState;
use App\Modules\LeadsWorkspace\Jobs\GenerateLeadExportJob;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\Support\BillingFixtures;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * PL6's export: presets, the revealed-only rule, the suppression exclusion, the metering, the file
 * and the prune.
 *
 * Phase 10b's streamed export is tested next door in `LeadCsvTest` and is deliberately left alone:
 * it is what every team still gets while `prelaunch.export` is off, and the round trip into the
 * importer is asserted there.
 *
 * Everything here reads the file off a faked disk rather than the download response, because the
 * question is nearly always what was written rather than how it was served.
 */
function exportFor(Team $team): LeadExport
{
    return LeadExport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

function exportedFile(LeadExport $export): string
{
    return (string) Storage::disk('local')->get((string) $export->path);
}

function suppress(SuppressionType $type, string $value, Team $team): void
{
    app(SuppressionList::class)->suppress(
        $type,
        $value,
        SuppressionScope::Team,
        SuppressionReason::Manual,
        (int) $team->getKey(),
    );
}

beforeEach(function (): void {
    Storage::fake('local');

    // Generous enough that the metering is never what a behaviour test trips over; the two tests
    // that are about the allowance set their own figure.
    BillingFixtures::capAllowance(BillingMetric::ExportRows, 10_000);
});

// --- The flag ------------------------------------------------------------------------------

it('does not offer the new export at all while the flag is off', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    // 404 rather than 403: the flag asks whether the code is finished, not whether this member
    // may export — and they may, which is what the Phase 10b route still answers.
    $this->actingAs($user)->post(route('leads.export.store'))->assertNotFound();
    $this->actingAs($user)->get(route('leads.export'))->assertOk();

    expect(LeadExport::query()->withoutGlobalScopes()->count())->toBe(0);
});

// --- The file ------------------------------------------------------------------------------

it('writes a small export in the request and hands the file straight over', function (): void {
    [$user, $team, $icp] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    $lead = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'company_number' => '11112222',
        'website' => 'brightbean.test',
    ], $icp, ScoreGrade::A, 88);

    app(TeamContext::class)->run($team, fn (): LeadContact => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'name' => 'Hello Desk',
        'email' => 'hello@brightbean.test',
        // No `email_source`: read off the company's own page, so nothing to reveal (ADR 0029).
        'email_source' => null,
        'is_role_based' => true,
    ]));

    $export = null;

    $this->actingAs($user)
        ->post(route('leads.export.store'), ['preset' => 'leads_only'])
        ->assertRedirect(route('leads.exports.download', ['export' => exportFor($team)->getKey()]));

    $export = exportFor($team);

    expect($export->state)->toBe(ExportState::Ready)
        ->and($export->row_count)->toBe(1)
        ->and($export->suppressed_count)->toBe(0)
        ->and($export->expires_at)->not->toBeNull()
        // Audited as one act with its shape, and not a copy of the data.
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.exported')->count())->toBe(1);

    $body = exportedFile($export);

    // The BOM is what makes Excel read it as UTF-8 — the difference between an Arabic name and a
    // line of question marks.
    expect($body)->toStartWith("\u{FEFF}".implode(',', LeadCsvExporter::COLUMNS))
        ->and($body)->toContain('Bright Bean Coffee')
        ->and($body)->toContain('hello@brightbean.test');

    $this->actingAs($user)
        ->get(route('leads.exports.download', ['export' => $export->getKey()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('writes one row per contact for the CRM preset, and one for a company with none', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    $lead = Fixtures::lead($team, ['company_name' => 'Two People Ltd', 'website' => 'two.test']);
    Fixtures::lead($team, ['company_name' => 'Nobody Ltd', 'dedupe_key' => 'no:nobody']);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        foreach (['dana@two.test', 'sam@two.test'] as $email) {
            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'email' => $email,
                'email_source' => null,
            ]);
        }
    });

    $this->actingAs($user)->post(route('leads.export.store'), ['preset' => 'crm_ready'])->assertRedirect();

    $export = exportFor($team);

    // Three rows: two people, plus the company nobody has been found at yet. A company worth
    // exporting is worth exporting before anybody has found a person there.
    expect($export->row_count)->toBe(3);

    $body = exportedFile($export);

    expect($body)->toContain('dana@two.test')
        ->and($body)->toContain('sam@two.test')
        ->and($body)->toContain('Nobody Ltd')
        // The CRM preset leads with the person, because that is what a CRM importer asks first.
        ->and($body)->toStartWith("\u{FEFF}contact_name,");
});

it('writes exactly the columns a member ticked, in the catalogue order', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'country' => 'GB']);

    $this->actingAs($user)->post(route('leads.export.store'), [
        'preset' => 'custom',
        // Asked for out of order on purpose: a file whose columns move between two exports of the
        // same set is a file somebody's spreadsheet breaks on.
        'columns' => ['country', 'company_name'],
    ])->assertRedirect();

    expect(exportedFile(exportFor($team)))->toStartWith("\u{FEFF}company_name,country\n");
});

it('neutralises a cell a spreadsheet would run', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    // A company name can come from a web-search hit or from a CSV the team was sent.
    Fixtures::lead($team, ['company_name' => '=HYPERLINK("http://evil.test","Click")']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $body = exportedFile(exportFor($team));

    expect($body)->toContain("'=HYPERLINK")
        ->and($body)->not->toContain(',=HYPERLINK');
});

// --- What must not reach the file ----------------------------------------------------------

it('leaves out a value nobody has paid to see, rather than masking it', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    $lead = Fixtures::lead($team, ['company_name' => 'Vendor Finds Ltd', 'website' => 'vendor.test']);

    app(TeamContext::class)->run($team, fn (): LeadContact => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'email' => 'hidden@vendor.test',
        // A paid vendor found it and nobody has revealed it.
        'email_source' => 'zerobounce',
        'revealed_email_at' => null,
    ]));

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $body = exportedFile(exportFor($team));

    // Neither the address nor the masked form of it: `h***@vendor.test` in a CRM is worse than
    // nothing, because something will eventually try to send to it.
    expect($body)->toContain('Vendor Finds Ltd')
        ->and($body)->not->toContain('hidden@vendor.test')
        ->and($body)->not->toContain('***');
});

it('leaves a suppressed company out of the file and counts it', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Fine Ltd', 'website' => 'fine.test']);
    Fixtures::lead($team, [
        'company_name' => 'Opted Out Ltd',
        'website' => 'https://optedout.test/contact',
        'dedupe_key' => 'no:opted',
    ]);

    suppress(SuppressionType::Domain, 'optedout.test', $team);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);

    // Counted rather than silently dropped: a member reconciling two rows against three leads
    // otherwise goes looking for a bug in the filter.
    expect($export->row_count)->toBe(1)
        ->and($export->suppressed_count)->toBe(1);

    expect(exportedFile($export))->toContain('Fine Ltd')
        ->and(exportedFile($export))->not->toContain('Opted Out Ltd');
});

it('blanks a suppressed address at a company that is otherwise fine', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    $lead = Fixtures::lead($team, ['company_name' => 'Fine Ltd', 'website' => 'fine.test']);

    app(TeamContext::class)->run($team, fn (): LeadContact => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'email' => 'dana@fine.test',
        'email_source' => null,
    ]));

    suppress(SuppressionType::Email, 'dana@fine.test', $team);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);

    // The company is not the address: the row stays, the cell goes.
    expect($export->row_count)->toBe(1)
        ->and($export->suppressed_count)->toBe(0)
        ->and(exportedFile($export))->toContain('Fine Ltd')
        ->and(exportedFile($export))->not->toContain('dana@fine.test');
});

// --- The allowance -------------------------------------------------------------------------

it('settles the rows it wrote against the team’s allowance', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    BillingFixtures::capAllowance(BillingMetric::ExportRows, 50);

    Fixtures::lead($team, ['company_name' => 'One Ltd']);
    Fixtures::lead($team, ['company_name' => 'Two Ltd', 'dedupe_key' => 'no:two']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $allowance = app(UsageAllowance::class);

        // Two held, two written, nothing left over.
        expect($allowance->used($team, BillingMetric::ExportRows))->toBe(2)
            ->and($allowance->remaining($team, BillingMetric::ExportRows))->toBe(48);
    });
});

it('gives back the rows a suppressed company never used', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    BillingFixtures::capAllowance(BillingMetric::ExportRows, 50);

    Fixtures::lead($team, ['company_name' => 'Fine Ltd', 'website' => 'fine.test']);
    Fixtures::lead($team, [
        'company_name' => 'Opted Out Ltd',
        'website' => 'optedout.test',
        'dedupe_key' => 'no:opted',
    ]);

    suppress(SuppressionType::Domain, 'optedout.test', $team);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    app(TeamContext::class)->run($team, function () use ($team): void {
        // The hold was for both; only the row that was written is charged for.
        expect(app(UsageAllowance::class)->used($team, BillingMetric::ExportRows))->toBe(1);
    });
});

it('refuses an export bigger than the month’s allowance, before writing anything', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    BillingFixtures::capAllowance(BillingMetric::ExportRows, 1);

    Fixtures::lead($team, ['company_name' => 'One Ltd']);
    Fixtures::lead($team, ['company_name' => 'Two Ltd', 'dedupe_key' => 'no:two']);

    // A domain exception on a web request is a toast and a trip back, not a 402 page.
    $this->actingAs($user)->from(route('leads.index'))
        ->post(route('leads.export.store'))
        ->assertRedirect(route('leads.index'));

    // No row, no file, nothing held: the refusal happens before any of it exists.
    expect(LeadExport::query()->withoutGlobalScopes()->count())->toBe(0);

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(UsageAllowance::class)->remaining($team, BillingMetric::ExportRows))->toBe(1);
    });
});

// --- The queue -----------------------------------------------------------------------------

it('queues an export too big to wait for', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    config()->set('leads-workspace.export.sync_max_rows', 1);

    Fixtures::lead($team, ['company_name' => 'One Ltd']);
    Fixtures::lead($team, ['company_name' => 'Two Ltd', 'dedupe_key' => 'no:two']);

    Queue::fake();

    $this->actingAs($user)->from(route('leads.index'))
        ->post(route('leads.export.store'))
        ->assertRedirect(route('leads.index'));

    Queue::assertPushed(GenerateLeadExportJob::class);

    expect(exportFor($team)->state)->toBe(ExportState::Pending);
});

it('tells the member when a queued export lands, with counts and no prose', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    config()->set('leads-workspace.export.sync_max_rows', 1);

    Fixtures::lead($team, ['company_name' => 'One Ltd']);
    Fixtures::lead($team, ['company_name' => 'Two Ltd', 'dedupe_key' => 'no:two']);

    // The queue is synchronous in the suite, so the job runs as it is dispatched.
    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);

    expect($export->state)->toBe(ExportState::Ready)
        ->and($export->row_count)->toBe(2);

    $notification = Notification::query()
        ->withoutGlobalScopes()
        ->where('notifiable_id', $user->getKey())
        ->firstOrFail();

    expect($notification->data['type'])->toBe(NotificationType::ExportReady->value)
        ->and($notification->data['params']['count'])->toBe(2)
        ->and($notification->data['url'])->toBe('/leads/exports/'.$export->getKey().'/download');
});

// --- Who may have the file -----------------------------------------------------------------

it('keeps one team out of another team’s file', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);

    // A second team, whose owner holds the id — the path on disk is never a parameter, so the id
    // is the only thing they could guess.
    [$stranger, $otherTeam] = Fixtures::team();
    Feature::for($otherTeam)->activate(PrelaunchFeature::Export->value);

    $this->actingAs($stranger)
        ->get(route('leads.exports.download', ['export' => $export->getKey()]))
        ->assertNotFound();
});

it('refuses the download to a member who may not export', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $member = Fixtures::member($team, TeamRole::Member);

    // The file is the team's whole list; a Read-only member sent the link is not entitled to it.
    $this->actingAs($member)
        ->get(route('leads.exports.download', ['export' => exportFor($team)->getKey()]))
        ->assertForbidden();
});

// --- The file goes away --------------------------------------------------------------------

it('says the export has expired once it is out of date, and prunes it', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);
    $path = (string) $export->path;

    $export->forceFill(['expires_at' => now()->subDay()])->save();

    // 410 rather than 404: the row is theirs, it is the file that has gone.
    $this->actingAs($user)->from(route('leads.index'))
        ->get(route('leads.exports.download', ['export' => $export->getKey()]))
        ->assertRedirect(route('leads.index'));

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->artisan('leads:prune-exports')->assertSuccessful();

    // The file first, the row second: a crash between them leaves a row pointing at nothing,
    // which the download answers honestly, rather than a file nothing knows about.
    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(LeadExport::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('reports what it would delete without deleting it', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    exportFor($team)->forceFill(['expires_at' => now()->subDay()])->save();

    $this->artisan('leads:prune-exports', ['--dry-run' => true])->assertSuccessful();

    expect(LeadExport::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('gives the allowance back when the file cannot be written', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);
    BillingFixtures::capAllowance(BillingMetric::ExportRows, 50);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    // An honest failure rather than a mocked one: the exporter reads the team's default stage, so
    // without that table the walk throws where a full disk or a corrupt row would.
    Schema::drop('pipeline_stages');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->post(route('leads.export.store')))
        ->toThrow(QueryException::class);

    // The request never reaches the job's `failed()`, so without the release the hold would sit
    // against the team's allowance until the month turned over — and the retry would hold again.
    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(UsageAllowance::class)->remaining($team, BillingMetric::ExportRows))->toBe(50);
    });

    expect(exportFor($team)->state)->toBe(ExportState::Failed);
});

it('leaves a finished export alone when a dead worker reports a failure', function (): void {
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Export->value);

    Fixtures::lead($team, ['company_name' => 'Mine Ltd']);

    $this->actingAs($user)->post(route('leads.export.store'))->assertRedirect();

    $export = exportFor($team);

    // A worker killed after the file was written and the allowance settled still reports a
    // failure. Flipping the row would take away a file the member can actually download.
    (new GenerateLeadExportJob((int) $export->getKey()))->failed(new RuntimeException('killed'));

    expect($export->refresh()->state)->toBe(ExportState::Ready)
        ->and($export->isDownloadable())->toBeTrue();
});
