<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A lead created a given number of days ago, optionally scored against the team's active ICP.
 */
function dashboardLead(
    Team $team,
    int $daysOld = 0,
    ?ScoreGrade $grade = null,
    LeadSource $source = LeadSource::CompaniesHouse,
): Lead {
    return app(TeamContext::class)->run($team, function () use ($team, $daysOld, $grade, $source): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => $source,
            'dedupe_key' => 'dash:'.Str::random(12),
        ]);

        $lead->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        if ($grade !== null) {
            $icp = Icp::query()->where('team_id', $team->getKey())->where('is_active', true)->first()
                ?? Icp::factory()->create(['team_id' => $team->getKey(), 'is_active' => true]);

            LeadScore::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'icp_id' => $icp->getKey(),
                'grade' => $grade,
                'excluded' => false,
            ]);
        }

        return $lead->refresh();
    });
}

it('redirects guests to the login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('renders the dashboard for a member of a team', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('report.kpis', 4)
            ->has('report.funnel', 4)
            ->has('report.tables', 4)
            ->where('report.has_data', false));
});

it('counts leads and strong fits in the funnel', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    dashboardLead($team, grade: ScoreGrade::A);
    dashboardLead($team, grade: ScoreGrade::B);
    dashboardLead($team, grade: ScoreGrade::D);
    dashboardLead($team);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.has_data', true)
            ->where('report.funnel.0.value', 4)
            ->where('report.funnel.1.value', 2));
});

it('counts contacted and replied leads, not attempts', function (): void {
    // A lead emailed three times is one lead in the funnel: any other reading would let the
    // "contacted" step overtake the one above it, and a funnel that widens is not a funnel.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $emailedTwice = dashboardLead($team);
    $replied = dashboardLead($team);
    $bounced = dashboardLead($team);
    dashboardLead($team);

    app(TeamContext::class)->run($team, function () use ($team, $emailedTwice, $replied, $bounced): void {
        ContactAttempt::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $emailedTwice->getKey(),
            'outcome' => ContactOutcome::Pending,
        ]);

        ContactAttempt::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $replied->getKey(),
            'outcome' => ContactOutcome::MeetingBooked,
        ]);

        // A bounce is not a reply — nobody read it.
        ContactAttempt::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $bounced->getKey(),
            'outcome' => ContactOutcome::Bounced,
        ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.funnel.0.value', 4)
            ->where('report.funnel.2.key', 'contacted')
            ->where('report.funnel.2.value', 3)
            ->where('report.funnel.2.available', true)
            ->where('report.funnel.3.key', 'replied')
            ->where('report.funnel.3.value', 1));
});

it('counts an opt-out as a reply', function (): void {
    // It is a reply, and a painful one. A funnel that hid it would flatter the reply rate by
    // dropping exactly the responses worth learning from.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = dashboardLead($team);

    app(TeamContext::class)->run($team, fn (): ContactAttempt => ContactAttempt::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'outcome' => ContactOutcome::OptedOut,
    ]));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.funnel.3.value', 1));
});

it('defaults to all time and reports the window it used', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    dashboardLead($team, daysOld: 400);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.range', 'all_time')
            ->where('report.range_from', null)
            ->where('report.range_to', null)
            // A year-old lead still counts: the dashboard is where a member lands, and "leads in
            // your list" is an inventory question before it is a flow one.
            ->where('report.funnel.0.value', 1)
            // No window before all time, so no delta dressed up as a movement.
            ->where('report.kpis.0.delta', null));
});

it('narrows every figure to the selected window', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    dashboardLead($team, daysOld: 2, grade: ScoreGrade::A, source: LeadSource::GooglePlaces);
    dashboardLead($team, daysOld: 5, source: LeadSource::GooglePlaces);
    // Outside a 7-day window, inside the 7 before it.
    dashboardLead($team, daysOld: 9, grade: ScoreGrade::A);
    dashboardLead($team, daysOld: 11, source: LeadSource::CompaniesHouse);

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'last_7_days']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.range', 'last_7_days')
            ->where('report.funnel.0.value', 2)
            ->where('report.funnel.1.value', 1)
            // The fit mix explains the same cohort as the funnel, not every score ever given.
            ->where('report.grades.0.value', 1)
            // One source used inside the window, so the other is dropped.
            ->has('report.sources', 1)
            ->where('report.sources.0.key', 'google_places')
            // Bars are days under a short window, not weeks.
            ->where('report.trend_granularity', 'day')
            ->where('report.kpis.0.label', 'Prospects sourced')
            ->where('report.kpis.0.value', 2)
            // Two in the window against two in the seven days before it.
            ->where('report.kpis.0.delta', 0)
            ->where('report.kpis.0.delta_label', 'vs the 7 days before'));
});

it('accepts a custom window and echoes its dates back', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $from = now()->subDays(10)->toDateString();
    $to = now()->subDays(5)->toDateString();

    dashboardLead($team, daysOld: 7);
    dashboardLead($team, daysOld: 1);

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'custom', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.range_from', $from)
            ->where('report.range_to', $to)
            ->where('report.funnel.0.value', 1)
            ->where('filters.from', $from)
            ->where('filters.to', $to));
});

it('rejects a malformed window rather than quietly showing different numbers', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => 'last_fortnight']))
        ->assertSessionHasErrors('range');

    $this->actingAs($user)
        ->get(route('dashboard', ['from' => '2026-10-10', 'to' => '2026-10-01']))
        ->assertSessionHasErrors('to');
});

it('offers every range and every report to the filter controls', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('options.ranges', count(ReportRange::presets()))
            // A chart that cannot be exported is a chart the member cannot take anywhere, so the
            // menu is built off the enum rather than typed out in the component.
            ->has('options.reports', count(ReportKey::cases())));
});

it('builds the four performance tables in tab order', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.tables', 4)
            ->where('report.tables.0.key', 'icp_performance')
            ->where('report.tables.1.key', 'outreach')
            ->where('report.tables.2.key', 'team_activity')
            ->where('report.tables.3.key', 'ai_usage')
            // Every member gets a row, including one who did nothing: a member at zero is the most
            // actionable line in the table.
            ->has('report.tables.2.rows', 1));
});

it('buckets the trend by week and keeps qualified inside sourced', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // This week, and a week that is comfortably inside the 12-week window.
    dashboardLead($team, daysOld: 0, grade: ScoreGrade::A);
    dashboardLead($team, daysOld: 0);
    dashboardLead($team, daysOld: 21);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $trend = collect($page->toArray()['props']['report']['trend']);

            expect($trend)->toHaveCount(12)
                ->and($trend->sum('sourced'))->toBe(3)
                ->and($trend->sum('qualified'))->toBe(1)
                // The last bucket is the current week.
                ->and($trend->last()['sourced'])->toBe(2)
                ->and($trend->last()['qualified'])->toBe(1);

            // The cohort invariant the chart is drawn on.
            $trend->each(fn (array $point) => expect($point['qualified'])
                ->toBeLessThanOrEqual($point['sourced']));

            return $page;
        });
});

it('always returns all four grades, zeros included', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    dashboardLead($team, grade: ScoreGrade::A);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.grades', 4)
            ->where('report.grades.0.key', 'A')
            ->where('report.grades.0.value', 1)
            ->where('report.grades.3.key', 'D')
            ->where('report.grades.3.value', 0));
});

it('breaks leads down by source, busiest first, dropping unused sources', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    dashboardLead($team, source: LeadSource::GooglePlaces);
    dashboardLead($team, source: LeadSource::GooglePlaces);
    dashboardLead($team, source: LeadSource::CompaniesHouse);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.sources', 2)
            ->where('report.sources.0.key', 'google_places')
            ->where('report.sources.0.value', 2)
            ->where('report.sources.1.key', 'companies_house'));
});

it('lists pipeline stages in board order', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $stages = app(EnsureDefaultPipelineStagesAction::class)->handle($team);
    $lead = dashboardLead($team);

    app(TeamContext::class)->run($team, function () use ($lead, $stages): void {
        $lead->forceFill(['pipeline_stage_id' => $stages->first()?->getKey()])->save();
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.stages', $stages->count())
            ->where('report.stages.0.value', 1));
});

it('counts undismissed signals by event type', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::New,
            'event_type' => SignalEventType::FundingRound,
        ]);

        // Dismissed signals are a judgement that the event was not worth acting on; counting them
        // would make a noisy watch look productive.
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::Dismissed,
            'event_type' => SignalEventType::Acquisition,
        ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.signal_types', 1)
            ->where('report.signal_types.0.key', 'funding_round')
            ->where('report.signal_types.0.value', 2)
            ->where('report.kpis.2.value', 2));
});

it('never reports another team\'s data', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    dashboardLead($team);
    dashboardLead($otherTeam);
    dashboardLead($otherTeam);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.funnel.0.value', 1));

    $this->actingAs($other)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.funnel.0.value', 2));
});

it('lets a read-only member see the dashboard', function (): void {
    // Reporting is a view of work the team already owns; hiding the numbers from the person asked
    // to read them would make the role useless.
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $reader = createUserWithTeam();
    $team->users()->attach($reader->getKey(), ['role' => TeamRole::ReadOnly->value]);
    $reader->switchTeam($team);

    $this->actingAs($reader->refresh())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard'));
});
