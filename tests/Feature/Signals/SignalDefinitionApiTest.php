<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Database\Seeders\SignalPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
 * `/api/v1` parity for signal definitions (S7, ADR 0018) — the same Actions behind the API as
 * behind the screen, so there is one definition of what creating, editing or cloning a type means.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SignalPresetSeeder::class);

    $this->user = createUserWithTeam();
    $this->team = $this->user->currentTeam()->firstOrFail();
});

it('lists, shows, creates, updates and deletes signal types', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $created = $this->postJson(route('api.v1.signal-definitions.store'), [
        'name' => 'Hiring surge',
        'description' => 'A business is hiring hard.',
        'category' => 'hiring',
        'icon' => 'users',
        'query_templates' => ['{location} company hiring surge'],
        'min_confidence' => 70,
    ])
        ->assertCreated()
        ->assertApiVersion()
        ->assertJsonPath('data.key', 'hiring_surge')
        ->assertJsonPath('data.is_preset', false)
        ->assertJsonPath('data.min_confidence', 70)
        // Both the stored value and the floor that applies, so a client never has to know about
        // the null fallback.
        ->assertJsonPath('data.effective_min_relevance', 40)
        ->json('data.id');

    $this->getJson(route('api.v1.signal-definitions.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'hiring_surge');

    $this->getJson(route('api.v1.signal-definitions.show', ['definition' => $created]))
        ->assertOk()
        ->assertJsonPath('data.watches_count', 0)
        ->assertJsonPath('data.signals_count', 0);

    $this->patchJson(route('api.v1.signal-definitions.update', ['definition' => $created]), [
        'name' => 'Hiring hard',
        'description' => 'Still hiring.',
        'category' => 'hiring',
        'icon' => 'users',
        'query_templates' => ['{location} hiring spree'],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hiring hard')
        // The key never changes: every signal of this type carries a copy of it.
        ->assertJsonPath('data.key', 'hiring_surge')
        ->assertJsonPath('data.version', 2);

    $this->deleteJson(route('api.v1.signal-definitions.destroy', ['definition' => $created]))
        ->assertNoContent();

    expect(SignalDefinition::withoutGlobalScope('team')->whereKey($created)->exists())->toBeFalse();
});

it('serves the preset library read-only', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    // A test team's market is `uk` (the `teams.market` default), so the library it is offered is
    // the **UK pack** — which is the S8 behaviour worth asserting: a UK team is not shown templates
    // that name Dubai.
    $this->getJson(route('api.v1.signal-definitions.presets'))
        ->assertOk()
        ->assertJsonCount(count(SignalPresetCatalogue::keysForMarket(Market::Uk)), 'data')
        ->assertJsonPath('data.0.is_preset', true)
        // A preset's wording is ours, so a client knows to translate it.
        ->assertJsonPath('data.0.translatable', true);

    // A preset is reached through the library, never through the definition endpoints: a team
    // clones one (ADR 0018 §3), and the team-scoped read makes it a 404 before any policy runs.
    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::ACQUISITION);

    $this->getJson(route('api.v1.signal-definitions.show', ['definition' => $preset->getKey()]))
        ->assertNotFound();
});

it('clones presets into the team’s own types', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson(route('api.v1.signal-definitions.clone'), [
        'keys' => [SignalPresetCatalogue::ACQUISITION, SignalPresetCatalogue::RELOCATION],
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.is_preset', false)
        ->assertJsonPath('data.0.preset_key', SignalPresetCatalogue::ACQUISITION);

    expect(SignalDefinition::withoutGlobalScope('team')->where('team_id', $this->team->getKey())->count())->toBe(2);
});

it('validates a signal type through the standard envelope', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson(route('api.v1.signal-definitions.store'), [
        'name' => '',
        'description' => '',
        'category' => 'nonsense',
        'icon' => 'nonsense',
        'query_templates' => [],
    ])
        ->assertStatus(422)
        ->assertApiValidationErrors(['name', 'description', 'category', 'icon', 'query_templates']);
});

it('reports a type still in use through the error envelope', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $definition = signalDefinition($this->team, SignalPresetCatalogue::FUNDING_ROUND);

    app(TeamContext::class)->run($this->team, function (): void {
        SignalWatch::factory()
            ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
            ->create(['team_id' => $this->team->getKey()]);
    });

    $this->deleteJson(route('api.v1.signal-definitions.destroy', ['definition' => $definition->getKey()]))
        ->assertStatus(422)
        ->assertApiError('signals.definition_in_use');
});

it('stops a read-only token writing signal types', function (): void {
    // A scope asks what a *credential* may do (ADR 0013). A read token can list the library and
    // change nothing in it.
    Sanctum::actingAs($this->user, [TokenAbility::SignalsRead->value]);

    $this->getJson(route('api.v1.signal-definitions.index'))->assertOk();
    $this->getJson(route('api.v1.signal-definitions.presets'))->assertOk();

    $this->postJson(route('api.v1.signal-definitions.store'), [
        'name' => 'Nope',
        'description' => 'Nope.',
        'category' => 'custom',
        'icon' => 'satellite',
        'query_templates' => ['{location} anything'],
    ])->assertForbidden();

    $this->postJson(route('api.v1.signal-definitions.clone'), [
        'keys' => [SignalPresetCatalogue::ACQUISITION],
    ])->assertForbidden();
});

it('404s on another team’s signal type', function (): void {
    $other = createUserWithTeam();
    $foreign = signalDefinition($other->currentTeam()->firstOrFail(), SignalPresetCatalogue::FUNDING_ROUND);

    Sanctum::actingAs($this->user, ['*']);

    $this->getJson(route('api.v1.signal-definitions.show', ['definition' => $foreign->getKey()]))
        ->assertNotFound();

    $this->deleteJson(route('api.v1.signal-definitions.destroy', ['definition' => $foreign->getKey()]))
        ->assertNotFound();
});
