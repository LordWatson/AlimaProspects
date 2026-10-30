<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A signal with an explicit age and status, created without the tenant scope getting in the way.
 *
 * @param  array<string, mixed>  $attributes
 */
function agedSignal(Team $team, SignalStatus $status, int $daysOld, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $status, $daysOld, $attributes): Signal {
        $watch = SignalWatch::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => $status,
            ...$attributes,
        ]);

        // `created_at` is what retention is measured on, so it is set explicitly rather than left
        // to the factory's "now".
        $signal->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        return $signal->refresh();
    });
}

it('deletes dismissed signals past their window and keeps fresh ones', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $old = agedSignal($team, SignalStatus::Dismissed, 100);
    $fresh = agedSignal($team, SignalStatus::Dismissed, 10);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(Signal::withoutGlobalScopes()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('deletes unactioned signals after the longer window', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A six-month-old funding round isn't a trigger event any more, and holding a named person's
    // details because nobody clicked is the retention data minimisation exists to prevent.
    $old = agedSignal($team, SignalStatus::New, 200);
    // 100 days is past the *dismissed* window but not the unactioned one — the two must not be
    // confused for each other.
    $middling = agedSignal($team, SignalStatus::New, 100);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(Signal::withoutGlobalScopes()->whereKey($middling->getKey())->exists())->toBeTrue();
});

it('never prunes a converted signal, however old', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'dedupe_key' => 'no:kept-1',
    ]));

    // It is the provenance of a real lead — the lead's own detail screen reads it back for the
    // origin panel — so it lives as long as the lead does.
    $converted = agedSignal($team, SignalStatus::Converted, 500, ['lead_id' => $lead->getKey()]);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->whereKey($converted->getKey())->exists())->toBeTrue();
});

it('reports without deleting on a dry run', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    agedSignal($team, SignalStatus::Dismissed, 100);
    agedSignal($team, SignalStatus::New, 200);

    $this->artisan('signals:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 dismissed and 1 unactioned signal(s).')
        ->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->count())->toBe(2);
});

it('is idempotent', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    agedSignal($team, SignalStatus::Dismissed, 100);

    $this->artisan('signals:prune')->assertSuccessful();
    // The second run has nothing left to find and must still succeed.
    $this->artisan('signals:prune')
        ->expectsOutputToContain('Deleted 0 dismissed and 0 unactioned signal(s).')
        ->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('sweeps every team, not just one', function (): void {
    $first = createUserWithTeam();
    $second = createUserWithTeam();

    agedSignal($first->currentTeam()->firstOrFail(), SignalStatus::Dismissed, 100);
    agedSignal($second->currentTeam()->firstOrFail(), SignalStatus::Dismissed, 100);

    // A system sweep, which is why it is a scheduled command and not something a request reaches.
    $this->artisan('signals:prune')->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('chunks through more rows than one pass holds', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->count(12)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalStatus::Dismissed,
        ]);
    });

    Signal::withoutGlobalScopes()->where('status', SignalStatus::Dismissed)
        ->update(['created_at' => now()->subDays(120)]);

    $this->artisan('signals:prune')
        ->expectsOutputToContain('Deleted 12 dismissed and 0 unactioned signal(s).')
        ->assertSuccessful();

    expect(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('honours the configured windows', function (): void {
    config(['signals.retention.dismissed_days' => 5, 'signals.retention.unactioned_days' => 7]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $dismissed = agedSignal($team, SignalStatus::Dismissed, 6);
    $unactioned = agedSignal($team, SignalStatus::New, 6);

    $this->artisan('signals:prune')->assertSuccessful();

    // 6 days is past the 5-day dismissed window but inside the 7-day unactioned one.
    expect(Signal::withoutGlobalScopes()->whereKey($dismissed->getKey())->exists())->toBeFalse()
        ->and(Signal::withoutGlobalScopes()->whereKey($unactioned->getKey())->exists())->toBeTrue();
});

it('tells the team how long a dismissed signal has left', function (): void {
    config(['signals.retention.dismissed_days' => 90]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    agedSignal($team, SignalStatus::Dismissed, 80);

    // The countdown is the promise compliance.md §5a makes to the team, so it comes from the same
    // window the sweep uses rather than being computed on the client.
    $this->actingAs($user)
        ->get(route('signals.index', ['status' => 'dismissed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('signals.data.0.deletes_in_days', 10));
});

it('reports no countdown for a converted signal', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'dedupe_key' => 'no:kept-2',
    ]));

    agedSignal($team, SignalStatus::Converted, 400, ['lead_id' => $lead->getKey()]);

    // It is kept as long as its lead, so there is nothing to count down to.
    $this->actingAs($user)
        ->get(route('signals.index', ['status' => 'converted']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('signals.data.0.deletes_in_days', null));
});

it('never reports a negative countdown for a signal the sweep hasn’t reached', function (): void {
    config(['signals.retention.dismissed_days' => 90]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // The sweep runs nightly, so a row can sit past its window for a few hours.
    agedSignal($team, SignalStatus::Dismissed, 120);

    $this->actingAs($user)
        ->get(route('signals.index', ['status' => 'dismissed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('signals.data.0.deletes_in_days', 0));
});
