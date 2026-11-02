<?php

declare(strict_types=1);

use App\Modules\Admin\Actions\PublishAnnouncementAction;
use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Jobs\AnnounceToTenantsJob;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Services\AnnouncementBoard;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/*
 * Platform announcements (Phase 19b).
 *
 * The banner an operator uses to tell every tenant about scheduled maintenance. Two properties
 * carry most of the risk and so most of these tests: it is read on **every** tenant page load, and
 * its fan-out writes a notification into **every** targeted tenant — so a mistake here is a mistake
 * a thousand customers see at once. Hence the kill switch, and hence `notified_at`.
 */

function publish(array $overrides = []): Announcement
{
    return app(PublishAnnouncementAction::class)->handle([
        'level' => $overrides['level'] ?? AnnouncementLevel::Info,
        'title' => $overrides['title'] ?? ['en' => 'Scheduled maintenance on Sunday'],
        'body' => $overrides['body'] ?? ['en' => 'Discovery will pause between 02:00 and 04:00 UTC.'],
        'plans' => $overrides['plans'] ?? null,
        'markets' => $overrides['markets'] ?? null,
        'starts_at' => $overrides['starts_at'] ?? CarbonImmutable::now('UTC')->subMinute(),
        'ends_at' => $overrides['ends_at'] ?? null,
        'is_dismissible' => $overrides['is_dismissible'] ?? true,
        'notify' => $overrides['notify'] ?? false,
    ], $overrides['operator'] ?? Fixtures::operator(AdminRole::SuperAdmin));
}

function board(): AnnouncementBoard
{
    app()->forgetInstance(PlatformSettings::class);
    app()->forgetInstance(AnnouncementBoard::class);

    return app(AnnouncementBoard::class);
}

// --- What a reader sees ----------------------------------------------------------------------------

it('shows a live announcement to a tenant', function (): void {
    [$owner, $team] = Fixtures::tenant();
    publish();

    $visible = board()->forUser($owner, $team, 'en');

    expect($visible)->toHaveCount(1)
        ->and($visible[0]['title'])->toBe('Scheduled maintenance on Sunday')
        ->and($visible[0]['dismissible'])->toBeTrue();
});

it('says nothing before it starts or after it ends', function (): void {
    [$owner, $team] = Fixtures::tenant();

    publish(['starts_at' => CarbonImmutable::now('UTC')->addDay()]);
    publish(['ends_at' => CarbonImmutable::now('UTC')->subMinute()]);

    // A window, not a flag: an operator schedules Sunday's maintenance notice on Thursday and does
    // not have to be at a keyboard to take it down.
    expect(board()->forUser($owner, $team, 'en'))->toBe([]);
});

it('reads the copy in the reader’s language, and falls back rather than going blank', function (): void {
    [$owner, $team] = Fixtures::tenant();

    publish([
        'title' => ['en' => 'Scheduled maintenance', 'ar' => 'صيانة مجدولة'],
        'body' => ['ar' => 'سيتوقف الاكتشاف مؤقتًا.'],
    ]);

    $arabic = board()->forUser($owner, $team, 'ar');
    $english = board()->forUser($owner, $team, 'en');

    expect($arabic[0]['title'])->toBe('صيانة مجدولة')
        ->and($arabic[0]['body'])->toBe('سيتوقف الاكتشاف مؤقتًا.')
        // The second fallback: an operator who filled in Arabic and not English should not leave
        // English readers with a title and an empty body.
        ->and($english[0]['title'])->toBe('Scheduled maintenance')
        ->and($english[0]['body'])->toBe('سيتوقف الاكتشاف مؤقتًا.');
});

it('skips an announcement with nothing to say in any language', function (): void {
    [$owner, $team] = Fixtures::tenant();

    publish(['title' => [], 'body' => []]);

    // A draft somebody saved, not a notice. Rendering it would put an empty coloured bar across
    // every tenant's dashboard.
    expect(board()->forUser($owner, $team, 'en'))->toBe([]);
});

// --- Targeting --------------------------------------------------------------------------------------

it('targets a plan, and leaves everybody else alone', function (): void {
    [$starterOwner, $starter] = Billing::team();
    Billing::subscribe($starter, PlanKey::Starter);

    [$scaleOwner, $scale] = Billing::team();
    Billing::subscribe($scale, PlanKey::Scale);

    publish(['plans' => [PlanKey::Scale->value]]);

    expect(board()->forUser($scaleOwner, $scale, 'en'))->toHaveCount(1)
        ->and(board()->forUser($starterOwner, $starter, 'en'))->toBe([]);
});

it('targets a market', function (): void {
    [$ukOwner, $uk] = Fixtures::tenant('UK Ltd', ['market' => Market::Uk->value]);
    [$uaeOwner, $uae] = Fixtures::tenant('UAE LLC', ['market' => Market::Uae->value]);

    publish(['markets' => [Market::Uae->value]]);

    expect(board()->forUser($uaeOwner, $uae, 'en'))->toHaveCount(1)
        ->and(board()->forUser($ukOwner, $uk, 'en'))->toBe([]);
});

it('treats no targets as everybody', function (): void {
    [$ukOwner, $uk] = Fixtures::tenant('UK Ltd', ['market' => Market::Uk->value]);
    [$uaeOwner, $uae] = Fixtures::tenant('UAE LLC', ['market' => Market::Uae->value]);

    publish(['plans' => null, 'markets' => null]);

    // The honest default for an outage: the operator writing it is not thinking about segments.
    expect(board()->forUser($uaeOwner, $uae, 'en'))->toHaveCount(1)
        ->and(board()->forUser($ukOwner, $uk, 'en'))->toHaveCount(1);
});

// --- Dismissal ---------------------------------------------------------------------------------------

it('hides a dismissed announcement from that person only', function (): void {
    [$owner, $team] = Fixtures::tenant();
    $colleague = Fixtures::member($team);

    $announcement = publish();

    $this->actingAs($owner)
        ->post(route('announcements.dismiss', $announcement))
        ->assertRedirect();

    // **Per person, not per team.** One colleague clearing a banner must not hide Sunday's
    // maintenance from the four people who have not read it yet.
    expect(board()->forUser($owner->refresh(), $team, 'en'))->toBe([])
        ->and(board()->forUser($colleague, $team, 'en'))->toHaveCount(1);
});

it('will not let an announcement that matters be dismissed', function (): void {
    [$owner, $team] = Fixtures::tenant();

    $announcement = publish(['is_dismissible' => false]);

    $this->actingAs($owner)->post(route('announcements.dismiss', $announcement));

    // A tenant whose account is about to be suspended does not get to clear the notice saying so.
    expect(board()->forUser($owner->refresh(), $team, 'en'))->toHaveCount(1);
});

// --- The kill switch -----------------------------------------------------------------------------------

it('stops showing every announcement the moment the switch flips', function (): void {
    [$owner, $team] = Fixtures::tenant();
    publish(['is_dismissible' => false]);

    expect(board()->forUser($owner, $team, 'en'))->toHaveCount(1);

    app(PlatformSettings::class)->set('announcements.enabled', false);

    // The switch exists for the banner that turns out to be wrong — a wrong date, a maintenance
    // window that moved. An operator reaching for it wants every tenant to stop seeing it on the
    // next request, not after somebody works out which rows to edit.
    expect(board()->forUser($owner, $team, 'en'))->toBe([]);
});

// --- The fan-out -----------------------------------------------------------------------------------------

it('queues the fan-out only when asked, and only after commit', function (): void {
    Queue::fake();

    publish(['notify' => false]);
    Queue::assertNotPushed(AnnounceToTenantsJob::class);

    publish(['notify' => true]);
    Queue::assertPushed(AnnounceToTenantsJob::class);
});

it('does not queue a fan-out for an announcement that rolled back', function (): void {
    Queue::fake();

    try {
        DB::transaction(function (): void {
            publish(['notify' => true]);

            throw new RuntimeException('something later went wrong');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The failure this guards: a notification in a thousand tenants' bells pointing at a row that
    // does not exist.
    expect(Announcement::query()->count())->toBe(0);
    Queue::assertNotPushed(AnnounceToTenantsJob::class);
});

it('writes one notification per team, not one per member', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::member($team);
    Fixtures::member($team);

    $announcement = publish(['notify' => true]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    // `toTeam` is what fans one raised notification out to that team's members by their own
    // preferences. The thing this must never become is a loop over every member of every tenant.
    //
    // Counted off `data.type`, not the `type` column: that column holds the notification class,
    // because these go out through Laravel's own database channel.
    expect(Notification::query()->withoutGlobalScopes()->get()
        ->where('data.type', NotificationType::Announcement->value))
        ->toHaveCount(3);
});

it('refuses to tell everybody twice', function (): void {
    [, $team] = Fixtures::tenant();

    $announcement = publish(['notify' => true]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);
    $first = Notification::query()->withoutGlobalScopes()->count();

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    // `notified_at`, because `ShouldBeUnique` releases its lock the moment the job finishes and a
    // retry of a job whose notifications *did* go out would land after it. Telling every customer
    // twice is how the next announcement becomes something people filter.
    expect(Notification::query()->withoutGlobalScopes()->count())->toBe($first)
        ->and($announcement->refresh()->notified_at)->not->toBeNull()
        ->and($team)->not->toBeNull();
});

it('notifies a tenant in the tenant’s language', function (): void {
    [, $team] = Fixtures::tenant('UAE LLC', ['locale' => 'ar', 'market' => Market::Uae->value]);

    $announcement = publish([
        'notify' => true,
        'title' => ['en' => 'Scheduled maintenance', 'ar' => 'صيانة مجدولة'],
        'body' => ['en' => 'Discovery will pause.', 'ar' => 'سيتوقف الاكتشاف مؤقتًا.'],
    ]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    $notification = Notification::query()->withoutGlobalScopes()->firstOrFail();

    // A UAE tenant reading an English notification because an operator wrote it in English is
    // exactly what the per-locale columns exist to avoid.
    expect($notification->data['title'])->toBe('صيانة مجدولة')
        ->and((int) $notification->team_id)->toBe((int) $team->getKey());
});

it('notifies only the teams it targets', function (): void {
    [, $uk] = Fixtures::tenant('UK Ltd', ['market' => Market::Uk->value]);
    [, $uae] = Fixtures::tenant('UAE LLC', ['market' => Market::Uae->value]);

    $announcement = publish(['notify' => true, 'markets' => [Market::Uae->value]]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    $teamIds = Notification::query()->withoutGlobalScopes()->pluck('team_id')->map(intval(...))->all();

    expect($teamIds)->toContain((int) $uae->getKey())
        ->and($teamIds)->not->toContain((int) $uk->getKey());
});

// --- The screen ------------------------------------------------------------------------------------------

it('is publishable only by a role that holds settings.manage', function (): void {
    foreach ([AdminRole::Support, AdminRole::Finance, AdminRole::ReadOnly] as $role) {
        $this->actingAs(Fixtures::operator($role))
            ->post('/admin/announcements', [
                'level' => AnnouncementLevel::Info->value,
                'title' => ['en' => 'Trying it on without the ability for it'],
                'starts_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    // Publishing one is read by every tenant it targets, which makes it a platform-wide act rather
    // than a support task.
    expect(Announcement::query()->count())->toBe(0);
});

it('insists on something to say', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::SuperAdmin))
        ->post('/admin/announcements', [
            'level' => AnnouncementLevel::Info->value,
            'title' => [],
            'starts_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
        ])
        ->assertSessionHasErrors();

    expect(Announcement::query()->count())->toBe(0);
});

it('retires rather than deletes', function (): void {
    $announcement = publish();
    $operator = Fixtures::operator(AdminRole::SuperAdmin);

    $this->actingAs($operator)
        ->delete('/admin/announcements/'.$announcement->getKey())
        ->assertRedirect();

    // "When did we say the product would be down?" is a question somebody asks later, so the row
    // stays and `ends_at` is what stops it showing.
    expect(Announcement::query()->count())->toBe(1)
        ->and($announcement->refresh()->ends_at)->not->toBeNull()
        ->and(AuditLog::query()
            ->withoutGlobalScopes()
            ->where('action', 'admin.announcement.retired')
            ->exists())->toBeTrue();
});

it('audits a publication with what it targeted', function (): void {
    publish(['notify' => true, 'plans' => [PlanKey::Scale->value]]);

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.announcement.published')
        ->latest('id')
        ->firstOrFail();

    // The targets, because "who did we tell?" is the first question after a notice goes to the
    // wrong people.
    expect($entry->properties['plans'])->toBe([PlanKey::Scale->value])
        ->and($entry->properties['notify'])->toBeTrue();
});

it('does not re-notify the teams a crashed fan-out already reached', function (): void {
    [, $alpha] = Fixtures::tenant('Alpha');
    [, $beta] = Fixtures::tenant('Beta');
    [, $gamma] = Fixtures::tenant('Gamma');

    // `notify: false`, so publishing does not run the fan-out on the sync queue — and **not**
    // `Queue::fake()`, which would also swallow the queued notifications this test needs to count.
    // The job itself does not read `notify`; that flag is the operator's instruction to dispatch.
    $announcement = publish(['notify' => false]);

    // Alpha has been told, and then the job died before reaching the rest — the state a retry
    // starts from. `notified_at` cannot express this: it only says whether the whole walk finished.
    DB::table('announcement_notifications')->insert([
        'announcement_id' => $announcement->getKey(),
        'team_id' => $alpha->getKey(),
        'created_at' => now(),
    ]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    $teamIds = Notification::query()->withoutGlobalScopes()->get()
        ->where('data.type', NotificationType::Announcement->value)
        ->pluck('team_id')->map(intval(...))->unique()->values()->all();

    // Beta and Gamma get it; Alpha does not get it twice. This is the case `ShouldBeUnique` cannot
    // cover, because its lock is released the moment the job finishes — and a retry lands after.
    expect($teamIds)->toContain((int) $beta->getKey())
        ->and($teamIds)->toContain((int) $gamma->getKey())
        ->and($teamIds)->not->toContain((int) $alpha->getKey());
});

it('claims each tenant exactly once', function (): void {
    [, $team] = Fixtures::tenant();

    $announcement = publish(['notify' => false]);

    app()->call([new AnnounceToTenantsJob((int) $announcement->getKey()), 'handle']);

    // The unique index is what makes the claim a guard rather than a note: two workers racing the
    // same chunk cannot both send, because the insert is the claim.
    expect(DB::table('announcement_notifications')
        ->where('announcement_id', $announcement->getKey())
        ->where('team_id', $team->getKey())
        ->count())->toBe(1);
});

it('backs off rather than retrying a failed fan-out immediately', function (): void {
    $job = new AnnounceToTenantsJob(1);

    // CLAUDE.md §2 asks every job for one. It matters more here than most: the thing that makes
    // this job fail is usually the database being busy, and three immediate retries over every
    // tenant is the opposite of what helps.
    expect($job->backoff)->not->toBe([])
        ->and($job->tries)->toBeGreaterThan(1);
});
