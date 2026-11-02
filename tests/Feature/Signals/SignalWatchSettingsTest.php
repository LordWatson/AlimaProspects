<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Event::fake();
    // "Run now" is throttled per watch; a shared limiter would leak between tests.
    RateLimiter::clear('signals:run-now:1');
});

it('lists the team’s watches with their recent runs and options', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $watch = SignalWatch::factory()
            ->watchingPresets(SignalPresetCatalogue::keys())
            ->create([
                'team_id' => $team->getKey(),
                'name' => 'Dubai wealth events',
                'last_run_at' => now()->subHours(3),
            ]);

        SignalRun::factory()->completed()->count(2)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('signal-watches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/Watches')
            ->has('watches', 1)
            ->where('watches.0.name', 'Dubai wealth events')
            ->where('watches.0.signals_count', 1)
            // A plain list, not a `{ data: [...] }` wrapper — the screen reads `watch.runs.length`.
            ->has('watches.0.runs', 2)
            // The watched types arrive as full definitions, so the checkboxes have their
            // explainers without the client fetching the library (S7, ADR 0018).
            ->has('watches.0.definitions', 3)
            ->has('watches.0.definitions.0.description')
            ->where('watches.0.definitions.0.is_preset', false)
            // Computed from the cadence, not stored.
            ->where('watches.0.next_due_at', fn (?string $due): bool => $due !== null)
            ->has('options.definitions', 3)
            ->has('options.cadences', 2)
            ->has('defaults.location')
            ->where('can.manage', true));
});

it('leaves next-due empty for a watch that has never run or is paused', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        SignalWatch::factory()->create(['team_id' => $team->getKey(), 'last_run_at' => null]);
        SignalWatch::factory()->inactive()->create(['team_id' => $team->getKey(), 'last_run_at' => now()]);
    });

    $this->actingAs($user)
        ->get(route('signal-watches.index'))
        ->assertOk()
        // A watch that has never run is due now; a paused one isn't due at all. Neither has a date.
        ->assertInertia(fn ($page) => $page
            ->where('watches.0.next_due_at', null)
            ->where('watches.1.next_due_at', null));
});

it('creates a watch and audits it', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $this->actingAs($user)
        ->post(route('signal-watches.store'), [
            'name' => 'Dubai founders',
            'location' => 'Dubai',
            'signal_definition_ids' => signalDefinitionIds($team, [
                SignalPresetCatalogue::ACQUISITION,
                SignalPresetCatalogue::FUNDING_ROUND,
            ]),
            'cadence' => 'weekly',
            'is_active' => true,
        ])
        ->assertRedirect(route('signal-watches.index'));

    $watch = SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($watch->name)->toBe('Dubai founders')
        // The types live in the pivot since S7, so this is a relation rather than a column.
        ->and($watch->eventKeys())->toBe([
            SignalPresetCatalogue::ACQUISITION,
            SignalPresetCatalogue::FUNDING_ROUND,
        ])
        ->and($watch->cadence)->toBe(SignalWatchCadence::Weekly)
        ->and($watch->is_active)->toBeTrue()
        // v1 is UAE-only: the market is set by the DTO, not taken from the form.
        ->and($watch->market)->toBe(Market::Uae)
        // Never run, so the hourly scheduler treats it as due and picks it up within the hour.
        ->and($watch->last_run_at)->toBeNull();

    expect(AuditLog::withoutGlobalScopes()->where('action', 'signals.watch_created')->count())->toBe(1);
});

it('ignores a market posted by a tampered form', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A UK watch would have no query templates and would silently find nothing, so the posted
    // value is ignored rather than honoured.
    $this->actingAs($user)
        ->post(route('signal-watches.store'), [
            'name' => 'London founders',
            'location' => 'London',
            'signal_definition_ids' => signalDefinitionIds($team, [SignalPresetCatalogue::ACQUISITION]),
            'cadence' => 'daily',
            'market' => 'uk',
        ])
        ->assertRedirect();

    expect(SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->sole()->market)
        ->toBe(Market::Uae);
});

it('creates the one-click default watch for a team with none', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $this->actingAs($user)
        ->post(route('signal-watches.store-default'))
        ->assertRedirect(route('signal-watches.index'));

    $watch = SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // Deliberately the broadest useful watch: a first watch that finds nothing teaches the team
    // that Signals doesn't work.
    //
    // Since S7 this also has to **clone the library presets first** - the team had no signal types
    // a moment ago, and a watch is built from theirs (ADR 0018). That is what keeps the shortest
    // path one click instead of making them assemble a library before they can look at anything.
    expect($watch->location)->toBe('Dubai')
        ->and($watch->eventKeys())->toBe(SignalPresetCatalogue::keys())
        ->and($watch->cadence)->toBe(SignalWatchCadence::Daily)
        ->and($watch->is_active)->toBeTrue();

    $definitions = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->get();

    expect($definitions)->toHaveCount(3)
        // Clones, carrying the provenance that lets S15 offer the diff when a preset moves on.
        ->and($definitions->pluck('preset_key')->sort()->values()->all())
        ->toBe(SignalPresetCatalogue::keys());
});

it('validates a watch', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signal-watches.store'), [
            'name' => '', 'location' => '', 'signal_definition_ids' => [], 'cadence' => 'hourly',
        ])
        ->assertSessionHasErrors(['name', 'location', 'signal_definition_ids', 'cadence']);
});

it('refuses an ICP from another team', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $foreign = app(TeamContext::class)->run($otherTeam, fn (): Icp => Icp::factory()->create([
        'team_id' => $otherTeam->getKey(),
    ]));

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signal-watches.store'), [
            'name' => 'Dubai',
            'location' => 'Dubai',
            'signal_definition_ids' => signalDefinitionIds(
                $user->currentTeam()->firstOrFail(),
                [SignalPresetCatalogue::ACQUISITION],
            ),
            'cadence' => 'daily',
            'icp_id' => $foreign->getKey(),
        ])
        ->assertSessionHasErrors('icp_id');
});

it('updates a watch without making a reactivated one instantly due', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->inactive()->create([
        'team_id' => $team->getKey(),
        'last_run_at' => now()->subMonth(),
    ]));

    $this->actingAs($user)
        ->patch(route('signal-watches.update', ['watch' => $watch->getKey()]), [
            'name' => 'Back on',
            'location' => 'Dubai',
            'signal_definition_ids' => signalDefinitionIds($team, [SignalPresetCatalogue::FUNDING_ROUND]),
            'cadence' => 'daily',
            'is_active' => true,
        ])
        ->assertRedirect(route('signal-watches.index'));

    $watch->refresh();

    // A watch paused for a month must not fire the moment it is switched back on — that is a
    // surprise bill, not a feature. "Run now" exists for when that is what the member wants.
    expect($watch->name)->toBe('Back on')
        ->and($watch->is_active)->toBeTrue()
        ->and($watch->last_run_at?->toDateString())->toBe(now()->subMonth()->toDateString());
});

it('deletes a watch and says what went with it', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, function () use ($team): SignalWatch {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        // The run is created for *this* watch, so the whole graph hangs off the row being deleted
        // (the Signal factory would otherwise mint a run — and a second watch — of its own).
        $run = SignalRun::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        Signal::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
        ]);

        return $watch;
    });

    $this->actingAs($user)
        ->delete(route('signal-watches.destroy', ['watch' => $watch->getKey()]))
        ->assertRedirect(route('signal-watches.index'));

    // The signals and runs go with the watch (both FKs cascade) — and the audit row is the only
    // place those counts survive the delete.
    expect(SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0)
        ->and(Signal::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0)
        ->and(SignalRun::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0);

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'signals.watch_deleted')->sole();

    expect($audit->properties['signals_deleted'])->toBe(2);
});

it('runs a watch now and queues the job', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($user)
        ->from(route('signal-watches.index'))
        ->post(route('signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertRedirect(route('signal-watches.index'));

    Queue::assertPushed(
        RunSignalWatchJob::class,
        fn (RunSignalWatchJob $job): bool => $job->watchId === (int) $watch->getKey(),
    );
});

it('tells the member when a watch was run too recently', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($user)->post(route('signal-watches.run', ['watch' => $watch->getKey()]));

    // A run costs money, so the second press inside 15 minutes comes back as a friendly toast.
    $this->actingAs($user)
        ->from(route('signal-watches.index'))
        ->post(route('signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertRedirect(route('signal-watches.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    Queue::assertPushed(RunSignalWatchJob::class, 1);
});

it('refuses to run a paused watch', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->inactive()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($user)
        ->from(route('signal-watches.index'))
        ->post(route('signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertRedirect(route('signal-watches.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    Queue::assertNothingPushed();
});

it('stops a read-only member managing or running watches', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->switchTeam($team);

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($readOnly)->get(route('signal-watches.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($readOnly)->post(route('signal-watches.store'), [
        'name' => 'Nope', 'location' => 'Dubai',
        'signal_definition_ids' => signalDefinitionIds($team, [SignalPresetCatalogue::ACQUISITION]),
        'cadence' => 'daily',
    ])->assertForbidden();

    $this->actingAs($readOnly)
        ->delete(route('signal-watches.destroy', ['watch' => $watch->getKey()]))
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->post(route('signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('404s on another team’s watch rather than admitting it exists', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $watch = app(TeamContext::class)->run($otherTeam, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $otherTeam->getKey(),
    ]));

    $user = createUserWithTeam();

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($user)
        ->patch(route('signal-watches.update', ['watch' => $watch->getKey()]), [
            'name' => 'Mine now', 'location' => 'Dubai',
            'signal_definition_ids' => signalDefinitionIds($user->currentTeam()->firstOrFail(), [SignalPresetCatalogue::ACQUISITION]),
            'cadence' => 'daily',
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('signal-watches.destroy', ['watch' => $watch->getKey()]))
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertNotFound();

    expect(SignalWatch::withoutGlobalScopes()->count())->toBe(1);
});

it('requires a signed-in member', function (): void {
    $this->get(route('signal-watches.index'))->assertRedirect(route('login'));
});

it('leaves a queued run on the screen the moment a member presses Run now', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());

    $this->actingAs($user)->post(route('signal-watches.run', ['watch' => $watch->getKey()]));

    // The queue is faked, so this is exactly what a member sees while waiting for a worker: a run
    // the card can report on, instead of a screen that looks like the press did nothing.
    $this->actingAs($user)
        ->get(route('signal-watches.index'))
        ->assertInertia(fn ($page) => $page
            ->where('watches.0.runs.0.status', 'queued')
            ->where('watches.0.runs.0.signals_created', 0)
            ->where('watches.0.runs.0.started_at', null));
});
