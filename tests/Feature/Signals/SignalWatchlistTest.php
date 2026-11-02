<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Jobs\SyncPipelineWatchlistsJob;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The accounts a team asks Alima to keep up with (S10, ADR 0021).
 *
 * The screen, the two Actions behind it and the one rule that matters more than the rest: a list
 * belongs to one team, and a name on it is a company name — so a cross-tenant reach has to fail,
 * and an erased lead has to leave.
 */

/** A lead in the team's pipeline, worked `$hoursAgo` ago so the cap's ordering is testable. */
function pipelineLead(Team $team, string $name, ?PipelineStage $stage = null, int $hoursAgo = 1): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_name' => $name,
        'pipeline_stage_id' => $stage?->getKey(),
        'updated_at' => now()->subHours($hoursAgo),
    ]));
}

function pipelineList(Team $team, array $attributes = []): SignalWatchlist
{
    return app(TeamContext::class)->run($team, fn (): SignalWatchlist => SignalWatchlist::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'source_kind' => WatchlistSourceKind::Pipeline,
        'name' => 'My pipeline',
    ], $attributes)));
}

function syncList(Team $team, SignalWatchlist $list): array
{
    return app(SyncPipelineWatchlistAction::class)->handle($team, $list);
}

// --- The screen ---------------------------------------------------------------------------------

it('shows the team’s lists, their accounts and what one check covers', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = pipelineLead($team, 'Acme Engineering Ltd');
    $list = pipelineList($team);
    syncList($team, $list);

    $this->actingAs($user)
        ->get(route('signal-watchlists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/Accounts')
            ->has('watchlists', 1)
            ->where('watchlists.0.name', 'My pipeline')
            ->where('watchlists.0.is_synced', true)
            // A synced list's names are an output, so the screen must not offer an add box.
            ->where('watchlists.0.can_edit_entries', false)
            ->where('watchlists.0.entries_count', 1)
            ->has('watchlists.0.entries', 1)
            ->where('watchlists.0.entries.0.name', 'Acme Engineering Ltd')
            ->where('watchlists.0.entries.0.lead_id', (int) $lead->getKey())
            // The comparison key is never sent: it is ours, and showing it would invite somebody
            // to type it.
            ->missing('watchlists.0.entries.0.normalised_name')
            ->has('options.source_kinds', 2)
            ->where('options.max_entries', 250)
            // How many accounts one run looks at, so the rotation can be explained on screen.
            ->where('options.window', 40)
            ->where('can.manage', true));
});

it('offers only the kinds a member can actually choose', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('signal-watchlists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // `segment` is declared on the enum and not offered: a case with no implementation
            // behind it would be a promise the screen cannot keep.
            ->where('options.source_kinds.0.value', 'pipeline')
            ->where('options.source_kinds.1.value', 'manual'));
});

it('creates a pipeline list and fills it on the spot', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    pipelineLead($team, 'Acme Engineering Ltd');
    pipelineLead($team, 'Borealis Trading LLC');

    $this->actingAs($user)
        ->post(route('signal-watchlists.store'), [
            'name' => 'Open opportunities',
            'source_kind' => 'pipeline',
            'is_active' => true,
            'stages' => [],
            'owners' => [],
        ])
        ->assertRedirect(route('signal-watchlists.index'));

    $list = app(TeamContext::class)->run($team, fn (): ?SignalWatchlist => SignalWatchlist::query()->first());

    // Synced immediately rather than tonight: a member who has just said "watch my pipeline"
    // should not be shown an empty list and a promise.
    expect($list)->not->toBeNull()
        ->and($list->entries_count)->toBe(2)
        ->and($list->synced_at)->not->toBeNull();
});

it('adds pasted names to a manual list, skipping the ones already on it', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual, 'name' => 'Names I add']);

    $this->actingAs($user)
        ->post(route('signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), [
            // A textarea, which is what the screen posts: the shaping into `entries[]` is the
            // request's job, so "one name per line" has exactly one definition.
            'names' => "Acme Engineering Ltd\nBorealis Trading LLC\n\n  Acme Engineering Ltd  \nACME ENGINEERING LTD",
        ])
        ->assertRedirect(route('signal-watchlists.index'));

    $names = app(TeamContext::class)->run($team, fn (): array => $list->entries()->orderBy('name')->pluck('name')->all());

    // Two added; the blank line, the whitespace-only duplicate and the shouted duplicate are all
    // the same name once normalised — pasting a column produces exactly this.
    expect($names)->toBe(['Acme Engineering Ltd', 'Borealis Trading LLC']);
});

it('refuses to let a member hand-edit a synced list', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team);

    $this->actingAs($user)
        ->post(route('signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), [
            'names' => 'Acme Engineering Ltd',
        ])
        // A name the next sync would delete is not something to offer somebody. A refused domain
        // rule comes back to the screen as a toast, not as a field error (bootstrap/app.php).
        ->assertRedirect();

    expect(app(TeamContext::class)->run($team, fn (): int => $list->entries()->count()))->toBe(0);
});

it('puts a textarea’s validation failure on the textarea', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual]);

    $this->actingAs($user)
        ->post(route('signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), ['names' => "\n \n"])
        // Not `entries.0.name`: the member typed one box, and an error against a row index in a
        // payload their screen never built points at nothing.
        ->assertSessionHasErrors('names');
});

it('caps how many lists one team may keep', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    config()->set('signals.watchlist.max_lists_per_team', 1);

    pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual, 'name' => 'First']);

    $this->actingAs($user)
        ->post(route('signal-watchlists.store'), [
            'name' => 'Second',
            'source_kind' => 'manual',
            'is_active' => true,
            'stages' => [],
            'owners' => [],
        ])
        ->assertRedirect();

    expect(app(TeamContext::class)->run($team, fn (): int => SignalWatchlist::query()->count()))->toBe(1);
});

it('removes a list without touching the signals it already found', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual]);

    $this->actingAs($user)
        ->delete(route('signal-watchlists.destroy', ['watchlist' => $list->getKey()]))
        ->assertRedirect(route('signal-watchlists.index'));

    expect(app(TeamContext::class)->run($team, fn (): int => SignalWatchlist::query()->count()))->toBe(0)
        ->and(app(TeamContext::class)->run($team, fn (): int => SignalWatchlistEntry::query()->count()))->toBe(0);
});

it('renames and pauses a list from the screen', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual, 'name' => 'First go']);

    $this->actingAs($user)
        ->patch(route('signal-watchlists.update', ['watchlist' => $list->getKey()]), [
            'name' => 'Key accounts',
            // The kind cannot change after the list exists, so it is posted back unchanged.
            'source_kind' => 'manual',
            'is_active' => false,
            'stages' => [],
            'owners' => [],
        ])
        ->assertRedirect(route('signal-watchlists.index'));

    $saved = app(TeamContext::class)->run($team, fn (): SignalWatchlist => $list->refresh());

    expect($saved->name)->toBe('Key accounts')
        // Pausing stops its watches searching; it does not forget the names.
        ->and($saved->is_active)->toBeFalse()
        ->and($saved->source_kind)->toBe(WatchlistSourceKind::Manual);
});

it('brings a pipeline list up to date on request', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = pipelineList($team);

    // Added after the list was created, so "sync now" has something to find — which is the whole
    // reason the button exists beside a job that runs overnight.
    pipelineLead($team, 'Acme Engineering Ltd');

    $this->actingAs($user)
        ->post(route('signal-watchlists.sync', ['watchlist' => $list->getKey()]))
        ->assertRedirect(route('signal-watchlists.index'));

    expect(app(TeamContext::class)->run($team, fn (): array => $list->entries()->pluck('name')->all()))
        ->toBe(['Acme Engineering Ltd']);
});

it('keeps a read-only member out of the lists', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $list = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual]);

    // Seeing which accounts the team watches is the team's business; changing them is not.
    $this->actingAs($readOnly)
        ->get(route('signal-watchlists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($readOnly)
        ->post(route('signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), ['names' => 'Acme Ltd'])
        ->assertForbidden();

    $this->actingAs($readOnly)
        ->post(route('signal-watchlists.sync', ['watchlist' => $list->getKey()]))
        ->assertForbidden();
});

// --- Tenancy -----------------------------------------------------------------------------------

it('refuses to reach a list, or an entry, belonging to another team', function (): void {
    $user = createUserWithTeam();
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    $theirList = pipelineList($otherTeam, ['source_kind' => WatchlistSourceKind::Manual]);
    $theirEntry = app(TeamContext::class)->run($otherTeam, fn (): SignalWatchlistEntry => SignalWatchlistEntry::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'signal_watchlist_id' => $theirList->getKey(),
        'name' => 'Acme Engineering Ltd',
    ]));

    $this->actingAs($user)
        ->post(route('signal-watchlists.entries.store', ['watchlist' => $theirList->getKey()]), ['names' => 'Mine Ltd'])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('signal-watchlists.entries.destroy', [
            'watchlist' => $theirList->getKey(),
            'entry' => $theirEntry->getKey(),
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('signal-watchlists.destroy', ['watchlist' => $theirList->getKey()]))
        ->assertNotFound();

    expect(app(TeamContext::class)->run($otherTeam, fn (): int => $theirList->entries()->count()))->toBe(1);
});

it('refuses an entry id from another list of the same team', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $mine = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual, 'name' => 'Mine']);
    $theirs = pipelineList($team, ['source_kind' => WatchlistSourceKind::Manual, 'name' => 'Other']);

    $entry = app(TeamContext::class)->run($team, fn (): SignalWatchlistEntry => SignalWatchlistEntry::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watchlist_id' => $theirs->getKey(),
        'name' => 'Acme Engineering Ltd',
    ]));

    // The nested route has to mean what it says: an entry belongs to the list in the URL.
    $this->actingAs($user)
        ->delete(route('signal-watchlists.entries.destroy', [
            'watchlist' => $mine->getKey(),
            'entry' => $entry->getKey(),
        ]))
        ->assertNotFound();
});

// --- The sync ----------------------------------------------------------------------------------

it('mirrors the pipeline and is idempotent', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    pipelineLead($team, 'Acme Engineering Ltd');
    pipelineLead($team, 'Borealis Trading LLC');

    $list = pipelineList($team);

    expect(syncList($team, $list))->toMatchArray(['added' => 2, 'removed' => 0, 'kept' => 2]);

    $ids = app(TeamContext::class)->run($team, fn (): array => $list->entries()->orderBy('id')->pluck('id')->all());

    // Run again: nothing added, nothing removed, and the same rows — an entry carries the
    // `lead_id` the matcher relies on, so churning them nightly would be churn for nothing.
    expect(syncList($team, $list))->toMatchArray(['added' => 0, 'removed' => 0, 'kept' => 2])
        ->and(app(TeamContext::class)->run($team, fn (): array => $list->entries()->orderBy('id')->pluck('id')->all()))
        ->toBe($ids);
});

it('follows only the stages a list names', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$watched, $ignored] = app(TeamContext::class)->run($team, fn (): array => [
        PipelineStage::factory()->create(['team_id' => $team->getKey(), 'name' => 'Qualifying', 'position' => 1]),
        PipelineStage::factory()->create(['team_id' => $team->getKey(), 'name' => 'Won', 'position' => 2]),
    ]);

    pipelineLead($team, 'In play Ltd', $watched);
    pipelineLead($team, 'Already won Ltd', $ignored);

    $list = pipelineList($team, ['sync_settings' => ['stages' => [(int) $watched->getKey()]]]);

    syncList($team, $list);

    expect(app(TeamContext::class)->run($team, fn (): array => $list->entries()->pluck('name')->all()))
        ->toBe(['In play Ltd']);
});

it('drops an account that leaves the stages being mirrored', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$watched, $other] = app(TeamContext::class)->run($team, fn (): array => [
        PipelineStage::factory()->create(['team_id' => $team->getKey(), 'name' => 'Qualifying', 'position' => 1]),
        PipelineStage::factory()->create(['team_id' => $team->getKey(), 'name' => 'Lost', 'position' => 2]),
    ]);

    $lead = pipelineLead($team, 'Acme Engineering Ltd', $watched);
    $list = pipelineList($team, ['sync_settings' => ['stages' => [(int) $watched->getKey()]]]);

    syncList($team, $list);

    app(TeamContext::class)->run($team, function () use ($lead, $other): void {
        $lead->forceFill(['pipeline_stage_id' => $other->getKey()])->save();
    });

    expect(syncList($team, $list))->toMatchArray(['added' => 0, 'removed' => 1, 'kept' => 0]);
});

it('never puts an erased lead back into a search', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = pipelineLead($team, 'Acme Engineering Ltd');
    $list = pipelineList($team);

    syncList($team, $list);

    expect(app(TeamContext::class)->run($team, fn (): int => $list->entries()->count()))->toBe(1);

    // Anonymised by some other route than `LeadAnonymiser` (which removes the entry itself, in the
    // same transaction as the rest of the erasure). This is the second line of defence.
    app(TeamContext::class)->run($team, function () use ($lead): void {
        $lead->forceFill(['anonymised_at' => now(), 'company_name' => 'Redacted'])->save();
    });

    expect(syncList($team, $list))->toMatchArray(['added' => 0, 'removed' => 1, 'kept' => 0]);
});

it('clears an entry whose lead has been deleted', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = pipelineLead($team, 'Acme Engineering Ltd');
    $list = pipelineList($team);

    syncList($team, $list);

    // `lead_id` is `nullOnDelete` and a lead is not soft-deleted, so deleting one leaves a row
    // holding a company name with nothing behind it — and a synced list refuses hand edits, so
    // the member cannot clear it up. Left in place it would go on being searched and paid for.
    app(TeamContext::class)->run($team, fn () => $lead->delete());

    expect(syncList($team, $list))->toMatchArray(['added' => 0, 'removed' => 1, 'kept' => 0])
        ->and(app(TeamContext::class)->run($team, fn (): int => $list->entries()->count()))->toBe(0);
});

it('keeps the most recently worked accounts when the pipeline outgrows the cap', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    config()->set('signals.watchlist.max_entries', 2);

    pipelineLead($team, 'Worked today Ltd', null, hoursAgo: 1);
    pipelineLead($team, 'Worked yesterday Ltd', null, hoursAgo: 25);
    pipelineLead($team, 'Worked in March Ltd', null, hoursAgo: 5_000);

    $list = pipelineList($team);

    syncList($team, $list);

    // A watchlist is about what somebody is working now, and every account on it is a paid search.
    expect(app(TeamContext::class)->run($team, fn (): array => $list->entries()->orderBy('name')->pluck('name')->all()))
        ->toBe(['Worked today Ltd', 'Worked yesterday Ltd']);
});

it('leaves a paused list exactly as it is', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    pipelineLead($team, 'Acme Engineering Ltd');
    $list = pipelineList($team);
    syncList($team, $list);

    app(TeamContext::class)->run($team, fn () => $list->forceFill(['is_active' => false])->save());

    pipelineLead($team, 'Borealis Trading LLC');

    // Pausing means "stop searching", not "forget what I told you".
    expect(syncList($team, $list))->toMatchArray(['added' => 0, 'removed' => 0, 'kept' => 1]);
});

it('skips a second lead whose name normalises to one already on the list', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    pipelineLead($team, 'Acme Engineering Ltd');
    pipelineLead($team, 'ACME ENGINEERING LTD');

    $list = pipelineList($team);

    // Two records for one company, which enrichment may not have merged yet. The unique index is
    // on the normalised name, so the sync skips rather than failing outright.
    expect(syncList($team, $list))->toMatchArray(['added' => 1, 'kept' => 1]);
});

it('syncs every team’s lists on the nightly job without crossing a tenant', function (): void {
    $first = createUserWithTeam();
    $firstTeam = $first->currentTeam()->firstOrFail();
    $second = createUserWithTeam();
    $secondTeam = $second->currentTeam()->firstOrFail();

    pipelineLead($firstTeam, 'Acme Engineering Ltd');
    pipelineLead($secondTeam, 'Borealis Trading LLC');

    $firstList = pipelineList($firstTeam);
    $secondList = pipelineList($secondTeam);

    app(SyncPipelineWatchlistsJob::class)->handle(app(SyncPipelineWatchlistAction::class));

    expect(app(TeamContext::class)->run($firstTeam, fn (): array => $firstList->entries()->pluck('name')->all()))
        ->toBe(['Acme Engineering Ltd'])
        ->and(app(TeamContext::class)->run($secondTeam, fn (): array => $secondList->entries()->pluck('name')->all()))
        ->toBe(['Borealis Trading LLC']);
});
