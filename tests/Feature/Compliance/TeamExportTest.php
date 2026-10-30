<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The team data export (Phase 15, docs/compliance.md §3.6).
 *
 * The archive is read back and inspected rather than merely downloaded: the point of a portability
 * export is the contents, and a zip that opens to a header row and nothing else would pass any
 * assertion about status codes.
 */
it('downloads a zip of the team’s own data', function (): void {
    [$user, $team] = Fixtures::team();
    exportableData($team);

    $response = $this->actingAs($user)->post(route('compliance.export.store'));

    $response->assertOk()
        ->assertHeader('content-type', 'application/zip')
        // The browser must not keep a copy of a whole tenant's prospect database.
        ->assertHeader('cache-control', 'must-revalidate, no-cache, no-store, private');

    expect($response->headers->get('content-disposition'))->toContain('.zip');

    $files = zipEntries($response->streamedContent());

    expect(array_keys($files))->toContain(
        'README.txt',
        'leads.csv',
        'contacts.csv',
        'outreach_messages.csv',
        'do_not_contact.csv',
        'lawful_basis.csv',
        'retention_policy.csv',
    );

    expect($files['leads.csv'])->toContain('Acme Ltd')
        ->and($files['contacts.csv'])->toContain('dana@acme.test')
        // A UTF-8 BOM, or a spreadsheet reads Arabic as mojibake.
        ->and($files['leads.csv'])->toStartWith("\u{FEFF}");
});

it('exports the do-not-contact list masked, and leaves global opt-outs out of it', function (): void {
    [$user, $team] = Fixtures::team();
    exportableData($team);

    app(SuppressionList::class)->suppress(
        SuppressionType::Email,
        'theirs@acme.test',
        SuppressionScope::Team,
        SuppressionReason::Manual,
        (int) $team->getKey(),
    );

    app(SuppressionList::class)->suppress(
        SuppressionType::Email,
        'optedout@elsewhere.test',
        SuppressionScope::Global,
        SuppressionReason::OptOut,
    );

    $files = zipEntries($this->actingAs($user)->post(route('compliance.export.store'))->streamedContent());

    // The team's own entry, masked — the table holds a hash and the export cannot show more (§3.9).
    expect($files['do_not_contact.csv'])->toContain('t***@acme.test')
        ->and($files['do_not_contact.csv'])->not->toContain('theirs@acme.test')
        // A platform-wide opt-out belongs to the person who made it, not to one tenant's export.
        ->and($files['do_not_contact.csv'])->not->toContain('optedout');
});

it('neutralises a company name a spreadsheet would run as a formula', function (): void {
    [$user, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_name' => '=HYPERLINK("http://evil.test","Click")',
    ]));

    $files = zipEntries($this->actingAs($user)->post(route('compliance.export.store'))->streamedContent());

    // The cell came from a web search, not from us, and an Owner will open this in Excel.
    expect($files['leads.csv'])->toContain("'=HYPERLINK");
});

it('never includes another team’s rows', function (): void {
    [$user, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    exportableData($teamA);

    app(TeamContext::class)->run($teamB, fn () => Lead::factory()->create([
        'team_id' => $teamB->getKey(),
        'company_name' => 'Other Team Holdings',
    ]));

    $files = zipEntries($this->actingAs($user)->post(route('compliance.export.store'))->streamedContent());

    expect($files['leads.csv'])->toContain('Acme Ltd')
        ->and($files['leads.csv'])->not->toContain('Other Team Holdings');
});

it('audits the export before the file is sent', function (): void {
    [$user, $team] = Fixtures::team();
    exportableData($team);

    $this->actingAs($user)->post(route('compliance.export.store'))->assertOk();

    $entry = AuditLog::query()->where('action', 'compliance.team_data_exported')->sole();

    expect($entry->user_id)->toBe($user->getKey())
        ->and($entry->team_id)->toBe((int) $team->getKey());
});

it('forbids a plain member from exporting the team’s data', function (): void {
    [, $team] = Fixtures::team();
    $member = Fixtures::member($team, TeamRole::Member);

    // Exporting hands somebody a copy of every prospect the team holds — not daily work.
    $this->actingAs($member)->post(route('compliance.export.store'))->assertForbidden();

    expect(AuditLog::query()->where('action', 'compliance.team_data_exported')->count())->toBe(0);
});

it('meters the export per member', function (): void {
    [$user, $team] = Fixtures::team();
    exportableData($team);

    config()->set('compliance.export.rate_limit', 1);

    $this->actingAs($user)->post(route('compliance.export.store'))->assertOk();
    $this->actingAs($user)->post(route('compliance.export.store'))->assertStatus(429);
});

/**
 * A lead, a contact and an assessment — enough that every file has something in it.
 */
function exportableData($team): void
{
    Fixtures::activeLia($team);
    Fixtures::retention($team, uncontactedDays: 180, contactedDays: 540);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Ltd']);

        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Dana Buyer',
            'email' => 'dana@acme.test',
        ]);
    });
}

/**
 * Read a downloaded archive back into `name => contents`.
 *
 * `ZipArchive` only opens a path, so the bytes are written to a temporary file first — the same
 * round trip a member's browser makes.
 *
 * @return array<string, string>
 */
function zipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'alima-test-zip-');

    expect($path)->not->toBeFalse();

    file_put_contents((string) $path, $bytes);

    $zip = new ZipArchive;

    expect($zip->open((string) $path))->toBeTrue();

    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink((string) $path);

    return $files;
}
