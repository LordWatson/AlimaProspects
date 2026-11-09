<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Reporting\Contracts\UsageSummarySource;
use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Services\DashboardReportBuilder;
use App\Modules\Reporting\Services\NoUsageSummary;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The dashboard's personal panel and the reachability mix.
 *
 * The thing both of these can get wrong is silent: a queue that quietly belongs to somebody else,
 * or a band that counts "we have not looked" as "there is nothing there". Neither shows up as an
 * error — the screen renders a plausible number — so each of those is asserted directly rather
 * than through a happy path.
 */
function panelLead(Team $team, ?int $readiness = null, ?PipelineStage $stage = null): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'readiness' => $readiness,
        'pipeline_stage_id' => $stage?->getKey(),
        'dedupe_key' => 'panel:'.Str::random(12),
    ]));
}

function assignTo(Team $team, Lead $lead, User $member): void
{
    app(TeamContext::class)->run($team, fn (): LeadAssignment => LeadAssignment::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'user_id' => $member->getKey(),
    ]));
}

function taskFor(Team $team, Lead $lead, ?User $member, string $due, ?string $title = null): LeadTask
{
    return app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'assigned_to' => $member?->getKey(),
        'status' => LeadTaskStatus::Open,
        'title' => $title ?? 'Call them back',
        'due_at' => Carbon::parse($due),
    ]));
}

it('counts only the member’s own queues', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $colleague = User::factory()->create();
    $team->users()->attach($colleague, ['role' => TeamRole::Member->value]);

    $mine = panelLead($team);
    $theirs = panelLead($team);

    assignTo($team, $mine, $user);
    assignTo($team, $theirs, $colleague);

    // A task on a company I own but assigned to somebody else is not mine to do.
    taskFor($team, $mine, $colleague, '-1 day');
    taskFor($team, $mine, $user, '-2 days');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.my_work.member', $user->name)
            ->where('report.my_work.open_leads', 1)
            ->where('report.my_work.tasks_overdue', 1)
            ->has('report.my_work.tasks', 1));
});

it('separates overdue from due today, and counts each once', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $lead = panelLead($team);

    taskFor($team, $lead, $user, '-3 days', 'Chase the quote');
    taskFor($team, $lead, $user, '+2 hours', 'Send the deck');
    // Tomorrow is neither, and a panel that swept it into "due today" would have somebody working
    // ahead of a date they set deliberately.
    taskFor($team, $lead, $user, '+2 days', 'Follow up next week');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.my_work.tasks_overdue', 1)
            ->where('report.my_work.tasks_due_today', 1)
            // Soonest first, so the overdue one leads.
            ->where('report.my_work.tasks.0.title', 'Chase the quote')
            ->where('report.my_work.tasks.0.overdue', true)
            ->where('report.my_work.tasks.1.overdue', false));
});

it('leaves a won or lost company out of the open count', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(EnsureDefaultPipelineStagesAction::class)->handle($team);

    $won = app(TeamContext::class)->run($team, fn (): ?PipelineStage => PipelineStage::query()
        ->where('team_id', $team->getKey())
        ->where('outcome', StageOutcome::Won)
        ->first());

    expect($won)->not->toBeNull();

    // No stage at all is the default column, which is where most leads sit — it has to count as
    // open, or the figure would be zero for almost every team.
    $unmoved = panelLead($team);
    $signed = panelLead($team, stage: $won);

    assignTo($team, $unmoved, $user);
    assignTo($team, $signed, $user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.my_work.open_leads', 1));
});

it('counts signals about the member’s companies, and not the team’s', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $colleague = User::factory()->create();
    $team->users()->attach($colleague, ['role' => TeamRole::Member->value]);

    $mine = panelLead($team);
    $theirs = panelLead($team);
    assignTo($team, $mine, $user);
    assignTo($team, $theirs, $colleague);

    app(TeamContext::class)->run($team, function () use ($team, $mine, $theirs): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $definition = signalDefinition($team);

        foreach ([$mine, $theirs] as $lead) {
            Signal::factory()->forDefinition($definition)->create([
                'team_id' => $team->getKey(),
                'signal_watch_id' => $watch->getKey(),
                'status' => SignalStatus::New,
                'matched_lead_id' => $lead->getKey(),
            ]);
        }

        // Already actioned: it is nobody's queue any more.
        Signal::factory()->forDefinition($definition)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::Dismissed,
            'matched_lead_id' => $mine->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.my_work.signals_waiting', 1));
});

it('scopes the activity figures to the window but not the queues', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $lead = panelLead($team);

    assignTo($team, $lead, $user);
    taskFor($team, $lead, $user, '-40 days');

    app(TeamContext::class)->run($team, function () use ($team, $lead, $user): void {
        LeadActivity::factory()->by((int) $user->getKey())
            ->type(LeadActivityType::Contacted)
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => now()->subDay(),
            ]);

        // Outside a seven-day window: it happened, but not in the period being read.
        LeadActivity::factory()->by((int) $user->getKey())
            ->type(LeadActivityType::NoteAdded)
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => now()->subDays(40),
            ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => ReportRange::Last7Days->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.my_work.actions', 1)
            ->where('report.my_work.companies_worked', 1)
            ->where('report.my_work.approaches', 1)
            // A queue is not a period: the task went overdue six weeks ago and is still overdue.
            ->where('report.my_work.tasks_overdue', 1));
});

it('bands reachability and keeps “not assessed” out of the empty band', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    panelLead($team, readiness: 90);
    panelLead($team, readiness: 70);
    panelLead($team, readiness: 55);
    panelLead($team, readiness: 10);
    // Null is its own band. Counting it as zero would make a team that has not run enrichment look
    // like a team whose prospects cannot be contacted.
    panelLead($team);
    panelLead($team);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.reachability', 4)
            ->where('report.reachability.0.key', 'ready')
            ->where('report.reachability.0.value', 2)
            ->where('report.reachability.1.key', 'partial')
            ->where('report.reachability.1.value', 1)
            ->where('report.reachability.2.key', 'sparse')
            ->where('report.reachability.2.value', 1)
            ->where('report.reachability.3.key', 'unassessed')
            ->where('report.reachability.3.value', 2));
});

it('shows the month’s allowances, which no date filter moves', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // Billing is registered last and binds the dashboard's usage seam over Reporting's own empty
    // default, so the panel exists because Billing is installed — not because the dashboard knows
    // what a plan is.
    $report = app(DashboardReportBuilder::class)->forTeam(
        $team,
        new ReportFilters(ReportRange::LastMonth),
        $user,
    );

    $narrow = app(DashboardReportBuilder::class)->forTeam(
        $team,
        new ReportFilters(ReportRange::Last7Days),
        $user,
    );

    expect($report->allowances)->not->toBeEmpty();

    foreach ($report->allowances as $line) {
        // Nothing unlimited and nothing untouched-at-zero: both are furniture on a panel whose
        // whole job is to warn.
        expect($line->included)->not->toBeNull();
        expect($line->included > 0 || $line->used > 0)->toBeTrue();
    }

    // The figures are the calendar month's, so changing the window must not move one of them —
    // which is the claim the panel's own subtitle makes to the member.
    expect(array_map(static fn ($line): array => [$line->metric, $line->used], $narrow->allowances))
        ->toEqual(array_map(static fn ($line): array => [$line->metric, $line->used], $report->allowances));

    // And it reaches the screen.
    $this->actingAs($user)
        ->get(route('dashboard', ['range' => ReportRange::LastMonth->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('report.allowances', count($report->allowances)));
});

it('gives a caller with no person behind it neither a panel nor the screen-only aggregates', function (): void {
    // The export and the AI-insights snapshot have a team but no person. Inventing one would put
    // somebody else's queue in a downloaded file — `my_work` is nullable so it cannot happen by
    // omission — and the two screen-only panels are skipped as well, because running a
    // nine-metric allowance summary for a nightly job that discards it is a cost per team for
    // nothing.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    panelLead($team, readiness: 80);

    $report = app(DashboardReportBuilder::class)->forTeam($team);

    expect($report->my_work)->toBeNull()
        ->and($report->reachability)->toBe([])
        ->and($report->allowances)->toBe([])
        // The team's own figures are still there: this is the same report, minus two panels
        // nobody without a screen reads.
        ->and($report->tables)->not->toBeEmpty();
});

it('draws no allowance panel when nothing fills the usage seam', function (): void {
    // Reporting's own default. Billing binds over it in a normal install, so this is the shape of
    // an installation that meters nothing — and the one case where the panel must not appear at
    // all rather than appear empty.
    $this->app->instance(UsageSummarySource::class, new NoUsageSummary);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('report.allowances', 0));
});

it('counts nothing belonging to the member’s other team', function (): void {
    // The realistic cross-tenant case here is not two users, it is **one** user in two teams: the
    // per-member filters would all still match, and only the `team_id` on every query stops the
    // other team's work appearing in this one's dashboard.
    $user = createUserWithTeam();
    $here = $user->currentTeam()->firstOrFail();

    $elsewhere = createUserWithTeam()->currentTeam()->firstOrFail();
    $elsewhere->users()->attach($user, ['role' => TeamRole::Member->value]);

    $mine = panelLead($here, readiness: 80);
    assignTo($here, $mine, $user);

    $theirs = panelLead($elsewhere, readiness: 80);
    assignTo($elsewhere, $theirs, $user);
    taskFor($elsewhere, $theirs, $user, '-1 day');

    app(TeamContext::class)->run($elsewhere, function () use ($elsewhere, $theirs, $user): void {
        LeadActivity::factory()->by((int) $user->getKey())
            ->type(LeadActivityType::Contacted)
            ->create([
                'team_id' => $elsewhere->getKey(),
                'lead_id' => $theirs->getKey(),
                'occurred_at' => now(),
            ]);

        $watch = SignalWatch::factory()->create(['team_id' => $elsewhere->getKey()]);

        Signal::factory()->forDefinition(signalDefinition($elsewhere))->create([
            'team_id' => $elsewhere->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::New,
            'matched_lead_id' => $theirs->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.my_work.open_leads', 1)
            ->where('report.my_work.tasks_overdue', 0)
            ->where('report.my_work.signals_waiting', 0)
            ->where('report.my_work.actions', 0)
            // And the team-level chart too: one readiness-80 lead here, not two.
            ->where('report.reachability.0.value', 1));
});

it('ignores a signal that was converted rather than matched', function (): void {
    // `lead_id` is the lead a signal became; `matched_lead_id` is the lead it is about (ADR 0021).
    // A converted signal is a lead on somebody's list, not an event still waiting on them.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = panelLead($team);
    assignTo($team, $lead, $user);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->forDefinition(signalDefinition($team))->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::New,
            'matched_lead_id' => null,
            'lead_id' => $lead->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.my_work.signals_waiting', 0));
});
