<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadsWorkspace\Actions\SetLeadAssigneesAction;
use App\Modules\LeadsWorkspace\Enums\LeadTaskStatus;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\AnnounceTopLeadsJob;
use App\Modules\Notifications\Listeners\AnnounceTopLeads;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Signals\Events\SignalsDetected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LeadsWorkspaceFixtures as Workspace;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The producers: what actually raises a notification (Phase 12).
 *
 * The case worth the most here is the **aggregation**. A discovery run can grade fifty leads a
 * minute, and the obvious "notify on each A grade" would mean fifty notifications and a member who
 * switches the category off before lunch. The job is `ShouldBeUnique` per team so the burst
 * collapses into one — which is the feature, and is what these tests pin.
 */
beforeEach(function (): void {
    Mail::fake();
});

// --- Discovery -----------------------------------------------------------------------------------

it('tells the whole team when a search finishes', function (): void {
    [$owner, $team] = Fixtures::team();
    $member = Fixtures::member($team);

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'leads_created' => 7,
        'leads_found' => 9,
    ]));

    event(new DiscoveryRunCompleted($team->getKey(), (int) $run->getKey()));

    expect(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->first()->notificationType())->toBe(NotificationType::DiscoveryComplete)
        ->and(Notification::query()->first()->params()['count'])->toBe(7)
        ->and(Notification::query()->first()->url())->toBe('/discovery/'.$run->getKey())
        ->and([$owner->getKey(), $member->getKey()])->toContain(Notification::query()->first()->notifiable_id);
});

it('still says so when a search found nothing — that is the useful part', function (): void {
    [, $team] = Fixtures::team();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'leads_created' => 0,
        'leads_found' => 0,
    ]));

    event(new DiscoveryRunCompleted($team->getKey(), (int) $run->getKey()));

    // It means the ICP or the terms need a look, which is more useful to know than to discover a
    // week later.
    expect(Notification::query()->sole()->data['title'])->toBe('Your search found nothing new');
});

// --- Strong matches, aggregated ------------------------------------------------------------------

it('collapses a burst of A grades into one queued announcement', function (): void {
    Queue::fake();

    [, $team] = Fixtures::team();

    foreach (range(1, 20) as $ignored) {
        app(AnnounceTopLeads::class)->handle(new LeadScored(
            teamId: (int) $team->getKey(),
            leadId: 1,
            scoreId: 1,
            grade: ScoreGrade::A->value,
            score: 92,
        ));
    }

    // Twenty dispatches, because `ShouldBeUnique` is enforced when the job is pushed — the point
    // is that only one of them survives to run, and the unique id is what guarantees it.
    Queue::assertPushed(AnnounceTopLeadsJob::class);

    expect((new AnnounceTopLeadsJob((int) $team->getKey()))->uniqueId())
        ->toBe('announce-top-leads:'.$team->getKey());
});

it('ignores anything that is not an A grade', function (): void {
    Queue::fake();

    [, $team] = Fixtures::team();

    foreach ([ScoreGrade::B, ScoreGrade::C, ScoreGrade::D] as $grade) {
        app(AnnounceTopLeads::class)->handle(new LeadScored(
            teamId: (int) $team->getKey(),
            leadId: 1,
            scoreId: 1,
            grade: $grade->value,
            score: 50,
        ));
    }

    Queue::assertNothingPushed();
});

it('announces the batch with a count and a couple of names', function (): void {
    [, $team, $icp] = Workspace::team();

    foreach (['Acme Ltd', 'Bright Bean', 'Third Co'] as $name) {
        Workspace::lead($team, ['company_name' => $name], $icp, ScoreGrade::A, 90);
    }

    app(AnnounceTopLeadsJob::class, ['teamId' => (int) $team->getKey()])
        ->handle(app(TeamContext::class), app(NotificationDispatcher::class));

    $notification = Notification::query()->first();

    expect(Notification::query()->count())->toBe(1)
        ->and($notification->notificationType())->toBe(NotificationType::TopLeads)
        ->and($notification->params()['count'])->toBe(3)
        // Two named and "and 1 others": enough to tell a member whether to stop what they are doing.
        ->and($notification->params()['names'])->toContain('Acme Ltd');
});

it('says nothing when the window closed with nothing in it', function (): void {
    [, $team, $icp] = Workspace::team();

    // Graded, but outside the window — a re-score that has since moved on.
    $lead = Workspace::lead($team, ['company_name' => 'Old News'], $icp, ScoreGrade::A, 90);

    app(TeamContext::class)->run($team, static fn () => LeadScore::query()
        ->where('lead_id', $lead->getKey())
        ->update(['scored_at' => now()->subDay()]));

    app(AnnounceTopLeadsJob::class, ['teamId' => (int) $team->getKey()])
        ->handle(app(TeamContext::class), app(NotificationDispatcher::class));

    // Announcing "0 new matches" would be worse than silence.
    expect(Notification::query()->count())->toBe(0);
});

// --- Signals -------------------------------------------------------------------------------------

it('tells the team when a watch brings back signals', function (): void {
    [, $team] = Fixtures::team();

    event(new SignalsDetected((int) $team->getKey(), runId: 5, count: 3));

    expect(Notification::query()->sole()->notificationType())->toBe(NotificationType::SignalsDetected)
        ->and(Notification::query()->sole()->params()['count'])->toBe(3);
});

it('says nothing for a run that found none', function (): void {
    [, $team] = Fixtures::team();

    event(new SignalsDetected((int) $team->getKey(), runId: 5, count: 0));

    expect(Notification::query()->count())->toBe(0);
});

// --- Assignment ----------------------------------------------------------------------------------

it('tells a colleague a lead has been put in their hands', function (): void {
    [$owner, $team] = Workspace::team();
    $colleague = Fixtures::member($team);
    $lead = Workspace::lead($team, ['company_name' => 'Acme Ltd']);

    app(TeamContext::class)->run($team, fn () => app(SetLeadAssigneesAction::class)
        ->handle($team, $lead, [(int) $colleague->getKey()], $owner));

    $notification = Notification::query()->sole();

    expect($notification->notifiable_id)->toBe($colleague->getKey())
        ->and($notification->notificationType())->toBe(NotificationType::LeadAssigned)
        // Named, not "a lead was assigned to you".
        ->and($notification->params()['company'])->toBe('Acme Ltd')
        ->and($notification->params()['actor'])->toBe($owner->name)
        ->and($notification->url())->toBe('/leads/'.$lead->getKey());
});

it('does not tell a member about a lead they assigned to themselves', function (): void {
    [$owner, $team] = Workspace::team();
    $lead = Workspace::lead($team);

    // The most common assignment in the product — a member picking a lead up. Notifying them about
    // their own click is the fastest way to teach somebody our notifications are worthless.
    app(TeamContext::class)->run($team, fn () => app(SetLeadAssigneesAction::class)
        ->handle($team, $lead, [(int) $owner->getKey()], $owner));

    expect(Notification::query()->count())->toBe(0);
});

it('does not tell somebody again when a second colleague joins the lead', function (): void {
    [$owner, $team] = Workspace::team();
    $first = Fixtures::member($team);
    $second = Fixtures::member($team);
    $lead = Workspace::lead($team);

    app(TeamContext::class)->run($team, fn () => app(SetLeadAssigneesAction::class)
        ->handle($team, $lead, [(int) $first->getKey()], $owner));

    app(TeamContext::class)->run($team, fn () => app(SetLeadAssigneesAction::class)
        ->handle($team, $lead, [(int) $first->getKey(), (int) $second->getKey()], $owner));

    // Only the newly added member is told: the first already has the lead.
    expect(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->pluck('notifiable_id')->sort()->values()->all())
        ->toBe(collect([$first, $second])->map->getKey()->sort()->values()->all());
});

// --- Task reminders (the Phase 10b deferral) -----------------------------------------------------

it('sends a due task reminder once, and stamps it so it cannot go twice', function (): void {
    [$owner, $team] = Workspace::team();
    $lead = Workspace::lead($team, ['company_name' => 'Acme Ltd']);

    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'created_by' => $owner->getKey(),
        'assigned_to' => $owner->getKey(),
        'title' => 'Call them back',
        'remind_at' => now()->subHour(),
    ]));

    $this->artisan('notifications:task-reminders')->assertSuccessful();

    expect($task->fresh()->reminded_at)->not->toBeNull()
        ->and(Notification::query()->sole()->notificationType())->toBe(NotificationType::TaskDue)
        ->and(Notification::query()->sole()->params()['task'])->toBe('Call them back')
        ->and(Notification::query()->sole()->params()['company'])->toBe('Acme Ltd');

    // The idempotency `lead_tasks.remind_at` was designed around in Phase 10b.
    $this->artisan('notifications:task-reminders')->assertSuccessful();

    expect(Notification::query()->count())->toBe(1);
});

it('leaves a reminder that is not due, and one on a finished task', function (): void {
    [$owner, $team] = Workspace::team();
    $lead = Workspace::lead($team);

    app(TeamContext::class)->run($team, fn () => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'assigned_to' => $owner->getKey(),
        'remind_at' => now()->addDay(),
    ]));

    app(TeamContext::class)->run($team, fn () => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'assigned_to' => $owner->getKey(),
        'status' => LeadTaskStatus::Done,
        'remind_at' => now()->subHour(),
    ]));

    $this->artisan('notifications:task-reminders')->assertSuccessful();

    expect(Notification::query()->count())->toBe(0);
});

it('sends an unassigned task\'s reminder to whoever set it', function (): void {
    [$owner, $team] = Workspace::team();
    $lead = Workspace::lead($team);

    app(TeamContext::class)->run($team, fn () => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'created_by' => $owner->getKey(),
        'assigned_to' => null,
        'remind_at' => now()->subHour(),
    ]));

    $this->artisan('notifications:task-reminders')->assertSuccessful();

    // An unassigned task is the team's, and the person who set the reminder is the one who wanted
    // it — so it goes to them rather than nowhere.
    expect(Notification::query()->sole()->notifiable_id)->toBe($owner->getKey());
});

it('reports without sending or stamping on a dry run', function (): void {
    [$owner, $team] = Workspace::team();
    $lead = Workspace::lead($team);

    $task = app(TeamContext::class)->run($team, fn (): LeadTask => LeadTask::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'assigned_to' => $owner->getKey(),
        'remind_at' => now()->subHour(),
    ]));

    $this->artisan('notifications:task-reminders --dry-run')->assertSuccessful();

    expect($task->fresh()->reminded_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(0);
});
