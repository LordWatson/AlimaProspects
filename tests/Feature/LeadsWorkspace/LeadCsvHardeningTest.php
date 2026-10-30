<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * What a CSV does when it isn't the tidy file the happy path assumes: a cell a spreadsheet would
 * run as a formula, a value longer than its column, a `javascript:` URL, an Arabic comma, a
 * re-import. The round-trip and mapping rules live in `LeadCsvTest`; this is the hostile input.
 */

/**
 * A CSV upload built from raw text, so a test can be as awkward as a real spreadsheet.
 */
function hostileCsv(string $contents, string $name = 'leads.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alima-csv');

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/**
 * Read a streamed export response into a string.
 */
function hostileExportBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

it('attributes an imported lead to the import, not to a provider', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv("Company\nBright Bean Coffee\n"),
            'mapping' => ['company_name' => 'Company'],
        ])
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

    // `LeadSource::Import` outranks every provider, so a later discovery of the same company fills
    // the gaps rather than renaming what the team typed (see DiscoverLeadsJob::mergeInto).
    expect($lead->source)->toBe(LeadSource::Import)
        ->and(LeadSource::Import->rank())->toBeGreaterThan(LeadSource::CompaniesHouse->rank())
        ->and(LeadSource::Import->isAvailable())->toBeFalse();
});

it('neutralises a cell a spreadsheet would run as a formula', function (): void {
    [$user, $team] = Fixtures::team();

    // A company name can come from a web-search hit or from a CSV the team was sent, and the export
    // is then opened in Excel by an Owner or Admin.
    Fixtures::lead($team, ['company_name' => '=HYPERLINK("http://evil.test","Click")']);

    $body = hostileExportBody($this->actingAs($user)->get(route('leads.export'))->assertOk());

    expect($body)->toContain("'=HYPERLINK")
        ->and($body)->not->toContain(',=HYPERLINK');
});

it('imports a neutralised cell back as the value it started as', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => '=Acme', 'dedupe_key' => 'name:acme-formula']);

    $body = hostileExportBody($this->actingAs($user)->get(route('leads.export'))->assertOk());

    Lead::query()->withoutGlobalScopes()->delete();

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($body, 'round-trip.csv'),
            'mapping' => ['company_name' => 'company_name'],
        ])
        ->assertRedirect();

    // The apostrophe is the exporter's, not the team's, so it must not become part of the name.
    expect(Lead::query()->withoutGlobalScopes()->firstOrFail()->company_name)->toBe('=Acme');
});

it('clamps a cell longer than its column instead of failing the upload', function (): void {
    [$user] = Fixtures::team();

    // `employee_range` is a 64-character column; a spreadsheet cell has no such limit, and an
    // over-long one used to be a "data too long" 500 half way through the file.
    $long = str_repeat('x', 400);
    $csv = "Company,Size\nBright Bean,{$long}\nDubai Dates,11-50\n";

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'employee_range' => 'Size'],
        ])
        ->assertRedirect();

    $leads = Lead::query()->withoutGlobalScopes()->orderBy('id')->get();

    expect($leads)->toHaveCount(2)
        ->and(mb_strlen((string) $leads->first()?->employee_range))->toBeLessThanOrEqual(64)
        // …and the audit row for the whole upload survives.
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'leads.imported')->count())->toBe(1);
});

it('keeps the dedupe key inside its column even from an absurd cell', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Number\nBright Bean,".str_repeat('9', 500)."\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'company_number' => 'Number'],
        ])
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

    expect(mb_strlen($lead->dedupe_key))->toBeLessThanOrEqual(191);
});

it('refuses a website a browser would run as script', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Site\nBright Bean,javascript:alert(1)\nDubai Dates,dubaidates.test\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'website' => 'Site'],
        ])
        ->assertRedirect();

    $leads = Lead::query()->withoutGlobalScopes()->orderBy('id')->get();

    // The detail screen renders `website` as an `<a href>`.
    expect($leads->first()?->website)->toBeNull()
        ->and($leads->last()?->website)->toBe('https://dubaidates.test');
});

it('splits tags on an Arabic comma as well as a Latin one', function (): void {
    [$user] = Fixtures::team();

    // An Arabic-language spreadsheet uses "،" (ADR 0007: the UAE is an MVP market).
    $csv = "Company,Labels\nBright Bean,\"ساخن، معرض\"\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'tags' => 'Labels'],
        ])
        ->assertRedirect();

    expect(LeadTag::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('does not stack up a fresh copy of a contact with no email on a re-import', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Number,Contact,Phone\nBright Bean,11112222,Reception,020 1234 5678\n";

    foreach ([1, 2] as $ignored) {
        $this->actingAs($user)
            ->from(route('leads.import'))
            ->post(route('leads.import.store'), [
                'file' => hostileCsv($csv),
                'mapping' => [
                    'company_name' => 'Company',
                    'company_number' => 'Number',
                    'contact_name' => 'Contact',
                    'contact_phone' => 'Phone',
                ],
            ])
            ->assertRedirect();
    }

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(LeadContact::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('drops a contact email that is not one', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Email\nBright Bean,not-an-address\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'contact_email' => 'Email'],
        ])
        ->assertRedirect();

    // Nothing else on the row, so there is no contact worth creating at all.
    expect(LeadContact::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('reads a file whose only row is the header without creating anything', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv("Company,Town\n"),
            'mapping' => ['company_name' => 'Company'],
        ])
        ->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps a quoted newline inside one cell', function (): void {
    [$user] = Fixtures::team();

    $csv = "Company,Town\n\"Bright Bean\nCoffee\",London\n";

    $this->actingAs($user)
        ->from(route('leads.import'))
        ->post(route('leads.import.store'), [
            'file' => hostileCsv($csv),
            'mapping' => ['company_name' => 'Company', 'locality' => 'Town'],
        ])
        ->assertRedirect();

    $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

    // One lead, not two — and the newline is squished out of the name rather than stored.
    expect(Lead::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($lead->company_name)->toBe('Bright Bean Coffee')
        ->and($lead->locality)->toBe('London');
});
