<?php

declare(strict_types=1);

use App\Modules\AiInsights\Jobs\GeneratePeriodicInsightsJob;
use App\Modules\AiInsights\Models\InsightRun;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('ai-insights.generate_at_hour', 7);
});

/**
 * @return list<int>
 */
function queuedTeamIds(): array
{
    $ids = [];

    Queue::assertPushed(GeneratePeriodicInsightsJob::class, function (GeneratePeriodicInsightsJob $job) use (&$ids): bool {
        $ids[] = $job->teamId;

        return true;
    });

    sort($ids);

    return $ids;
}

/** A team that has already had its insights worked out on the given local date. */
function ranOn(Team $team, string $localDate): InsightRun
{
    return app(TeamContext::class)->run($team, fn (): InsightRun => InsightRun::factory()
        ->on($localDate)
        ->create(['team_id' => $team->getKey()]));
}

it('queues a run for a team whose local morning it is, and not for one whose isn’t', function (): void {
    $london = Team::factory()->create(['timezone' => 'Europe/London']);
    $dubai = Team::factory()->create(['timezone' => 'Asia/Dubai']);

    // 04:30 UTC is 05:30 in London (before 7) and 08:30 in Dubai (after 7). A single nightly run
    // at a server hour cannot get both of these right, which is why the sweep is hourly.
    Carbon::setTestNow('2026-06-10 04:30:00');

    $this->artisan('insights:generate')->assertSuccessful();

    expect(queuedTeamIds())->toBe([(int) $dubai->getKey()])
        ->and($london->fresh())->not->toBeNull();
});

it('still runs later in the day when the worker was down at the hour', function (): void {
    $team = Team::factory()->create(['timezone' => 'UTC']);

    // Not `== 7`: a member whose queue was down until eleven still wants the day's insights.
    Carbon::setTestNow('2026-06-10 11:00:00');

    $this->artisan('insights:generate')->assertSuccessful();

    expect(queuedTeamIds())->toBe([(int) $team->getKey()]);
});

it('does not queue a second run for a team that has already been done today', function (): void {
    $team = Team::factory()->create(['timezone' => 'UTC']);

    Carbon::setTestNow('2026-06-10 09:00:00');
    ranOn($team, '2026-06-10');

    $this->artisan('insights:generate')->assertSuccessful();

    Queue::assertNotPushed(GeneratePeriodicInsightsJob::class);
});

it('does not re-run a team whose insights came back empty', function (): void {
    $team = Team::factory()->create(['timezone' => 'UTC']);

    Carbon::setTestNow('2026-06-10 09:00:00');

    // A run that found nothing: no stale leads, nobody to call, a model with nothing to say. It is
    // still a day that has been done — and this is the case an earlier version got wrong, because
    // it asked the *insights* table whether the team was due and so re-ran it (and re-paid for the
    // aggregates and the AI call) every hour until midnight.
    app(TeamContext::class)->run($team, fn (): InsightRun => InsightRun::factory()
        ->on('2026-06-10')
        ->create(['team_id' => $team->getKey(), 'insights_created' => 0]));

    $this->artisan('insights:generate')->assertSuccessful();

    Queue::assertNotPushed(GeneratePeriodicInsightsJob::class);
});

it('queues again the next day', function (): void {
    $team = Team::factory()->create(['timezone' => 'UTC']);

    ranOn($team, '2026-06-09');

    Carbon::setTestNow('2026-06-10 07:30:00');

    $this->artisan('insights:generate')->assertSuccessful();

    expect(queuedTeamIds())->toBe([(int) $team->getKey()]);
});

it('reads the last run on the team’s calendar, not the server’s', function (): void {
    $team = Team::factory()->create(['timezone' => 'Asia/Dubai']);

    // 21:00 UTC on the 9th is 01:00 on the 10th in Dubai, so the run the generator stamped then is
    // stamped with the 10th — today for this team, and nothing new is due.
    ranOn($team, '2026-06-10');

    Carbon::setTestNow('2026-06-10 06:00:00'); // 10:00 in Dubai, same local day.

    $this->artisan('insights:generate')->assertSuccessful();

    Queue::assertNotPushed(GeneratePeriodicInsightsJob::class);
});

it('runs one team now when asked, whatever the hour and whether it has been done', function (): void {
    $team = Team::factory()->create(['timezone' => 'UTC']);
    Team::factory()->create(['timezone' => 'UTC']);

    Carbon::setTestNow('2026-06-10 02:00:00'); // Nobody's morning.
    ranOn($team, '2026-06-10');

    $this->artisan('insights:generate', ['--team' => (string) $team->getKey()])->assertSuccessful();

    expect(queuedTeamIds())->toBe([(int) $team->getKey()]);

    // And the job knows it is a forced run, which is what lets the generator skip the AI call it
    // has already paid for today.
    Queue::assertPushed(
        GeneratePeriodicInsightsJob::class,
        static fn (GeneratePeriodicInsightsJob $job): bool => $job->force === true,
    );
});

it('skips a team with an unusable timezone instead of failing the sweep', function (): void {
    $broken = Team::factory()->create(['timezone' => 'UTC']);
    $healthy = Team::factory()->create(['timezone' => 'UTC']);

    // Written past the model so no validation or cast gets in the way — the point is a row that is
    // already in the database. One bad row must not cost every team after it in the chunk its
    // insights.
    Team::query()->whereKey($broken->getKey())->update(['timezone' => 'Not/AZone']);

    Carbon::setTestNow('2026-06-10 09:00:00');

    $this->artisan('insights:generate')->assertSuccessful();

    expect(queuedTeamIds())->toBe([(int) $healthy->getKey()]);
});

it('queues nothing on a dry run', function (): void {
    Team::factory()->create(['timezone' => 'UTC']);

    Carbon::setTestNow('2026-06-10 08:00:00');

    $this->artisan('insights:generate', ['--dry-run' => true])
        ->expectsOutputToContain('Would queue 1')
        ->assertSuccessful();

    Queue::assertNotPushed(GeneratePeriodicInsightsJob::class);
});
