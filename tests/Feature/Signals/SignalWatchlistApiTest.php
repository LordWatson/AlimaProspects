<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalMatchConfidence;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
 * `/api/v1` parity for watchlists and "add to this lead" (S10, ADR 0021) — the same Actions behind
 * the API as behind the screen, so a team syncing their CRM to Alima gets the same rules as a
 * member typing names into a box.
 *
 * The API posts `entries[]` rather than a textarea, which is the one shape difference, and it keeps
 * the indexed validation keys that tell a caller which element it got wrong.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = createUserWithTeam();
    $this->team = $this->user->currentTeam()->firstOrFail();
});

it('lists, shows, creates, updates and deletes watchlists', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $created = $this->postJson(route('api.v1.signal-watchlists.store'), [
        'name' => 'Accounts I care about',
        'source_kind' => 'manual',
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertApiVersion()
        ->assertJsonPath('data.name', 'Accounts I care about')
        ->assertJsonPath('data.is_synced', false)
        ->assertJsonPath('data.can_edit_entries', true)
        ->assertJsonPath('data.entries_count', 0)
        ->json('data.id');

    $this->postJson(route('api.v1.signal-watchlists.entries.store', ['watchlist' => $created]), [
        'entries' => [
            ['name' => 'Acme Engineering Ltd'],
            ['name' => 'Borealis Trading LLC'],
            // Already present once normalised — skipped, not refused, because a client syncing a
            // CRM will send the same name twice and failing the batch would be unusable.
            ['name' => 'ACME ENGINEERING LTD'],
        ],
    ])
        ->assertOk()
        // Beside the list rather than inside it: "two added, one already there" is the normal
        // outcome of a bulk add, and it is not a property of the list.
        ->assertJsonPath('meta.added', 2)
        ->assertJsonPath('meta.duplicates', 1)
        ->assertJsonPath('data.entries_count', 2);

    $this->getJson(route('api.v1.signal-watchlists.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.entries_count', 2);

    $entryId = $this->getJson(route('api.v1.signal-watchlists.show', ['watchlist' => $created]))
        ->assertOk()
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.watches_count', 0)
        ->json('data.entries.0.id');

    $this->patchJson(route('api.v1.signal-watchlists.update', ['watchlist' => $created]), [
        'name' => 'Key accounts',
        'source_kind' => 'manual',
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Key accounts')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson(route('api.v1.signal-watchlists.entries.destroy', [
        'watchlist' => $created,
        'entry' => $entryId,
    ]))->assertNoContent();

    $this->deleteJson(route('api.v1.signal-watchlists.destroy', ['watchlist' => $created]))
        ->assertNoContent();

    expect(app(TeamContext::class)->run($this->team, fn (): int => SignalWatchlist::query()->count()))->toBe(0);
});

it('syncs a pipeline list on request', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
        'team_id' => $this->team->getKey(),
        'company_name' => 'Acme Engineering Ltd',
    ]));

    $list = $this->postJson(route('api.v1.signal-watchlists.store'), [
        'name' => 'My pipeline',
        'source_kind' => 'pipeline',
        'is_active' => true,
    ])->assertCreated()->json('data.id');

    $this->postJson(route('api.v1.signal-watchlists.sync', ['watchlist' => $list]))
        ->assertOk()
        // Nothing to add: creating it synced it, which is what the screen relies on too.
        ->assertJsonPath('meta.added', 0)
        ->assertJsonPath('meta.kept', 1);
});

it('refuses to hand-edit a synced list, with a code a client can branch on', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $list = app(TeamContext::class)->run($this->team, fn (): SignalWatchlist => SignalWatchlist::factory()->create([
        'team_id' => $this->team->getKey(),
        'source_kind' => WatchlistSourceKind::Pipeline,
    ]));

    $this->postJson(route('api.v1.signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), [
        'entries' => [['name' => 'Acme Engineering Ltd']],
    ])
        ->assertStatus(422)
        ->assertApiError('signals.watchlist_is_synced');
});

it('validates the batch and says which element was wrong', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $list = app(TeamContext::class)->run($this->team, fn (): SignalWatchlist => SignalWatchlist::factory()->create([
        'team_id' => $this->team->getKey(),
        'source_kind' => WatchlistSourceKind::Manual,
    ]));

    $this->postJson(route('api.v1.signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), [
        'entries' => [['name' => 'Fine Ltd'], ['name' => '']],
    ])
        ->assertStatus(422)
        // The indexed key stays indexed here: a caller that really did post a list can be told
        // which element to fix, which is the opposite of what the screen needs.
        ->assertApiValidationErrors('entries.1.name');

    $this->postJson(route('api.v1.signal-watchlists.store'), [
        'name' => '',
        'source_kind' => 'segment',
    ])
        ->assertStatus(422)
        ->assertApiValidationErrors(['name', 'source_kind']);
});

it('stops a read-only token changing the accounts', function (): void {
    // A scope asks what a *credential* may do (ADR 0013): a read token sees which accounts the
    // team watches and changes none of them.
    Sanctum::actingAs($this->user, [TokenAbility::SignalsRead->value]);

    $list = app(TeamContext::class)->run($this->team, fn (): SignalWatchlist => SignalWatchlist::factory()->create([
        'team_id' => $this->team->getKey(),
        'source_kind' => WatchlistSourceKind::Manual,
    ]));

    $this->getJson(route('api.v1.signal-watchlists.index'))->assertOk();
    $this->getJson(route('api.v1.signal-watchlists.show', ['watchlist' => $list->getKey()]))->assertOk();

    $this->postJson(route('api.v1.signal-watchlists.entries.store', ['watchlist' => $list->getKey()]), [
        'entries' => [['name' => 'Acme Engineering Ltd']],
    ])->assertForbidden();

    $this->postJson(route('api.v1.signal-watchlists.sync', ['watchlist' => $list->getKey()]))->assertForbidden();
    $this->deleteJson(route('api.v1.signal-watchlists.destroy', ['watchlist' => $list->getKey()]))->assertForbidden();
});

it('404s on another team’s list and on their entry', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    [$list, $entry] = app(TeamContext::class)->run($otherTeam, function () use ($otherTeam): array {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
        ]);

        $entry = SignalWatchlistEntry::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Acme Engineering Ltd',
        ]);

        return [$list, $entry];
    });

    Sanctum::actingAs($this->user, ['*']);

    $this->getJson(route('api.v1.signal-watchlists.show', ['watchlist' => $list->getKey()]))->assertNotFound();
    $this->postJson(route('api.v1.signal-watchlists.sync', ['watchlist' => $list->getKey()]))->assertNotFound();
    $this->deleteJson(route('api.v1.signal-watchlists.entries.destroy', [
        'watchlist' => $list->getKey(),
        'entry' => $entry->getKey(),
    ]))->assertNotFound();
});

it('needs a credential at all', function (): void {
    $this->getJson(route('api.v1.signal-watchlists.index'))->assertUnauthorized();
});

// --- Add to the lead ----------------------------------------------------------------------------

it('adds a matched event to its lead and creates nothing', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $lead = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
        'team_id' => $this->team->getKey(),
        'company_name' => 'Acme DMCC',
    ]));

    $signal = app(TeamContext::class)->run($this->team, function () use ($lead): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $this->team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $this->team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'subject_company_name' => 'Acme DMCC',
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Exact,
        ]);
    });

    $this->postJson(route('api.v1.signals.attach', ['signal' => $signal->getKey()]))
        ->assertOk()
        ->assertApiVersion()
        // 200 with the signal, not 201: nothing was created, which is the point of the endpoint.
        ->assertJsonPath('data.status', SignalStatus::Converted->value)
        ->assertJsonPath('data.lead.id', (int) $lead->getKey());

    expect(app(TeamContext::class)->run($this->team, fn (): int => Lead::query()->count()))->toBe(1);
});

it('carries the matched lead on every single-signal response', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $lead = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
        'team_id' => $this->team->getKey(),
        'company_name' => 'Acme DMCC',
    ]));

    $signal = app(TeamContext::class)->run($this->team, function () use ($lead): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $this->team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $this->team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Likely,
        ]);
    });

    // `matched_lead` is not `whenLoaded` — the card decides what to offer from it — so every
    // endpoint returning one signal has to eager-load the relation. Unloaded, this is a
    // lazy-loading violation here and an N+1 in production, and only a matched signal shows it.
    $this->getJson(route('api.v1.signals.show', ['signal' => $signal->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.matched_lead.company_name', 'Acme DMCC')
        ->assertJsonPath('data.matched_lead.needs_confirmation', true);

    $this->postJson(route('api.v1.signals.dismiss', ['signal' => $signal->getKey()]), [
        'reason' => 'not_relevant',
    ])
        ->assertOk()
        ->assertJsonPath('data.matched_lead.company_name', 'Acme DMCC');

    $this->postJson(route('api.v1.signals.restore', ['signal' => $signal->getKey()]))
        ->assertOk()
        ->assertJsonPath('data.matched_lead.company_name', 'Acme DMCC');
});

it('refuses to attach an event that is about nobody', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $signal = app(TeamContext::class)->run($this->team, function (): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $this->team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $this->team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'matched_lead_id' => null,
        ]);
    });

    $this->postJson(route('api.v1.signals.attach', ['signal' => $signal->getKey()]))
        ->assertStatus(422)
        ->assertApiError('signals.no_lead_to_attach_to');
});
