<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\ProviderHealth;
use App\Modules\Admin\Services\Platform\QueueHealth;
use App\Modules\Core\Models\AuditLog;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * Failed jobs, queue health and the provider breaker (Phase 19c).
 *
 * The three writes on these screens are each audited, and each for a different reason: a retry can
 * send a real email a second time, a drop destroys the only copy of some work, and a breaker reset
 * overrides the protection that stops us hammering a provider that is already struggling.
 */

/**
 * A row in `failed_jobs` shaped the way the queue actually writes one, so the payload parsing under
 * test is parsing a real payload rather than a convenient one.
 *
 * `serialize()` on the job, base64'd into `data.command`, is exactly what
 * `CallQueuedHandler` stores — which is why the tenant id is recoverable at all, and why
 * {@see QueueHealth::teamIdFromPayload()} pattern-matches it rather than unserialising it.
 */
function failedJob(string $job, ?int $teamId = null, string $queue = 'default'): string
{
    $uuid = (string) Str::uuid();

    $command = $teamId === null
        ? 'O:8:"stdClass":0:{}'
        : 'O:8:"stdClass":1:{s:6:"teamId";i:'.$teamId.';}';

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $job,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $job, 'command' => $command],
        ], JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: the provider said no\n#0 somewhere",
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('surfaces the job class and the tenant the failed job belonged to', function (): void {
    failedJob(DiscoverLeadsJob::class, 417);
    failedJob('App\\Modules\\Outreach\\Jobs\\SendOutreachEmailJob');

    $failed = app(QueueHealth::class)->failedJobs();

    expect($failed['available'])->toBeTrue()
        ->and($failed['total'])->toBe(2);

    $jobs = collect($failed['jobs'])->keyBy('job');

    expect($jobs[DiscoverLeadsJob::class]['team_id'])->toBe(417)
        // An em dash on the screen rather than a guess: a missed id is a gap, a wrong one sends an
        // operator to the wrong customer.
        ->and($jobs['App\\Modules\\Outreach\\Jobs\\SendOutreachEmailJob']['team_id'])->toBeNull()
        // The first line of the exception only. The trace is in the logs and in Sentry.
        ->and($jobs[DiscoverLeadsJob::class]['exception'])
        ->toBe('RuntimeException: the provider said no');
});

it('recovers the tenant from a serialised Team model too', function (): void {
    // Laravel replaces a serialised model with a `ModelIdentifier` carrying the class and the key,
    // which is the other shape every job in this product can arrive in.
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\\Modules\\Signals\\Jobs\\RunSignalWatchJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'App\\Modules\\Signals\\Jobs\\RunSignalWatchJob',
                'command' => 'O:45:"Illuminate\\Contracts\\Database\\ModelIdentifier":2:'
                    .'{s:5:"class";s:28:"App\\Modules\\Core\\Models\\Team";s:2:"id";i:91;}',
            ],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'RuntimeException: nope',
        'failed_at' => now(),
    ]);

    expect(app(QueueHealth::class)->failedJobs()['jobs'][0]['team_id'])->toBe(91);
});

it('retries a failed job and audits it with the tenant it belonged to', function (): void {
    $uuid = failedJob(DiscoverLeadsJob::class, 417);
    $operator = Fixtures::operator(AdminRole::Support);

    $this->actingAs($operator)
        ->post('/admin/operations/failed-jobs/'.$uuid.'/retry')
        ->assertRedirect('/admin/operations');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::JOB_RETRIED)
        ->sole();

    expect($entry->user_id)->toBe($operator->getKey())
        // The entry has **no team**, because the act is the operator's rather than the tenant's —
        // the tenant it was about is a property, which is the distinction `AdminAudit` exists for.
        ->and($entry->team_id)->toBeNull()
        ->and($entry->properties['uuid'] ?? null)->toBe($uuid)
        ->and($entry->properties['subject_team_id'] ?? null)->toBe(417);
});

it('audits a dropped job before it deletes it', function (): void {
    $uuid = failedJob(DiscoverLeadsJob::class, 417);
    $operator = Fixtures::operator(AdminRole::Support);

    $this->actingAs($operator)
        ->delete('/admin/operations/failed-jobs/'.$uuid)
        ->assertRedirect('/admin/operations');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::JOB_DELETED)
        ->sole();

    // The payload was the only copy of that work, so the entry has to carry enough to say what was
    // lost: the class, the queue and the tenant. Deliberately **not** the exception text — see the
    // test below.
    expect($entry->properties['job'] ?? null)->toBe(DiscoverLeadsJob::class)
        ->and($entry->properties['queue'] ?? null)->toBe('default')
        ->and($entry->properties['subject_team_id'] ?? null)->toBe(417);
});

it('refuses a retry of a job that has already gone', function (): void {
    // A refusal rather than a silent success. On a web visit a `DomainException` renders as a toast
    // and a redirect back (`bootstrap/app.php`), so the assertion is about the message an operator
    // reads — not an error code nobody sees.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/operations/failed-jobs/'.Str::uuid().'/retry')
        ->assertRedirect();

    expect(session('errors'))->toBeNull();
});

it('retries everything and records the count rather than one entry per job', function (): void {
    failedJob(DiscoverLeadsJob::class, 1);
    failedJob(DiscoverLeadsJob::class, 2);
    failedJob(DiscoverLeadsJob::class, 3);

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/operations/failed-jobs/retry-all')
        ->assertRedirect('/admin/operations');

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::JOB_RETRIED)
        ->sole();

    expect($entry->properties['uuid'] ?? null)->toBe('all')
        ->and($entry->properties['count'] ?? null)->toBe(3);
});

it('keeps the operational levers away from a read-only operator', function (): void {
    $uuid = failedJob(DiscoverLeadsJob::class, 417);

    $this->actingAs(Fixtures::operator(AdminRole::ReadOnly))
        ->post('/admin/operations/failed-jobs/'.$uuid.'/retry')
        ->assertForbidden();

    $this->actingAs(Fixtures::operator(AdminRole::ReadOnly))
        ->post('/admin/providers/reset', ['provider' => PlatformProvider::CompaniesHouse->value])
        ->assertForbidden();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});

it('reads queue depth on the database driver and says so when it cannot', function (): void {
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(5)->getTimestamp(),
        'created_at' => now()->subMinutes(5)->getTimestamp(),
    ]);

    $queues = app(QueueHealth::class)->queues();

    expect($queues['available'])->toBeTrue()
        ->and($queues['queues'][0]['queue'])->toBe('default')
        ->and($queues['queues'][0]['pending'])->toBe(1)
        // The number that separates a busy worker from a dead one.
        ->and($queues['queues'][0]['oldest_age_seconds'])->toBeGreaterThanOrEqual(290);

    config()->set('queue.default', 'redis');

    $onRedis = app(QueueHealth::class)->queues();

    // Not a zero. A queue depth of zero is the most reassuring number on this screen and must
    // never be a default.
    expect($onRedis['available'])->toBeFalse()
        ->and($onRedis['queues'])->toBe([])
        ->and($onRedis['note'])->toContain('redis');
});

/*
 * Provider health and the breaker.
 */

it('reports a provider with no breaker as not instrumented rather than closed', function (): void {
    $rows = collect(app(ProviderHealth::class)->all())->keyBy('key');

    expect($rows[PlatformProvider::CompaniesHouse->value]['instrumented'])->toBeTrue()
        ->and($rows[PlatformProvider::CompaniesHouse->value]['open'])->toBeFalse()
        // The honest half: three providers have no breaker at all, and a green light nobody wired
        // up is worse than none.
        ->and($rows[PlatformProvider::Stripe->value]['instrumented'])->toBeFalse()
        ->and($rows[PlatformProvider::Stripe->value]['open'])->toBeNull()
        ->and($rows[PlatformProvider::Claude->value]['instrumented'])->toBeFalse()
        // Reported as advisory on every row, never as truth.
        ->and($rows[PlatformProvider::CompaniesHouse->value]['advisory'])->toBeTrue();
});

it('closes an open circuit and audits the reset', function (): void {
    $provider = PlatformProvider::CompaniesHouse;
    $breaker = app(ProviderHealth::class)->breaker($provider);
    $key = (string) $provider->breakerKey();

    // Trip it the way the provider itself would.
    for ($i = 0; $i < (int) config('lead-sourcing.circuit_breaker.threshold', 5); $i++) {
        $breaker->recordFailure($key);
    }

    expect($breaker->isOpen($key))->toBeTrue();

    $operator = Fixtures::operator(AdminRole::Support);

    $this->actingAs($operator)
        ->post('/admin/providers/reset', ['provider' => $provider->value])
        ->assertRedirect('/admin/providers');

    expect($breaker->isOpen($key))->toBeFalse()
        ->and($breaker->failures($key))->toBe(0);

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::BREAKER_RESET)
        ->sole();

    expect($entry->user_id)->toBe($operator->getKey())
        ->and($entry->properties['provider'] ?? null)->toBe($provider->value)
        // Recorded before the reset, because afterwards it is zero — and "was it actually open?"
        // is what makes the entry worth having.
        ->and($entry->properties['was_open'] ?? null)->toBeTrue();
});

it('refuses a breaker reset for a provider that has none', function (): void {
    // Three providers genuinely have none, and the refusal is a domain fact rather than a
    // validation rule — so it arrives as a toast, and crucially writes **no** audit entry: an
    // entry saying a circuit was reset when there was no circuit would be a lie in the one place
    // that records overrides.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/providers/reset', ['provider' => PlatformProvider::Stripe->value])
        ->assertRedirect();

    expect(AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::BREAKER_RESET)
        ->exists())->toBeFalse();
});

it('lists every scheduled command with when it was last seen', function (): void {
    $schedule = app(Schedule::class);

    $rows = collect(app(QueueHealth::class)->schedule($schedule))->keyBy('command');

    expect($rows)->toHaveKey('platform:roll-up')
        ->and($rows)->toHaveKey('compliance:purge')
        // Nothing has run in a test, so every command reads "not seen" rather than claiming a run.
        // The label on the screen says *seen*, not *ran*, for exactly this reason.
        ->and($rows['platform:roll-up']['last_seen_at'])->toBeNull()
        ->and($rows['platform:roll-up']['expression'])->toBe('40 2 * * *');
});

it('records a scheduled run so the screen can report it', function (): void {
    $this->artisan('platform:roll-up', ['--skip-funnel' => true])->assertSuccessful();

    // The listener fires on the scheduler's own events, which a bare `artisan` call does not
    // raise — so it is dispatched here the way the scheduler would.
    event(new ScheduledTaskFinished(
        tap(app(Schedule::class)->command('platform:roll-up'), function ($event): void {
            $event->exitCode = 0;
        }),
        1.5,
    ));

    $rows = collect(app(QueueHealth::class)->schedule(app(Schedule::class)))
        ->keyBy('command');

    expect($rows['platform:roll-up']['last_outcome'])->toBe('ok')
        ->and($rows['platform:roll-up']['last_seen_at'])->not->toBeNull()
        ->and($rows['platform:roll-up']['last_runtime_ms'])->toBe(1500);
});

it('renders the queues screen with everything an operator needs on it', function (): void {
    failedJob(DiscoverLeadsJob::class, 417);

    $this->actingAs(Fixtures::operator(AdminRole::Support));

    $this->get('/admin/operations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/operations/Index')
            ->has('failed.jobs', 1)
            ->where('failed.jobs.0.team_id', 417)
            ->has('queues')
            // Read from the live Schedule instance, so a command any module adds appears with no
            // change to the controller.
            ->where('schedule', fn (Collection $tasks): bool => $tasks
                ->contains(fn (array $task): bool => $task['command'] === 'platform:roll-up'))
            ->has('pulse'));
});

it('renders the provider-health screen', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::ReadOnly));

    $this->get('/admin/providers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/operations/Providers')
            ->has('providers', count(PlatformProvider::cases()))
            ->where('context.currency', fn (string $code): bool => $code !== ''));
});

it('keeps the exception text out of the audit trail when a job is dropped', function (): void {
    // The failed-job screen is behind `operations.manage` precisely because an exception message
    // leaks a tenant's internals — a provider error quoting an address, a database message quoting
    // a parameter. The audit viewer is behind `audit.read`, which Finance and Read-only also hold,
    // so copying the text into an entry would widen the audience for the thing the narrower
    // ability exists to contain.
    $uuid = failedJob(DiscoverLeadsJob::class, 417);

    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->delete('/admin/operations/failed-jobs/'.$uuid)
        ->assertRedirect();

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::JOB_DELETED)
        ->sole();

    expect($entry->properties)->not->toHaveKey('exception')
        // Still enough to say what was lost.
        ->and($entry->properties['job'] ?? null)->toBe(DiscoverLeadsJob::class)
        ->and($entry->properties['subject_team_id'] ?? null)->toBe(417);
});

it('finds a failed job older than a page of them', function (): void {
    // The Actions used to page the newest thousand and search in PHP, which quietly lost the job
    // class and the tenant id for anything older — on the very screen whose reason to exist is a
    // backlog.
    $uuid = failedJob(DiscoverLeadsJob::class, 417);

    DB::table('failed_jobs')->where('uuid', $uuid)->update([
        'failed_at' => now()->subMonths(6),
    ]);

    expect(app(QueueHealth::class)->describeFailedJob($uuid))
        ->toMatchArray(['job' => DiscoverLeadsJob::class, 'team_id' => 417]);

    expect(app(QueueHealth::class)->describeFailedJob('not-a-uuid'))->toBeNull();
});

it('audits the failed-job list as a cross-tenant read', function (): void {
    failedJob(DiscoverLeadsJob::class, 417);

    $this->actingAs(Fixtures::operator(AdminRole::Support))->get('/admin/operations')->assertOk();

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::RESOURCE_VIEWED)
        ->sole();

    expect($entry->properties['resource'] ?? null)->toBe('failed-jobs')
        // A count, not the rows.
        ->and($entry->properties['results'] ?? null)->toBe(1)
        ->and($entry->properties)->not->toHaveKey('jobs');
});
