<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Jobs\RunSignalWatchJob;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Event::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function apiSignal(Team $team, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $attributes): Signal {
        $watch = SignalWatch::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatch::factory()->create(['team_id' => $team->getKey(), 'name' => 'Dubai watch']);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            ...$attributes,
        ]);
    });
}

// --- Signals ------------------------------------------------------------------------------------

it('lists a team’s signals, paginated', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    apiSignal($team);

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signals.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'new')
        ->assertJsonStructure(['data', 'links', 'meta'])
        // A signal holds no contact details, so none can be serialised (ADR 0011) — and `raw` (the
        // clamped Serper payload) never leaves the server.
        ->assertJsonMissingPath('data.0.email')
        ->assertJsonMissingPath('data.0.phone')
        ->assertJsonMissingPath('data.0.raw');
});

it('filters the API list the same way the feed does', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    apiSignal($team, ['relevance' => 90]);
    apiSignal($team, ['relevance' => 10]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signals.index', ['min_relevance' => 50]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.relevance', 90);
});

it('shows one signal with its watch and lead', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = apiSignal($team);

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signals.show', ['signal' => $signal->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.id', $signal->id)
        ->assertJsonPath('data.watch.name', 'Dubai watch')
        ->assertJsonPath('data.lead', null);
});

it('converts a signal and answers with the lead it made', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = apiSignal($team);

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signals.convert', ['signal' => $signal->getKey()]), [
        'company_name' => 'Acme DMCC',
        'person_name' => 'Jane Founder',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('data.lead.company_name', 'Acme DMCC');

    $lead = Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($lead->source)->toBe(LeadSource::Signal)
        ->and($lead->contacts()->withoutGlobalScopes()->sole()->email)->toBeNull();
});

it('validates a conversion', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = apiSignal($team);

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signals.convert', ['signal' => $signal->getKey()]), ['company_name' => ''])
        ->assertStatus(422)
        ->assertApiValidationErrors('company_name');
});

it('dismisses and restores through the API', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = apiSignal($team);

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signals.dismiss', ['signal' => $signal->getKey()]), ['reason' => 'not_relevant'])
        ->assertOk()
        ->assertJsonPath('data.status', 'dismissed')
        ->assertJsonPath('data.dismiss_reason', 'not_relevant');

    $this->postJson(route('api.v1.signals.restore', ['signal' => $signal->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.dismiss_reason', null);
});

it('answers a domain error with the JSON envelope and its status', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = apiSignal($team, ['status' => SignalStatus::Converted]);

    Sanctum::actingAs($user, ['*']);

    // Where the web gets a redirect and a toast, the API gets the envelope — 409 for a signal
    // that has already become a lead.
    $this->postJson(route('api.v1.signals.dismiss', ['signal' => $signal->getKey()]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'signals.already_converted');
});

it('requires a token', function (): void {
    $this->getJson(route('api.v1.signals.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.signal-watches.index'))->assertUnauthorized();
});

it('stops a read-only token acting on a signal', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $signal = apiSignal($team);

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->switchTeam($team);

    Sanctum::actingAs($readOnly, ['*']);

    $this->getJson(route('api.v1.signals.index'))->assertOk();
    $this->postJson(route('api.v1.signals.dismiss', ['signal' => $signal->getKey()]))->assertForbidden();
    $this->postJson(route('api.v1.signals.convert', ['signal' => $signal->getKey()]), [
        'company_name' => 'Acme DMCC',
    ])->assertForbidden();
});

it('404s on another team’s signal', function (): void {
    $other = createUserWithTeam();
    $signal = apiSignal($other->currentTeam()->firstOrFail());

    $user = createUserWithTeam();
    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signals.show', ['signal' => $signal->getKey()]))->assertNotFound();
    $this->postJson(route('api.v1.signals.dismiss', ['signal' => $signal->getKey()]))->assertNotFound();
});

// --- Watches ------------------------------------------------------------------------------------

it('lists, shows, creates, updates and deletes watches', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    Sanctum::actingAs($user, ['*']);

    // Create — 201 with the new watch.
    $created = $this->postJson(route('api.v1.signal-watches.store'), [
        'name' => 'Dubai founders',
        'location' => 'Dubai',
        'event_types' => ['acquisition', 'funding_round'],
        'cadence' => 'daily',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Dubai founders')
        // Event types arrive with their labels and explainers, so a client holds no copy.
        ->assertJsonPath('data.event_types.0.value', 'acquisition')
        ->assertJsonStructure(['data' => ['event_types' => [['value', 'label', 'description']]]])
        ->json('data.id');

    $this->getJson(route('api.v1.signal-watches.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(route('api.v1.signal-watches.show', ['watch' => $created]))
        ->assertOk()
        ->assertJsonPath('data.signals_count', 0);

    $this->patchJson(route('api.v1.signal-watches.update', ['watch' => $created]), [
        'name' => 'Renamed',
        'location' => 'Abu Dhabi',
        'event_types' => ['relocation'],
        'cadence' => 'weekly',
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.cadence', 'weekly')
        ->assertJsonPath('data.is_active', false)
        // Paused, so there is no next check to report.
        ->assertJsonPath('data.next_due_at', null);

    $this->deleteJson(route('api.v1.signal-watches.destroy', ['watch' => $created]))
        ->assertNoContent();

    expect(SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0);
});

it('validates a watch', function (): void {
    $user = createUserWithTeam();
    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signal-watches.store'), [
        'name' => '', 'location' => '', 'event_types' => [], 'cadence' => 'hourly',
    ])
        ->assertStatus(422)
        ->assertApiValidationErrors(['name', 'location', 'event_types', 'cadence']);
});

it('accepts a run and answers 202 without waiting for it', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());
    Sanctum::actingAs($user, ['*']);

    // The work is a Serper search plus an AI pass, so the call returns and the results arrive over
    // Reverb or on the next GET.
    $this->postJson(route('api.v1.signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertStatus(202);

    Queue::assertPushed(
        RunSignalWatchJob::class,
        fn (RunSignalWatchJob $job): bool => $job->watchId === (int) $watch->getKey(),
    );
});

it('answers 429 when a watch was run too recently', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    RateLimiter::clear('signals:run-now:'.$watch->getKey());
    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signal-watches.run', ['watch' => $watch->getKey()]))->assertStatus(202);

    // A token is easier to loop than a button, and a run costs money.
    $this->postJson(route('api.v1.signal-watches.run', ['watch' => $watch->getKey()]))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'signals.run_throttled');

    Queue::assertPushed(RunSignalWatchJob::class, 1);
});

it('reports a watch’s recent runs', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, function () use ($team): SignalWatch {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        SignalRun::factory()->completed()->count(2)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        return $watch;
    });

    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signal-watches.show', ['watch' => $watch->getKey()]))
        ->assertOk()
        // A plain list, not a nested `{ data: [...] }` wrapper.
        ->assertJsonCount(2, 'data.runs')
        ->assertJsonStructure(['data' => ['runs' => [['id', 'status', 'cost_micros', 'signals_created']]]]);
});

it('stops a read-only token managing watches', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->switchTeam($team);

    RateLimiter::clear('signals:run-now:'.$watch->getKey());
    Sanctum::actingAs($readOnly, ['*']);

    $this->getJson(route('api.v1.signal-watches.index'))->assertOk();
    $this->postJson(route('api.v1.signal-watches.store'), [
        'name' => 'Nope', 'location' => 'Dubai', 'event_types' => ['acquisition'], 'cadence' => 'daily',
    ])->assertForbidden();
    $this->deleteJson(route('api.v1.signal-watches.destroy', ['watch' => $watch->getKey()]))->assertForbidden();
    $this->postJson(route('api.v1.signal-watches.run', ['watch' => $watch->getKey()]))->assertForbidden();

    Queue::assertNothingPushed();
});

it('404s on another team’s watch', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $watch = app(TeamContext::class)->run($otherTeam, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $otherTeam->getKey(),
    ]));

    $user = createUserWithTeam();
    RateLimiter::clear('signals:run-now:'.$watch->getKey());
    Sanctum::actingAs($user, ['*']);

    $this->getJson(route('api.v1.signal-watches.show', ['watch' => $watch->getKey()]))->assertNotFound();
    $this->deleteJson(route('api.v1.signal-watches.destroy', ['watch' => $watch->getKey()]))->assertNotFound();
    $this->postJson(route('api.v1.signal-watches.run', ['watch' => $watch->getKey()]))->assertNotFound();

    expect(SignalWatch::withoutGlobalScopes()->count())->toBe(1);
});
