<?php

declare(strict_types=1);

use App\Modules\Compliance\Jobs\PurgeExpiredDataJob;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The scheduled entry point (`compliance:purge`, docs/compliance.md §3.4).
 *
 * The command deliberately does almost nothing itself: it fans out to one job per team, so one
 * team's backlog cannot hold up everybody else's promise. The exceptions are `--dry-run` and
 * `--sync`, where a human is waiting for the answer.
 */
it('queues one sweep per team', function (): void {
    Queue::fake();

    [, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    $this->artisan('compliance:purge')->assertSuccessful();

    Queue::assertPushed(PurgeExpiredDataJob::class, 2);

    foreach ([$teamA, $teamB] as $team) {
        Queue::assertPushed(
            PurgeExpiredDataJob::class,
            fn (PurgeExpiredDataJob $job): bool => $job->teamId === (int) $team->getKey(),
        );
    }
});

it('sweeps one team when told to', function (): void {
    Queue::fake();

    [, $teamA] = Fixtures::team();
    Fixtures::team();

    $this->artisan('compliance:purge', ['--team' => (string) $teamA->getKey()])->assertSuccessful();

    Queue::assertPushed(PurgeExpiredDataJob::class, 1);
});

it('runs inline for a dry run and queues nothing', function (): void {
    Queue::fake();

    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    $this->artisan('compliance:purge', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 lead(s)')
        ->assertSuccessful();

    // Retention is irreversible, and a dry run that queued jobs would print nothing useful.
    Queue::assertNothingPushed();

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
});

it('sweeps inline with --sync', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    $this->artisan('compliance:purge', ['--sync' => true])->assertSuccessful();

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('reports the teams it left alone rather than counting them as clean', function (): void {
    [, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => RetentionPolicy::factory()
        ->disabled()
        ->create(['team_id' => $team->getKey()]));

    $this->artisan('compliance:purge', ['--sync' => true])
        ->expectsOutputToContain('1 team(s) have retention switched off')
        ->assertSuccessful();
});

it('does the work when the queued job runs, and is unique per team', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::retention($team, uncontactedDays: 30);

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(['team_id' => $team->getKey()]));
    Fixtures::ageLead($lead, now()->subDays(90));

    $job = new PurgeExpiredDataJob((int) $team->getKey());

    // Two sweeps racing over the same leads would have one deleting rows the other had taken.
    expect($job->uniqueId())->toBe('compliance-purge:'.$team->getKey());

    app()->call([$job, 'handle']);

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('does nothing when the team was deleted between dispatch and running', function (): void {
    $job = new PurgeExpiredDataJob(999_999);

    app()->call([$job, 'handle']);
})->throwsNoExceptions();
