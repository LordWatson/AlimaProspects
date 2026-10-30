<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** Read a streamed export response into a string. */
function reportBody(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

/**
 * The export's rows, parsed back out of the CSV with the UTF-8 BOM stripped.
 *
 * @return list<list<string>>
 */
function reportRows(TestResponse $response): array
{
    $body = Str::after(reportBody($response), "\u{FEFF}");

    $rows = [];

    foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
        if ($line !== '') {
            $rows[] = str_getcsv($line);
        }
    }

    return $rows;
}

function exportLead(Team $team, int $daysOld = 0): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $daysOld): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'dedupe_key' => 'export:'.Str::random(12),
        ]);

        $lead->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        return $lead->refresh();
    });
}

it('requires a signed-in member', function (): void {
    $this->get(route('reports.export', ['report' => 'funnel']))
        ->assertRedirect(route('login'));
});

it('requires a known report', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('reports.export'))
        ->assertSessionHasErrors('report');

    $this->actingAs($user)
        ->get(route('reports.export', ['report' => 'everything']))
        ->assertSessionHasErrors('report');
});

it('exports every report, each as a CSV with a header row', function (): void {
    // The point of the enum: "any report" is a closed set, and a chart that cannot be exported is
    // one a member cannot take to a meeting.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    exportLead($team);

    foreach (ReportKey::cases() as $key) {
        $response = $this->actingAs($user)
            ->get(route('reports.export', ['report' => $key->value]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Content-Disposition'))
            ->toContain('alima-'.$key->slug().'-all-time.csv');

        expect(reportRows($response)[0] ?? [])->not->toBeEmpty();
    }
});

it('writes a spreadsheet\'s numbers, not a screen\'s', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = exportLead($team);
    $other = exportLead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead, $other): void {
        ContactAttempt::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'channel' => OutreachChannel::Email,
            'outcome' => ContactOutcome::Replied,
        ]);

        ContactAttempt::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $other->getKey(),
            'channel' => OutreachChannel::Email,
            'outcome' => ContactOutcome::Pending,
        ]);
    });

    $rows = reportRows(
        $this->actingAs($user)->get(route('reports.export', ['report' => 'outreach']))->assertOk(),
    );

    // The unit lives in the header so the cells stay summable: a column headed "Reply rate" whose
    // cells read "50%" cannot be averaged in the one application this file is for.
    expect($rows[0])->toBe([
        'Channel', 'Approaches', 'Companies', 'Replies', 'Reply rate (%)',
        'Interested or meeting', 'Still waiting', 'Asked us to stop',
    ])
        ->and($rows[1])->toBe(['Email', '2', '2', '1', '50.0', '0', '1', '0']);
});

it('leaves a cell empty rather than writing a zero we do not mean', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $icp = app(TeamContext::class)->run(
        $team,
        fn (): Icp => Icp::factory()->active()->create(['team_id' => $team->getKey()]),
    );

    $lead = exportLead($team);

    app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'icp_id' => $icp->getKey(),
        'grade' => ScoreGrade::D,
        'excluded' => true,
    ]));

    $rows = reportRows(
        $this->actingAs($user)
            ->get(route('reports.export', ['report' => 'icp_performance']))
            ->assertOk(),
    );

    // Columns: ideal customer, status, graded, strong fits, strong-fit rate, ruled out, average.
    // Nothing was gradeable, so the rate is blank — "we judged nothing" is not "nothing fitted".
    expect($rows[1][4])->toBe('')
        ->and($rows[1][2])->toBe('1')
        ->and($rows[1][5])->toBe('1');
});

it('writes money as major units with the currency in the header', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn (): AiRequest => AiRequest::factory()->create([
        'team_id' => $team->getKey(),
        'prompt_key' => 'scoring.qualitative_fit',
        'cost_micros' => 1_234_000,
    ]));

    $rows = reportRows(
        $this->actingAs($user)->get(route('reports.export', ['report' => 'ai_usage']))->assertOk(),
    );

    expect($rows[0][6])->toBe('Cost (GBP)')
        // 1,234,000 micros is 123 minor units, so £1.23.
        ->and($rows[1][6])->toBe('1.23');
});

it('writes a negative number as a number, not as text', function (): void {
    // Formula-neutralisation prefixes an apostrophe to anything starting `=`, `+`, `-` or `@`.
    // Applied to a numeric cell that is the bug it is meant to prevent in reverse: `'-3` lands in
    // the spreadsheet as text, and the column the member wanted to sum cannot be summed.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // Three leads in the 30 days before the window, one inside it: a delta of −2.
    exportLead($team, daysOld: 2);
    exportLead($team, daysOld: 35);
    exportLead($team, daysOld: 40);
    exportLead($team, daysOld: 45);

    $rows = reportRows(
        $this->actingAs($user)
            ->get(route('reports.export', ['report' => 'kpis', 'range' => 'last_30_days']))
            ->assertOk(),
    );

    // Columns: figure, value, change, compared with, amount.
    expect($rows[1][0])->toBe('Prospects sourced')
        ->and($rows[1][2])->toBe('-2');
});

it('neutralises a cell a spreadsheet would run as a formula', function (): void {
    // An ICP's name is typed by a member; a lead's by a web-search result. Either can reach a
    // spreadsheet an Owner then opens.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $icp = app(TeamContext::class)->run($team, fn (): Icp => Icp::factory()->active()->create([
        'team_id' => $team->getKey(),
        'name' => '=HYPERLINK("http://evil.test","Click")',
    ]));

    $lead = exportLead($team);

    app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'icp_id' => $icp->getKey(),
        'grade' => ScoreGrade::A,
        'excluded' => false,
    ]));

    $body = reportBody(
        $this->actingAs($user)
            ->get(route('reports.export', ['report' => 'icp_performance']))
            ->assertOk(),
    );

    expect($body)->toContain("'=HYPERLINK");
});

it('covers the same window as the screen, and names it in the filename', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    exportLead($team, daysOld: 2);
    exportLead($team, daysOld: 40);

    $response = $this->actingAs($user)
        ->get(route('reports.export', ['report' => 'funnel', 'range' => 'last_30_days']))
        ->assertOk();

    $rows = reportRows($response);

    expect($rows[1][1])->toBe('1')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('alima-funnel-'.now()->subDays(30)->toDateString());
});

it('audits the export without copying the numbers into the trail', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    exportLead($team);

    $response = $this->actingAs($user)
        ->get(route('reports.export', ['report' => 'team_activity', 'range' => 'last_7_days']))
        ->assertOk();

    reportBody($response);

    $log = AuditLog::query()->where('action', 'reports.exported')->sole();

    expect($log->properties['report'] ?? null)->toBe('team_activity')
        ->and($log->properties['range'] ?? null)->toBe('last_7_days')
        ->and($log->properties['rows'] ?? null)->toBe(1)
        ->and((int) $log->user_id)->toBe((int) $user->getKey())
        // The file's contents are the team's own data; the trail has no business holding a copy.
        ->and(array_keys($log->properties ?? []))
        ->toBe(['report', 'range', 'from', 'to', 'rows']);
});

it('lets a read-only member download what they can see', function (): void {
    // An export is the report in a different window. Gating the file behind a stricter role than
    // the chart would mean the person asked to read the numbers could not take them anywhere.
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $reader = createUserWithTeam();
    $team->users()->attach($reader->getKey(), ['role' => TeamRole::ReadOnly->value]);
    $reader->switchTeam($team);

    $this->actingAs($reader->refresh())
        ->get(route('reports.export', ['report' => 'funnel']))
        ->assertOk();
});

it('never exports another team\'s numbers', function (): void {
    $user = createUserWithTeam();
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    exportLead($otherTeam);
    exportLead($otherTeam);

    $rows = reportRows(
        $this->actingAs($user)->get(route('reports.export', ['report' => 'funnel']))->assertOk(),
    );

    expect($rows[1][1])->toBe('0');

    $rows = reportRows(
        $this->actingAs($other)->get(route('reports.export', ['report' => 'funnel']))->assertOk(),
    );

    expect($rows[1][1])->toBe('2');
});

it('writes the report in the member\'s language', function (): void {
    // Headers always, and cells only where they hold Alima's own words: a member's name or a
    // prompt key is data and must come out exactly as stored.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = exportLead($team);

    app(TeamContext::class)->run($team, fn (): ContactAttempt => ContactAttempt::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'channel' => OutreachChannel::Email,
    ]));

    $rows = reportRows(
        $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'ar')
            ->get(route('reports.export', ['report' => 'outreach']))
            ->assertOk(),
    );

    // The `locale` cookie is excepted from encryption, so it is sent unencrypted.
    expect($rows[0][0])->toBe(__('Channel', locale: 'ar'))
        ->and($rows[0][0])->not->toBe('Channel')
        ->and($rows[1][0])->toBe(__('Email', locale: 'ar'));
});
