<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Database\Seeders\SignalPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BillingFixtures as Billing;

/*
 * The signal-type library (S7, ADR 0018): the phase that turned v1's three-case `SignalEventType`
 * enum into rows a team owns.
 *
 * The cases worth having here are the ones the enum made impossible to get wrong and a row does
 * not: the platform/tenant scope boundary, the clone-never-reference rule, and the two ways a
 * definition can be in use when somebody tries to remove it.
 */

uses(RefreshDatabase::class);

/**
 * A signal of a given type, attributed the way a run writes one: both the FK and the stored key.
 */
function definitionSignal(Team $team, SignalDefinition $definition): Signal
{
    return app(TeamContext::class)->run($team, function () use ($team, $definition): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()
            ->forDefinition($definition)
            ->create(['team_id' => $team->getKey(), 'signal_watch_id' => $watch->getKey()]);
    });
}

beforeEach(function (): void {
    Queue::fake();
    Event::fake();

    // The library is platform reference data, so a test that reads it has to seed it — the same
    // arrangement the AI prompt registry uses.
    $this->seed(SignalPresetSeeder::class);
});

// --- the library ------------------------------------------------------------------------------

it('lists the team’s types and the presets they have not taken yet', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    $this->actingAs($user)
        ->get(route('signal-definitions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/Types')
            ->has('definitions', 1)
            ->where('definitions.0.key', SignalPresetCatalogue::FUNDING_ROUND)
            ->where('definitions.0.is_preset', false)
            // A library that keeps offering what you already have is a library you stop reading.
            // A test team's market is `uk`, so the library is the UK pack minus nothing (the clone
            // above is of a UAE preset, which was never in this team's library to begin with).
            ->has('presets', count(SignalPresetCatalogue::keysForMarket(Market::Uk)))
            ->where('presets.0.is_preset', true)
            ->has('options.categories')
            ->has('options.icons')
            // The builder (S9): the packs the library is browsed by, the per-template cost the
            // form multiplies, and whether this plan includes the AI shortcut.
            ->has('options.packs')
            ->has('cost.cost_micros_per_month')
            ->where('cost.templates', 1)
            ->where('can.manage', true)
            ->where('can.compose', true));
});

it('keeps the presets out of every team-scoped read', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // The global scope is what hides them, and `PlatformPresetReader` is the only thing allowed to
    // remove it (ADR 0018 §2). A team-scoped query must come back empty even though three preset
    // rows exist in the same table.
    $scoped = app(TeamContext::class)->run($team, fn (): int => SignalDefinition::query()->count());

    expect($scoped)->toBe(0)
        ->and(app(PlatformPresetReader::class)->active())->toHaveCount(count(SignalPresetCatalogue::keys()));
});

it('refuses to hand back a tenant’s row through the preset reader', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $mine = signalDefinition($team, SignalPresetCatalogue::ACQUISITION);

    // Asked for by a key a team also has: the reader re-asserts `team_id is null`, so what comes
    // back is the platform row and never the tenant's.
    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::ACQUISITION);

    expect($preset)->not->toBeNull()
        ->and($preset->getKey())->not->toBe($mine->getKey())
        ->and($preset->team_id)->toBeNull()
        ->and($preset->scope_id)->toBe(SignalDefinition::PLATFORM_SCOPE);
});

// --- cloning ----------------------------------------------------------------------------------

it('clones a preset into the team’s own types, carrying its provenance', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $this->actingAs($user)
        ->post(route('signal-definitions.clone'), ['keys' => [SignalPresetCatalogue::RELOCATION]])
        ->assertRedirect(route('signal-definitions.index'));

    $clone = SignalDefinition::withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->sole();

    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::RELOCATION);

    expect($clone->key)->toBe(SignalPresetCatalogue::RELOCATION)
        // A copy, not a reference: the templates live on the team's row (ADR 0018 §3).
        ->and($clone->query_templates)->toBe($preset->query_templates)
        ->and($clone->preset_key)->toBe(SignalPresetCatalogue::RELOCATION)
        ->and($clone->preset_version)->toBe(1)
        ->and($clone->version)->toBe(1)
        ->and($clone->created_by)->toBe($user->getKey())
        // The generated column agrees with the team, which is what makes the unique index mean
        // "one key per scope".
        ->and($clone->scope_id)->toBe((int) $team->getKey());

    expect(AuditLog::withoutGlobalScopes()->where('action', 'signals.definition_cloned')->count())->toBe(1);
});

it('does not overwrite a tuned copy when the same preset is added again', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $mine = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    app(TeamContext::class)->run($team, function () use ($mine): void {
        $mine->forceFill(['name' => 'Rounds we care about', 'query_templates' => ['Dubai seed round']])->save();
    });

    $this->actingAs($user)
        ->post(route('signal-definitions.clone'), ['keys' => [SignalPresetCatalogue::FUNDING_ROUND]])
        ->assertRedirect();

    // Idempotent, and that is the point: re-adding from the library must not quietly undo the
    // team's own tuning.
    $after = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($after->getKey())->toBe($mine->getKey())
        ->and($after->name)->toBe('Rounds we care about')
        ->and($after->query_templates)->toBe(['Dubai seed round']);
});

it('reports an unknown preset key rather than creating nothing silently', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->from(route('signal-definitions.index'))
        ->post(route('signal-definitions.clone'), ['keys' => ['no_such_type']])
        ->assertRedirect(route('signal-definitions.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

// --- authoring --------------------------------------------------------------------------------

it('creates a type of the team’s own and slugs its key', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $this->actingAs($user)
        ->post(route('signal-definitions.store'), [
            'name' => 'Hiring surge',
            'description' => 'A business is hiring hard, which says where it is investing.',
            'category' => 'hiring',
            'icon' => 'users',
            'query_templates' => ['{location} company hiring surge', '{location} opens new office'],
            'match_keywords' => ['Hiring', 'RECRUIT'],
            'includes' => ['a named company posting many roles'],
            'excludes' => ['job boards and recruitment-agency marketing'],
            'min_confidence' => 75,
            'min_relevance' => null,
        ])
        ->assertRedirect(route('signal-definitions.index'));

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($definition->key)->toBe('hiring_surge')
        // The keyword list is lower-cased on the way in, so the ICP match does not have to re-learn
        // that it is case-insensitive on every run.
        ->and($definition->query_templates)->toBe(['{location} company hiring surge', '{location} opens new office'])
        ->and($definition->match_keywords)->toBe(['hiring', 'recruit'])
        // Null stays null and falls back to the product's floor, rather than becoming a floor of 0.
        ->and($definition->effectiveThresholds())->toBe(['min_confidence' => 75, 'min_relevance' => 40])
        // A type the team wrote has no provenance and no market restriction.
        ->and($definition->preset_key)->toBeNull()
        ->and($definition->markets)->toBeNull();

    expect(AuditLog::withoutGlobalScopes()->where('action', 'signals.definition_created')->count())->toBe(1);
});

it('gives a second type of the same name a key of its own', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    foreach ([1, 2] as $_) {
        $this->actingAs($user)->post(route('signal-definitions.store'), [
            'name' => 'Hiring surge',
            'description' => 'They are hiring.',
            'category' => 'hiring',
            'icon' => 'users',
            'query_templates' => ['{location} hiring surge'],
        ])->assertRedirect();
    }

    $keys = SignalDefinition::withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->orderBy('id')
        ->pluck('key')
        ->all();

    // `unique(scope_id, key)` is a real constraint, so the second one has to be given a free key
    // rather than failing the insert.
    expect($keys)->toBe(['hiring_surge', 'hiring_surge_2']);
});

it('keeps the key when the name changes, and bumps the version', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    $this->actingAs($user)
        ->patch(route('signal-definitions.update', ['definition' => $definition->getKey()]), [
            'name' => 'Rounds we care about',
            'description' => 'A round that puts cash in a founder’s hands.',
            'category' => 'growth',
            'icon' => 'trending-up',
            'query_templates' => ['{location} seed round'],
        ])
        ->assertRedirect(route('signal-definitions.index'));

    $definition->refresh();

    // Every signal this type ever classified carries a copy of the key, so renaming must not
    // orphan its own history (ADR 0018 §4).
    expect($definition->key)->toBe(SignalPresetCatalogue::FUNDING_ROUND)
        ->and($definition->name)->toBe('Rounds we care about')
        ->and($definition->version)->toBe(2)
        // Edited, so the wording is the team's now and must not be run through `t()`.
        ->and($definition->usesPlatformWording())->toBeFalse();
});

it('validates a type', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signal-definitions.store'), [
            'name' => '',
            'description' => '',
            'category' => 'nonsense',
            'icon' => '<script>',
            'query_templates' => [],
            'min_confidence' => 400,
        ])
        ->assertSessionHasErrors(['name', 'description', 'category', 'icon', 'query_templates', 'min_confidence']);
});

it('rejects a blank search line rather than dropping it silently', function (): void {
    $user = createUserWithTeam();

    // `ConvertEmptyStringsToNull` turns a trimmed-empty entry into null, and the rule refuses it.
    // That is the behaviour we want for the API — silently dropping something a client sent would
    // have them believe it was accepted — and the textarea filters blank lines before posting, so
    // nobody meets this from the screen.
    $this->actingAs($user)
        ->post(route('signal-definitions.store'), [
            'name' => 'Hiring surge',
            'description' => 'They are hiring.',
            'category' => 'hiring',
            'icon' => 'users',
            'query_templates' => ['{location} hiring surge', '  '],
        ])
        ->assertSessionHasErrors('query_templates.1');
});

it('refuses a new type once the team is at its ceiling', function (): void {
    // **The plan's figure, not the module's config floor.** Since S16 the ceiling comes from
    // `SignalQuotaSource` and Billing's plan-backed source wins over
    // `signals.max_definitions_per_team`, so capping that key alone would cap the thing that gets
    // overridden.
    Billing::capSignalAllowance('max_definitions', 1);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    $this->actingAs($user)
        ->from(route('signal-definitions.index'))
        ->post(route('signal-definitions.store'), [
            'name' => 'One too many',
            'description' => 'Nope.',
            'category' => 'custom',
            'icon' => 'satellite',
            'query_templates' => ['{location} something'],
        ])
        ->assertRedirect(route('signal-definitions.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1);
});

it('still lets an existing type be edited at the ceiling', function (): void {
    // **The plan's figure, not the module's config floor.** Since S16 the ceiling comes from
    // `SignalQuotaSource` and Billing's plan-backed source wins over
    // `signals.max_definitions_per_team`, so capping that key alone would cap the thing that gets
    // overridden.
    Billing::capSignalAllowance('max_definitions', 1);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    // The cap is on create: an existing type is already counted.
    $this->actingAs($user)
        ->patch(route('signal-definitions.update', ['definition' => $definition->getKey()]), [
            'name' => 'Renamed',
            'description' => 'Still a round.',
            'category' => 'growth',
            'icon' => 'trending-up',
            'query_templates' => ['{location} seed round'],
        ])
        ->assertRedirect(route('signal-definitions.index'));

    expect($definition->refresh()->name)->toBe('Renamed');
});

// --- removal ----------------------------------------------------------------------------------

it('refuses to remove a type a watch still uses, and names the count', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    app(TeamContext::class)->run($team, function () use ($team, $definition): void {
        SignalWatch::factory()->watching([$definition])->create(['team_id' => $team->getKey()]);
    });

    // Removing it anyway would silently change what that watch looks for — and a watch that stops
    // finding a kind of event looks exactly like a quiet week.
    $this->actingAs($user)
        ->from(route('signal-definitions.index'))
        ->delete(route('signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertRedirect(route('signal-definitions.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(SignalDefinition::withoutGlobalScopes()->whereKey($definition->getKey())->exists())->toBeTrue();
});

it('removes an unused type and keeps the signals it found', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);
    $signal = definitionSignal($team, $definition);

    $this->actingAs($user)
        ->delete(route('signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertRedirect(route('signal-definitions.index'));

    // `withoutGlobalScope('team')` rather than `withoutGlobalScopes()`: the latter drops the
    // soft-delete scope as well, so it cannot answer "is this gone".
    expect(SignalDefinition::withoutGlobalScope('team')->whereKey($definition->getKey())->exists())->toBeFalse()
        // Soft-deleted, so even the attribution on the signals survives.
        ->and(SignalDefinition::withoutGlobalScope('team')->withTrashed()->whereKey($definition->getKey())->exists())->toBeTrue();

    $signal->refresh();

    // The signal is history and must still render: it keeps its key, and the FK is `nullOnDelete`
    // so a hard delete would leave the row intact too (ADR 0018 §4).
    expect($signal->event_key)->toBe(SignalPresetCatalogue::FUNDING_ROUND)
        ->and($signal->eventLabel())->not->toBe('');

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'signals.definition_deleted')->sole();

    // The count is the only place this number survives the delete.
    expect($audit->properties['signals_kept'])->toBe(1);
});

// --- review findings, pinned ------------------------------------------------------------------

it('restores a deleted clone when the preset is added again, rather than colliding', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    app(TeamContext::class)->run($team, function () use ($definition): void {
        $definition->forceFill(['name' => 'Rounds we care about'])->save();
    });

    $this->actingAs($user)
        ->delete(route('signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertRedirect();

    // `unique(scope_id, key)` knows nothing about `deleted_at`, so the soft-deleted row still holds
    // `funding_round`. Inserting a second one is a 500; the right answer is to bring theirs back,
    // tuning and all.
    $this->actingAs($user)
        ->post(route('signal-definitions.clone'), ['keys' => [SignalPresetCatalogue::FUNDING_ROUND]])
        ->assertRedirect(route('signal-definitions.index'))
        ->assertSessionHasNoErrors();

    $after = SignalDefinition::withoutGlobalScope('team')->where('team_id', $team->getKey())->get();

    expect($after)->toHaveCount(1)
        ->and($after->first()->getKey())->toBe($definition->getKey())
        ->and($after->first()->name)->toBe('Rounds we care about');
});

it('gives a cloned preset a free key when the team already used that key themselves', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A hand-authored type called "Acquisition" slugs to `acquisition` - the key the preset wants.
    $this->actingAs($user)->post(route('signal-definitions.store'), [
        'name' => 'Acquisition',
        'description' => 'Our own idea of one.',
        'category' => 'liquidity',
        'icon' => 'handshake',
        'query_templates' => ['{location} sold'],
    ])->assertRedirect();

    $this->actingAs($user)
        ->post(route('signal-definitions.clone'), ['keys' => [SignalPresetCatalogue::ACQUISITION]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $keys = SignalDefinition::withoutGlobalScope('team')
        ->where('team_id', $team->getKey())
        ->orderBy('id')
        ->pluck('key')
        ->all();

    // Both exist. The clone took the suffix, because the team's own type was there first.
    expect($keys)->toBe([SignalPresetCatalogue::ACQUISITION, 'acquisition_2']);
});

it('keeps naming a deleted type on the signals it already found', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $definition = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->custom('hiring_surge_uae', 'Hiring surge (UAE)')
        ->create(['team_id' => $team->getKey()]));

    $signal = definitionSignal($team, $definition);

    $this->actingAs($user)
        ->delete(route('signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertRedirect();

    // ADR 0018 section 4 promises a signal outlives its definition **with** its attribution. The
    // relation has to be `withTrashed()` for that to be true: a plain `belongsTo` returns null once
    // the type is soft-deleted, and every card, report row and lead-origin panel would silently
    // degrade to "Hiring Surge Uae".
    $reloaded = app(TeamContext::class)->run($team, fn (): Signal => Signal::query()
        ->with('definition')
        ->findOrFail($signal->getKey()));

    expect($reloaded->eventLabel())->toBe('Hiring surge (UAE)')
        ->and($reloaded->eventDescription())->not->toBeNull()
        ->and($reloaded->signal_definition_id)->toBe($definition->getKey());
});

// --- the platform boundary --------------------------------------------------------------------

it('404s on a preset rather than letting a team edit the library', function (): void {
    $user = createUserWithTeam();

    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::ACQUISITION);

    // A 404 before any policy runs: the controller's read is team-scoped, so a preset is not even
    // visible to a definition endpoint — a team clones one, it does not edit it.
    $this->actingAs($user)
        ->patch(route('signal-definitions.update', ['definition' => $preset->getKey()]), [
            'name' => 'Mine now',
            'description' => 'Hijacked.',
            'category' => 'custom',
            'icon' => 'satellite',
            'query_templates' => ['anything'],
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('signal-definitions.destroy', ['definition' => $preset->getKey()]))
        ->assertNotFound();

    expect($preset->refresh()->name)->toBe('Acquisition');
});

it('404s on another team’s type rather than admitting it exists', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $foreign = signalDefinition($otherTeam, SignalPresetCatalogue::FUNDING_ROUND);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->patch(route('signal-definitions.update', ['definition' => $foreign->getKey()]), [
            'name' => 'Mine now',
            'description' => 'Hijacked.',
            'category' => 'custom',
            'icon' => 'satellite',
            'query_templates' => ['anything'],
        ])
        ->assertNotFound();

    expect($foreign->refresh()->name)->toBe('Funding round');
});

it('stops a read-only member changing the library', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    $reader = createUserWithTeam();
    app(TeamMembership::class)->add($reader, $team, TeamRole::ReadOnly);
    $reader->switchTeam($team);

    // Read-only may see the library — that is the whole team's business — and may change nothing.
    $this->actingAs($reader)->get(route('signal-definitions.index'))->assertOk();

    $this->actingAs($reader)
        ->post(route('signal-definitions.store'), [
            'name' => 'Nope',
            'description' => 'Nope.',
            'category' => 'custom',
            'icon' => 'satellite',
            'query_templates' => ['{location} anything'],
        ])
        ->assertForbidden();

    $this->actingAs($reader)
        ->delete(route('signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertForbidden();

    $this->actingAs($reader)
        ->post(route('signal-definitions.clone'), ['keys' => [SignalPresetCatalogue::ACQUISITION]])
        ->assertForbidden();
});

// --- a watch cannot borrow another team's type ------------------------------------------------

it('drops a signal type that is not this team’s when a watch is saved', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $foreign = signalDefinition($otherTeam, SignalPresetCatalogue::ACQUISITION);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $mine = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    // Dropped and logged, not rejected — the same treatment an out-of-team ICP gets, because it can
    // only arrive from a tampered form and the member had every right to save their own watch.
    $this->actingAs($user)
        ->post(route('signal-watches.store'), [
            'name' => 'Mixed',
            'locations' => ['Dubai'],
            'regions' => ['ae'],
            'signal_definition_ids' => [$mine->getKey(), $foreign->getKey()],
            'cadence' => 'daily',
        ])
        ->assertRedirect(route('signal-watches.index'));

    $watch = SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($watch->definitions()->pluck('signal_definitions.id')->all())->toBe([$mine->getKey()]);
});

it('refuses a watch whose every signal type belongs to somebody else', function (): void {
    $other = createUserWithTeam();
    $foreign = signalDefinition($other->currentTeam()->firstOrFail(), SignalPresetCatalogue::ACQUISITION);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // Nothing left to watch for, so this one is refused rather than saved: a watch with no type is
    // a watch that can never find anything.
    $this->actingAs($user)
        ->from(route('signal-watches.index'))
        ->post(route('signal-watches.store'), [
            'name' => 'Empty',
            'locations' => ['Dubai'],
            'regions' => ['ae'],
            'signal_definition_ids' => [$foreign->getKey()],
            'cadence' => 'daily',
        ])
        ->assertRedirect(route('signal-watches.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0);
});

/*
 * The builder's two screens-worth of behaviour (S9). The draft and the preview arrive as **flashed**
 * data rather than props, which is exactly their lifetime — they last until the member saves or
 * walks away.
 */

it('flashes a composer draft back to the library screen', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $fake = new FakeAiTransport;
    app()->instance(AiTransport::class, $fake);
    app()->forgetInstance(AiClient::class);

    $fake->queueStructured([
        'is_viable' => true,
        'reason' => null,
        'summary' => 'A UK manufacturer changing hands.',
        'definition' => [
            'name' => 'Manufacturer sold',
            'description' => 'A manufacturing business changes hands.',
            'category' => 'liquidity',
            'icon' => 'handshake',
            'query_templates' => ['{location} manufacturer acquired'],
        ],
    ]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signal-definitions.compose'), [
            'brief' => 'Tell me when a UK manufacturer changes hands.',
        ])
        ->assertRedirect(route('signal-definitions.index'));

    // The brief goes back with it, so the box keeps what they typed — a composer that cleared the
    // text on a refusal would make its own reason unactionable.
    expect(session('inertia.flash_data.draft.viable'))->toBeTrue()
        ->and(session('inertia.flash_data.draft.definition.name'))->toBe('Manufacturer sold')
        ->and(session('inertia.flash_data.brief'))->toContain('manufacturer changes hands');

    // Still nothing in the library: the AI drafts and the member owns.
    expect(SignalDefinition::query()->count())->toBe(0);
});

it('flashes a preview result and nothing that could be mistaken for a draft', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $news = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($news);

    $fake = new FakeAiTransport;
    app()->instance(AiTransport::class, $fake);
    app()->forgetInstance(AiClient::class);

    $news->willReturn([$news->item('Acme Engineering sold', 'https://gulfbusiness.com/acme')]);
    $fake->queueStructured(['events' => [[
        'item_index' => 0,
        'is_event' => true,
        'event_type' => 'manufacturer_sold',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'Acme was sold.',
        'location' => 'London',
    ]]]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signal-definitions.preview'), [
            'name' => 'Manufacturer sold',
            'description' => 'A manufacturing business changes hands.',
            'category' => 'liquidity',
            'icon' => 'handshake',
            'query_templates' => ['{location} manufacturer acquired'],
        ])
        ->assertRedirect(route('signal-definitions.index'));

    // **Only the result is flashed.** It flashed the submitted draft back as well for one review
    // cycle, so that the form would survive the redirect — and the client could then not tell a
    // composer draft from an echo of its own form, so previewing a *saved* type reopened the editor
    // as a new one: pressing Save created a duplicate, and the reset had cleared the
    // unsaved-changes guard. The screen keeps its own state across this post instead.
    expect(session('inertia.flash_data.preview.kept_count'))->toBe(1)
        ->and(session('inertia.flash_data.draft'))->toBeNull();
});

it('refuses the composer to a member who may not manage types', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $viewer = createUserWithTeam();
    app(TeamMembership::class)->add($viewer, $team, TeamRole::ReadOnly);
    $viewer->switchTeam($team);

    $this->actingAs($viewer)
        ->post(route('signal-definitions.compose'), ['brief' => 'Tell me when a manufacturer changes hands.'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('signal-definitions.preview'), [
            'name' => 'Anything',
            'description' => 'Anything at all.',
            'category' => 'custom',
            'icon' => 'satellite',
            'query_templates' => ['{location} anything'],
        ])
        ->assertForbidden();
});
