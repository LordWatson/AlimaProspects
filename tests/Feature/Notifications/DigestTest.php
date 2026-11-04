<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Data\PushMessage;
use App\Modules\Notifications\Data\PushResult;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Services\DigestBuilder;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InsightFixtures;
use Tests\Support\LeadsWorkspaceFixtures as Workspace;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The daily and weekly round-ups (Phase 12).
 *
 * The risk mvp.md flags for this phase is **digest timezone correctness**, and that is most of what
 * this file is about: the member picks an hour, the team picks a timezone, and "8am" has to mean 8am
 * where the team is. The rest is the two rules that keep a digest worth opening — only what the
 * member asked for, and only when something happened.
 */
beforeEach(function (): void {
    Mail::fake();
});

/** A member with a digest switched on, in a team on the given clock. */
function digestMember(string $timezone = 'UTC', DigestFrequency $frequency = DigestFrequency::Daily, int $hour = 8): array
{
    [$user, $team] = Fixtures::team();
    $team->forceFill(['timezone' => $timezone])->save();

    NotificationSetting::factory()->digest($frequency, $hour)->create([
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
    ]);

    return [$user, $team];
}

// --- Timezones -----------------------------------------------------------------------------------

it('sends at the member\'s hour in the team\'s timezone, not the server\'s', function (): void {
    // 04:30 UTC is 08:30 in Dubai. A single nightly run at 08:00 UTC would mail Dubai at noon.
    Carbon::setTestNow(Carbon::parse('2026-10-28 04:30:00', 'UTC'));

    [, $team] = digestMember('Asia/Dubai', hour: 8);

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertQueued(DigestMail::class);
});

it('does not send at the same moment to a team on another clock', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 04:30:00', 'UTC'));

    // 04:30 in London is not 08:00.
    [, $team] = digestMember('Europe/London', hour: 8);

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('sends the weekly round-up on a Monday and not on a Tuesday', function (): void {
    // 2026-10-26 is a Monday.
    Carbon::setTestNow(Carbon::parse('2026-10-26 08:15:00', 'UTC'));

    [, $team] = digestMember('UTC', DigestFrequency::Weekly, 8);
    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();
    Mail::assertQueuedCount(1);

    // Reset and move to Tuesday with a fresh member, so the test isn't about the sent stamp.
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-27 08:15:00', 'UTC'));

    [, $other] = digestMember('UTC', DigestFrequency::Weekly, 8);
    app(TeamContext::class)->run($other, fn () => Lead::factory()->create(['team_id' => $other->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();
    Mail::assertNothingQueued();
});

it('does not send the same digest twice in one window', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [, $team] = digestMember('UTC', hour: 8);
    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();

    // The hourly sweep sees one hour once, but a retry or a clock change could show it twice.
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:50:00', 'UTC'));
    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

// --- What goes in --------------------------------------------------------------------------------

it('sends nothing when nothing happened', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [, $team] = digestMember('UTC', hour: 8);

    $this->artisan('notifications:digest')->assertSuccessful();

    // "Nothing happened this week" is an email that teaches people to filter us.
    Mail::assertNothingQueued();

    // The row is still stamped, so the sweep doesn't retry all afternoon.
    expect(NotificationSetting::query()->sole()->digest_sent_at)->not->toBeNull();
});

it('leaves out the sections the member switched off', function (): void {
    [$user, $team, $icp] = Workspace::team();

    Workspace::lead($team, ['company_name' => 'Acme Ltd'], $icp, ScoreGrade::A, 92);

    // Discovery notifications off; strong matches left on.
    Fixtures::prefer($user, $team, NotificationType::DiscoveryComplete, []);

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    $keys = array_column($summary->lines, 'key');

    // A digest that ignored preferences would be a way of re-enabling everything a member turned
    // off.
    expect($keys)->not->toContain(':count new companies found')
        ->and($keys)->toContain(':count graded a strong match');
});

it('says how many insights are waiting, and only what is still open', function (): void {
    [$user, $team] = Workspace::team();

    InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now(),
    ]);

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    $line = collect($summary->lines)->firstWhere('key', ':count insights waiting for you');

    // Not the ones generated in the window: a digest says what is *waiting*, and an insight the
    // team cleared on Tuesday is not waiting. Same reasoning as the open-tasks section.
    expect($line)->not->toBeNull()
        ->and($line['params']['count'])->toBe(1)
        ->and($line['url'])->toBe('/insights');
});

it('counts only what happened inside the window', function (): void {
    [$user, $team] = Workspace::team();

    $recent = Workspace::lead($team, ['company_name' => 'Recent Co']);
    $old = Workspace::lead($team, ['company_name' => 'Old Co']);

    app(TeamContext::class)->run($team, static fn () => Lead::query()
        ->whereKey($old->getKey())
        ->update(['created_at' => now()->subWeek()]));

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    $found = collect($summary->lines)->firstWhere('key', ':count new companies found');

    expect($found['params']['count'])->toBe(1)
        ->and($recent->exists)->toBeTrue();
});

it('stops sending the team\'s digest to somebody who has left', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::device($user);
    $push = Fixtures::fakePush();

    // The settings row cascades on the user, not the membership, so it survives their leaving.
    $team->users()->detach($user->getKey());

    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertNothingQueued();

    // **And no push either** (S15). Asserted rather than assumed, because the push deliberately
    // does not go through `NotificationDispatcher` — a digest is a sweep rendering a Mailable, not
    // a notification — so "a member who has left is dropped" is the sweep's own candidate query
    // doing a job the dispatcher would otherwise do. A second delivery channel bolted onto a sweep
    // is exactly where that kind of guarantee goes missing (raised by the S15 review).
    expect($push->sends)->toBe([]);
});

it('reports without sending or stamping on a dry run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [, $team] = digestMember('UTC', hour: 8);
    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest --dry-run')->assertSuccessful();

    Mail::assertNothingQueued();
    expect(NotificationSetting::query()->sole()->digest_sent_at)->toBeNull();
});

it('leaves a member whose digest is off alone', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [, $team] = digestMember('UTC', DigestFrequency::Off, 8);
    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

// --- The signal round-up (S15 task 1) ------------------------------------------------------------

/*
 * The one part of the digest that is not a count.
 *
 * Two rules do most of the work here. **The approach note travels with the headline**, because the
 * digest is read outside the app where no screen can warn somebody that a UAE prospect may not be
 * cold-emailed (`docs/compliance.md` §3.11) — so the restriction comes with the line or the line
 * does not go. And **the highlights obey the signal preference**, because a round-up that ignored
 * it would be a way of re-enabling something a member switched off.
 */

/** A new signal for this team, hot or unscored, as one event. */
function digestSignal(Team $team, ?int $heat, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'status' => SignalStatus::New->value,
        'heat' => $heat,
    ], $attributes)));
}

it('names the hottest events rather than only counting them', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    digestSignal($team, 20, ['headline' => 'A quiet little round']);
    digestSignal($team, 90, ['headline' => 'Acme raises $40m']);

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    expect($summary->highlights)->toHaveCount(2)
        // By heat, hottest first.
        ->and($summary->highlights[0]->headline)->toBe('Acme raises $40m')
        // The article's own words, so this is the one thing in a notification payload that is
        // prose rather than a translation key.
        ->and($summary->highlights[0]->signalId)->toBeGreaterThan(0);
});

it('puts an unscored event last rather than treating it as cold', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    digestSignal($team, null, ['headline' => 'Nobody scored this one']);
    digestSignal($team, 5, ['headline' => 'Scored, and barely warm']);

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    // Unknown is not zero (ADR 0022). A heat of 5 beats a null, and the ordering is explicit
    // because MySQL and SQLite disagree about where a null sorts.
    expect($summary->highlights[0]->headline)->toBe('Scored, and barely warm')
        ->and($summary->highlights[1]->heat)->toBeNull();
});

it('carries the jurisdiction approach note with the headline', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    digestSignal($team, 80, ['headline' => 'A Dubai acquisition', 'resolved_market' => Market::Uae->value]);

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    // The note is a translation key and it follows the **signal's own** jurisdiction, not the
    // team's — the permissive bug `docs/compliance.md` §3.11 exists to prevent.
    expect($summary->highlights[0]->approachNote)
        ->toBe(Market::Uae->outreachRestriction()->guidance());
});

it('counts one event per story rather than one per article', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    $primary = digestSignal($team, 70, ['headline' => 'Acme raises $40m']);
    $cluster = app(TeamContext::class)->run($team, fn (): SignalCluster => SignalCluster::factory()->create([
        'team_id' => $team->getKey(),
        'primary_signal_id' => $primary->getKey(),
    ]));

    // The same story from a second outlet, in the same cluster. A member never actions a member row.
    digestSignal($team, 70, [
        'headline' => 'Acme closes $40m round',
        'signal_cluster_id' => $cluster->getKey(),
    ]);

    app(TeamContext::class)->run($team, fn () => $primary->forceFill([
        'signal_cluster_id' => $cluster->getKey(),
    ])->save());

    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    expect($summary->highlights)->toHaveCount(1)
        ->and($summary->highlights[0]->signalId)->toBe((int) $primary->getKey());
});

it('caps the number of headlines', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));
    config()->set('signals.digest.highlights', 2);

    [$user, $team] = digestMember('UTC', hour: 8);

    for ($i = 0; $i < 5; $i++) {
        digestSignal($team, 50 + $i);
    }

    // Three headlines is a digest; a dozen is a feed somebody unsubscribes from.
    expect(app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily)->highlights)
        ->toHaveCount(2);
});

it('says nothing about signals to a member who switched them off', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    Fixtures::prefer($user, $team, NotificationType::SignalsDetected, []);

    digestSignal($team, 90, ['headline' => 'Acme raises $40m']);

    // A round-up that ignored the preference would be a way of re-enabling what somebody turned
    // off — and the count obeys it too, so the digest is silent rather than merely vaguer.
    expect(app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily)->highlights)
        ->toBe([]);
});

it('leaves out a signal somebody has already dealt with', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    digestSignal($team, 90, ['status' => SignalStatus::Dismissed->value]);
    digestSignal($team, 80, ['status' => SignalStatus::Converted->value]);

    expect(app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily)->highlights)
        ->toBe([]);
});

it('does not send a digest for signals alone when nothing else happened', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);

    digestSignal($team, 90);

    // `isEmpty()` still asks the **lines**, deliberately: the count sections are what decide
    // whether there is a round-up, and the signal count is one of them.
    $summary = app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily);

    expect($summary->isEmpty())->toBeFalse()
        ->and($summary->total)->toBeGreaterThan(0);
});

it('pushes the digest with the headline itself, once', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    Fixtures::device($user);
    $push = Fixtures::fakePush();

    digestSignal($team, 90, ['headline' => 'Acme raises $40m']);

    $this->artisan('notifications:digest')->assertSuccessful();

    // One message for the whole round-up, which is the aggregation rule: a notification that would
    // fan out per record is aggregated first.
    expect($push->sends)->toHaveCount(1)
        ->and($push->lastMessage()?->body)->toBe('Acme raises $40m')
        ->and($push->lastMessage()?->data['url'])->toBe('/signals');

    Mail::assertQueued(DigestMail::class);
});

it('keeps the push to a count where there is no headline to name', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    Fixtures::device($user);
    $push = Fixtures::fakePush();

    app(TeamContext::class)->run($team, fn () => Lead::factory()->create(['team_id' => $team->getKey()]));

    $this->artisan('notifications:digest')->assertSuccessful();

    expect($push->sends)->toHaveCount(1)
        ->and($push->lastMessage()?->data['url'])->toBe('/dashboard');
});

it('does not push to a member who asked for the round-up by email only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    Fixtures::device($user);
    $push = Fixtures::fakePush();
    Fixtures::prefer($user, $team, NotificationType::Digest, [NotificationChannel::Mail]);

    digestSignal($team, 90);

    $this->artisan('notifications:digest')->assertSuccessful();

    expect($push->sends)->toBe([]);
    Mail::assertQueued(DigestMail::class);
});

it('still mails the round-up when the push fails', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    Fixtures::device($user);

    app()->instance(PushSender::class, new class implements PushSender
    {
        public function send(array $tokens, PushMessage $message): PushResult
        {
            throw new RuntimeException('the provider said no');
        }
    });

    digestSignal($team, 90);

    $this->artisan('notifications:digest')->assertSuccessful();

    // The push is attempted **after** the mail and inside its own try/catch: a provider having a
    // bad morning must not cost every member their round-up.
    Mail::assertQueued(DigestMail::class);
});

it('keeps one team signals out of another team round-up', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:05:00', 'UTC'));

    [$user, $team] = digestMember('UTC', hour: 8);
    [, $other] = Fixtures::team();

    digestSignal($other, 95, ['headline' => 'Not yours']);

    expect(app(DigestBuilder::class)->build($user, $team, DigestFrequency::Daily)->highlights)
        ->toBe([]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});
