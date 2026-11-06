<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
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

// --- S16: the five tables that had no window, and the entry that has no clock ------------------

/**
 * Create a row of `$model` with an explicit `created_at`, outside the tenant scope.
 *
 * `created_at` is what most of S16's windows are measured on, and a factory's "now" is no use to a
 * retention test — so it is forced quietly after the insert, the same shape `agedSignal()` uses.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @param  class-string<TModel>  $model
 * @param  array<string, mixed>  $attributes
 * @return TModel
 */
function agedRow(Team $team, string $model, int $daysOld, array $attributes = [])
{
    return app(TeamContext::class)->run($team, function () use ($team, $model, $daysOld, $attributes) {
        $row = $model::factory()->create(['team_id' => $team->getKey(), ...$attributes]);

        $row->forceFill(['created_at' => now()->subDays($daysOld)])->saveQuietly();

        return $row->refresh();
    });
}

it('sweeps preview ledger rows past their window', function (): void {
    config(['signals.retention.preview_days' => 30]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $old = agedRow($team, SignalPreview::class, 40);
    $fresh = agedRow($team, SignalPreview::class, 5);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalPreview::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SignalPreview::withoutGlobalScopes()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('sweeps finished runs but never one that is still going', function (): void {
    config(['signals.retention.run_days' => 400]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->create(['team_id' => $team->getKey()]));

    $make = fn (SignalRunStatus $status, ?int $finishedDaysAgo): SignalRun => app(TeamContext::class)
        ->run($team, fn (): SignalRun => SignalRun::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => $status,
            'finished_at' => $finishedDaysAgo === null ? null : now()->subDays($finishedDaysAgo),
        ]));

    $old = $make(SignalRunStatus::Completed, 500);
    $recent = $make(SignalRunStatus::Completed, 30);
    // **A run with no `finished_at` is live state**, however old the row is: `claimOrCreateRun()`
    // adopts a non-terminal run for the watch, so deleting one by age would take the row a worker
    // is about to claim.
    $stuck = $make(SignalRunStatus::Queued, null);
    $stuck->forceFill(['created_at' => now()->subDays(500)])->saveQuietly();

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalRun::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SignalRun::withoutGlobalScopes()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(SignalRun::withoutGlobalScopes()->whereKey($stuck->getKey())->exists())->toBeTrue();
});

it('sweeps per-type spend rows on the day they report, not when they were last written', function (): void {
    config(['signals.retention.spend_days' => 400]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A row's timestamps move every time a run that day adds to it, so `updated_at` would be the
    // day it was last written — `day` is the day being reported on.
    $old = app(TeamContext::class)->run($team, fn (): SignalDefinitionSpend => SignalDefinitionSpend::factory()
        ->create([
            'team_id' => $team->getKey(),
            'event_key' => 'old_key',
            'day' => now()->subDays(500)->toDateString(),
        ]));

    $recent = app(TeamContext::class)->run($team, fn (): SignalDefinitionSpend => SignalDefinitionSpend::factory()
        ->create([
            'team_id' => $team->getKey(),
            'event_key' => 'recent_key',
            'day' => now()->subDays(10)->toDateString(),
        ]));

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalDefinitionSpend::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SignalDefinitionSpend::withoutGlobalScopes()->whereKey($recent->getKey())->exists())->toBeTrue();
});

it('sweeps the playbook log on its own clock as well as with its signal', function (): void {
    config(['signals.retention.playbook_run_days' => 180]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // Hung off a **converted** signal, which the sweep never touches — so the cascade cannot help
    // and this window is the only thing that makes the promise true.
    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'dedupe_key' => 'no:playbook-log',
    ]));

    $signal = agedSignal($team, SignalStatus::Converted, 400, ['lead_id' => $lead->getKey()]);

    // **Two rules, not one.** `(signal_id, signal_playbook_id)` is unique — the once-per-signal
    // guarantee, and the first of S14's four brakes — so one signal cannot carry two logs for the
    // same rule however old they are.
    [$ruleA, $ruleB] = app(TeamContext::class)->run($team, fn (): array => [
        SignalPlaybook::factory()->create(['team_id' => $team->getKey(), 'name' => 'Old rule']),
        SignalPlaybook::factory()->create(['team_id' => $team->getKey(), 'name' => 'New rule']),
    ]);

    $old = agedRow($team, SignalPlaybookRun::class, 200, [
        'signal_id' => $signal->getKey(),
        'signal_playbook_id' => $ruleA->getKey(),
    ]);
    $fresh = agedRow($team, SignalPlaybookRun::class, 10, [
        'signal_id' => $signal->getKey(),
        'signal_playbook_id' => $ruleB->getKey(),
    ]);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalPlaybookRun::withoutGlobalScopes()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SignalPlaybookRun::withoutGlobalScopes()->whereKey($fresh->getKey())->exists())->toBeTrue()
        // And the signal it hangs off is untouched: converted signals are never swept.
        ->and(Signal::withoutGlobalScopes()->whereKey($signal->getKey())->exists())->toBeTrue();
});

it('sweeps a source tally on a rolling window, not on its age', function (): void {
    config(['signals.retention.source_stat_days' => 180]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // An outlet the team still sees every week is never swept however old the row is — otherwise
    // the authority their best source had earned would start again from nothing on its birthday.
    $stillRead = agedRow($team, SignalSourceStat::class, 900, [
        'source_key' => 'gulf-business',
        'event_key' => 'funding_round',
        'last_seen_at' => now()->subDays(3),
    ]);

    $gone = agedRow($team, SignalSourceStat::class, 900, [
        'source_key' => 'defunct-weekly',
        'event_key' => 'funding_round',
        'last_seen_at' => now()->subDays(400),
    ]);

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalSourceStat::withoutGlobalScopes()->whereKey($stillRead->getKey())->exists())->toBeTrue()
        ->and(SignalSourceStat::withoutGlobalScopes()->whereKey($gone->getKey())->exists())->toBeFalse();
});

it('sweeps a synced watchlist entry whose lead has gone, and leaves a manual one alone', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$synced, $manual] = app(TeamContext::class)->run($team, function () use ($team): array {
        $syncedList = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            // `(team_id, name)` is unique, so the two lists are named apart.
            'name' => 'Mirrored pipeline',
            'source_kind' => WatchlistSourceKind::Pipeline,
            // Paused on purpose: the nightly sync skips a paused list ("stop searching" is not
            // "forget what I told you"), so this is exactly where an orphan would sit for ever.
            'is_active' => false,
        ]);

        $manualList = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Typed by hand',
            'source_kind' => WatchlistSourceKind::Manual,
        ]);

        return [
            SignalWatchlistEntry::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $syncedList->getKey(),
                'name' => 'Orphaned Holdings Ltd',
                'lead_id' => null,
            ]),
            // On a manual list a null `lead_id` is the normal case — a company somebody typed that
            // is not a lead yet — rather than a lead that has gone.
            SignalWatchlistEntry::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $manualList->getKey(),
                'name' => 'Typed By Hand Ltd',
                'lead_id' => null,
            ]),
        ];
    });

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalWatchlistEntry::withoutGlobalScopes()->whereKey($synced->getKey())->exists())->toBeFalse()
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->whereKey($manual->getKey())->exists())->toBeTrue();
});

it('deletes nothing on a dry run and reports what it would take', function (): void {
    config(['signals.retention.preview_days' => 30]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $preview = agedRow($team, SignalPreview::class, 90);
    $signal = agedSignal($team, SignalStatus::Dismissed, 200);

    $this->artisan('signals:prune', ['--dry-run' => true])->assertSuccessful();

    // Retention is irreversible, so it has to be possible to see the damage before doing it.
    expect(SignalPreview::withoutGlobalScopes()->whereKey($preview->getKey())->exists())->toBeTrue()
        ->and(Signal::withoutGlobalScopes()->whereKey($signal->getKey())->exists())->toBeTrue();
});

it('is idempotent across every table it sweeps', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    agedRow($team, SignalPreview::class, 90);
    agedSignal($team, SignalStatus::Dismissed, 200);

    $this->artisan('signals:prune')->assertSuccessful();
    // A second pass finds nothing to do rather than failing on rows that have gone.
    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalPreview::withoutGlobalScopes()->count())->toBe(0)
        ->and(Signal::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps one team’s rows out of another team’s sweep', function (): void {
    config(['signals.retention.preview_days' => 30]);

    $userA = createUserWithTeam();
    $teamA = $userA->currentTeam()->firstOrFail();
    $userB = createUserWithTeam();
    $teamB = $userB->currentTeam()->firstOrFail();

    $eligible = agedRow($teamA, SignalPreview::class, 90);
    $fresh = agedRow($teamB, SignalPreview::class, 1);

    $this->artisan('signals:prune')->assertSuccessful();

    // The sweep runs across every team with no scope at all, which is why the *eligibility* has to
    // be the only thing deciding — not whose row it is.
    expect(SignalPreview::withoutGlobalScopes()->whereKey($eligible->getKey())->exists())->toBeFalse()
        ->and(SignalPreview::withoutGlobalScopes()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('puts the list\'s cached count right after sweeping an orphan', function (): void {
    // The case that can never self-heal: this sweep exists for the **paused** synced list the
    // nightly sync returns early on, which is the one path that never recomputes `entries_count`
    // (`/code-review`). Left alone, a list of forty would report forty with twenty-eight rows for
    // ever — on the screen and in the delete audit payload.
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlist {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Mirrored pipeline',
            'source_kind' => WatchlistSourceKind::Pipeline,
            'is_active' => false,
        ]);

        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Still Here Ltd',
            'dedupe_key' => 'no:recount-1',
        ]);

        SignalWatchlistEntry::factory()->forLead($lead)->create([
            'signal_watchlist_id' => $list->getKey(),
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Orphaned Holdings Ltd',
            'lead_id' => null,
        ]);

        $list->forceFill(['entries_count' => 2])->save();

        return $list;
    });

    $this->artisan('signals:prune')->assertSuccessful();

    expect(SignalWatchlistEntry::withoutGlobalScopes()->count())->toBe(1)
        ->and($list->refresh()->entries_count)->toBe(1);
});
