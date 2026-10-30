<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Notifications\Models\NotificationSetting;
use App\Modules\Notifications\Services\DigestBuilder;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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

    // The settings row cascades on the user, not the membership, so it survives their leaving.
    $team->users()->detach($user->getKey());

    $this->artisan('notifications:digest')->assertSuccessful();

    Mail::assertNothingQueued();
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

afterEach(function (): void {
    Carbon::setTestNow();
});
