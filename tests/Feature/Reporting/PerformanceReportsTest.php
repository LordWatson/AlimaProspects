<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Reporting\Actions\BuildAiUsageAction;
use App\Modules\Reporting\Actions\BuildIcpPerformanceAction;
use App\Modules\Reporting\Actions\BuildOutreachPerformanceAction;
use App\Modules\Reporting\Actions\BuildTeamActivityAction;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The cells of one row of a report table, keyed by column key.
 *
 * @return array<string, string|int|float|null>
 */
function cellsOf(ReportTable $table, string $rowKey): array
{
    foreach ($table->rows as $row) {
        if ($row->key === $rowKey) {
            return $row->cells;
        }
    }

    throw new RuntimeException("No row [{$rowKey}] in the {$table->key->value} report.");
}

function reportLead(Team $team, int $daysOld = 0): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $daysOld): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'dedupe_key' => 'report:'.Str::random(12),
        ]);

        $lead->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        return $lead->refresh();
    });
}

it('rates each ICP on what it graded, excluding its own disqualifications', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$active, $retired] = app(TeamContext::class)->run($team, fn (): array => [
        Icp::factory()->active()->create(['team_id' => $team->getKey(), 'name' => 'UK agencies']),
        Icp::factory()->create(['team_id' => $team->getKey(), 'name' => 'Old profile']),
    ]);

    $score = function (Icp $icp, ScoreGrade $grade, int $value, bool $excluded = false) use ($team): void {
        $lead = reportLead($team);

        app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'grade' => $grade,
            'score' => $value,
            'excluded' => $excluded,
        ]));
    };

    $score($active, ScoreGrade::A, 90);
    $score($active, ScoreGrade::B, 70);
    $score($active, ScoreGrade::C, 50);
    // A matched ICP exclusion: ruled out, not graded poorly — so it must not sit in the rate's
    // denominator or the profile looks worse than it was asked to be.
    $score($active, ScoreGrade::D, 10, excluded: true);
    $score($retired, ScoreGrade::D, 20);

    $table = app(BuildIcpPerformanceAction::class)->handle($team, DateRange::allTime('UTC'));

    $cells = cellsOf($table, 'icp-'.$active->getKey());

    expect($cells['icp'])->toBe('UK agencies')
        ->and($cells['status'])->toBe('Active')
        ->and($cells['scored'])->toBe(4)
        ->and($cells['strong'])->toBe(2)
        ->and($cells['excluded'])->toBe(1)
        // Two strong out of the three it actually judged.
        ->and($cells['strong_rate'])->toBe(0.6667)
        ->and($cells['average'])->toBe(55);

    // The retired profile is kept and marked: "the last one was better" is the most useful thing
    // this table can say, and dropping the row would hide it.
    expect(cellsOf($table, 'icp-'.$retired->getKey())['status'])->toBe('Retired')
        ->and($table->rows)->toHaveCount(2);
});

it('reports a strong-fit rate of null, not zero, when nothing was gradeable', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $icp = app(TeamContext::class)->run(
        $team,
        fn (): Icp => Icp::factory()->active()->create(['team_id' => $team->getKey()]),
    );

    $lead = reportLead($team);

    app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'icp_id' => $icp->getKey(),
        'grade' => ScoreGrade::D,
        'excluded' => true,
    ]));

    $table = app(BuildIcpPerformanceAction::class)->handle($team, DateRange::allTime('UTC'));

    // "Nothing was judged" is not "nothing was a fit".
    expect(cellsOf($table, 'icp-'.$icp->getKey())['strong_rate'])->toBeNull();
});

it('breaks outreach down by channel over when the approach happened', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = reportLead($team);
    $other = reportLead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead, $other): void {
        $attempt = function (OutreachChannel $channel, ContactOutcome $outcome, int $daysAgo, Lead $on) use ($team): void {
            ContactAttempt::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $on->getKey(),
                'channel' => $channel,
                'outcome' => $outcome,
                'occurred_at' => now()->subDays($daysAgo),
            ]);
        };

        $attempt(OutreachChannel::Email, ContactOutcome::Replied, 1, $lead);
        $attempt(OutreachChannel::Email, ContactOutcome::MeetingBooked, 2, $lead);
        $attempt(OutreachChannel::Email, ContactOutcome::Pending, 3, $other);
        $attempt(OutreachChannel::Call, ContactOutcome::NoAnswer, 2, $lead);
        // Logged today but made three weeks ago, which is outside a seven-day window: the report
        // is about when the approach happened, not when somebody got round to recording it.
        $attempt(OutreachChannel::LinkedIn, ContactOutcome::Replied, 21, $lead);
    });

    $table = app(BuildOutreachPerformanceAction::class)
        ->handle($team, ReportRange::Last7Days->resolve('UTC'));

    $email = cellsOf($table, 'email');

    expect($email['attempts'])->toBe(3)
        // Three approaches across two companies — the distinction the funnel depends on.
        ->and($email['companies'])->toBe(2)
        ->and($email['responses'])->toBe(2)
        ->and($email['reply_rate'])->toBe(0.6667)
        ->and($email['positives'])->toBe(1)
        ->and($email['waiting'])->toBe(1)
        ->and($email['opt_outs'])->toBe(0);

    // Channels are driven off the enum, so the order is stable; LinkedIn is outside the window and
    // a channel with nothing in it is dropped rather than shown as a row of zeroes.
    expect(array_map(static fn ($row): string => $row->key, $table->rows))->toBe(['email', 'call']);
});

it('gives every member a row and reads activity off the timeline', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create(['name' => 'Aisha Haddad']);
    $team->users()->attach($member->getKey(), ['role' => TeamRole::Member->value]);

    $idle = User::factory()->create(['name' => 'Zoe Idle']);
    $team->users()->attach($idle->getKey(), ['role' => TeamRole::ReadOnly->value]);

    $lead = reportLead($team);
    $second = reportLead($team);

    app(TeamContext::class)->run($team, function () use ($team, $lead, $second, $member): void {
        $log = function (LeadActivityType $type, Lead $on, ?int $userId, int $daysAgo = 1) use ($team): void {
            LeadActivity::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $on->getKey(),
                'user_id' => $userId,
                'type' => $type,
                'occurred_at' => now()->subDays($daysAgo),
            ]);
        };

        $log(LeadActivityType::Contacted, $lead, (int) $member->getKey());
        $log(LeadActivityType::NoteAdded, $lead, (int) $member->getKey(), 2);
        $log(LeadActivityType::StageChanged, $second, (int) $member->getKey(), 3);
        // Alima's own work. Attributing a queued score to nobody in a table called "team activity"
        // would read as a gap in the data rather than as the platform doing its job.
        $log(LeadActivityType::Scored, $lead, null);
    });

    $table = app(BuildTeamActivityAction::class)->handle($team, DateRange::allTime('UTC'));

    $cells = cellsOf($table, 'member-'.$member->getKey());

    expect($cells['member'])->toBe('Aisha Haddad')
        ->and($cells['role'])->toBe('Member')
        ->and($cells['actions'])->toBe(3)
        ->and($cells['leads'])->toBe(2)
        ->and($cells['contacted'])->toBe(1)
        ->and($cells['notes'])->toBe(1)
        ->and($cells['stage_moves'])->toBe(1)
        ->and($cells['tasks_done'])->toBe(0)
        ->and($cells['last_active'])->toBeString();

    // Three members, busiest first, and the two who did nothing sink in name order.
    expect($table->rows)->toHaveCount(3)
        ->and($table->rows[0]->key)->toBe('member-'.$member->getKey());

    $idleCells = cellsOf($table, 'member-'.$idle->getKey());

    expect($idleCells['actions'])->toBe(0)
        // Never active in the window, which is a date we do not have rather than a zero.
        ->and($idleCells['last_active'])->toBeNull();
});

it('groups AI spend by the work it paid for', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        AiRequest::factory()->create([
            'team_id' => $team->getKey(),
            'prompt_key' => 'scoring.qualitative_fit',
            'input_tokens' => 1_000,
            'output_tokens' => 200,
            'cost_micros' => 250_000,
        ]);

        // A later version of the same prompt is the same job.
        AiRequest::factory()->create([
            'team_id' => $team->getKey(),
            'prompt_key' => 'scoring.qualitative_fit',
            'prompt_version' => 2,
            'input_tokens' => 500,
            'output_tokens' => 100,
            'cost_micros' => 150_000,
        ]);

        // Cached: counted as a call, costs nothing. Which is why the two columns do not move
        // together, and why the hit rate is the column worth watching.
        AiRequest::factory()->cacheHit()->create([
            'team_id' => $team->getKey(),
            'prompt_key' => 'scoring.qualitative_fit',
        ]);

        AiRequest::factory()->failed()->create([
            'team_id' => $team->getKey(),
            'prompt_key' => 'outreach.draft',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_micros' => 0,
        ]);
    });

    $table = app(BuildAiUsageAction::class)->handle($team, DateRange::allTime('UTC'));

    $scoring = cellsOf($table, 'scoring.qualitative_fit');

    expect($scoring['prompt'])->toBe('scoring.qualitative_fit')
        ->and($scoring['calls'])->toBe(3)
        ->and($scoring['failures'])->toBe(0)
        ->and($scoring['cache_rate'])->toBe(0.3333)
        ->and($scoring['input_tokens'])->toBe(1_500)
        ->and($scoring['output_tokens'])->toBe(300)
        // 400,000 micros is 40 minor units — money stays an integer (CLAUDE.md §2).
        ->and($scoring['cost'])->toBe(40);

    expect(cellsOf($table, 'outreach.draft')['failures'])->toBe(1)
        // Dearest first, so the row that is costing money is the one a reader sees.
        ->and($table->rows[0]->key)->toBe('scoring.qualitative_fit');

    expect($table->columns[6]->currency)->toBe('GBP');
});

it('never reports another team\'s rows in any table', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    $otherLead = reportLead($otherTeam);

    app(TeamContext::class)->run($otherTeam, function () use ($otherTeam, $otherLead): void {
        ContactAttempt::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'lead_id' => $otherLead->getKey(),
        ]);

        AiRequest::factory()->create(['team_id' => $otherTeam->getKey()]);

        Icp::factory()->active()->create(['team_id' => $otherTeam->getKey()]);
    });

    $range = DateRange::allTime('UTC');

    expect(app(BuildOutreachPerformanceAction::class)->handle($team, $range)->rows)->toBe([])
        ->and(app(BuildAiUsageAction::class)->handle($team, $range)->rows)->toBe([])
        ->and(app(BuildIcpPerformanceAction::class)->handle($team, $range)->rows)->toBe([])
        // The activity table lists this team's members, which is one person.
        ->and(app(BuildTeamActivityAction::class)->handle($team, $range)->rows)->toHaveCount(1);
});
