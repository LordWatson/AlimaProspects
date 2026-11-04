<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\SubmitSignalDefinitionAction;
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

/*
 * The builder's two endpoints (S9). Both 200 with a body and **neither creates anything**, which is
 * the whole point of them: the AI drafts, the caller owns.
 */

it('drafts a signal type from a brief over the API', function (): void {
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
            'min_confidence' => 60,
            'min_relevance' => 40,
        ],
    ]);

    Sanctum::actingAs($this->user, ['*']);

    $response = $this->postJson(route('api.v1.signal-definitions.compose'), [
        'brief' => 'Tell me when a UK manufacturer changes hands.',
    ])->assertOk();

    expect($response->json('data.viable'))->toBeTrue()
        // The draft is shaped like the **save payload**, so a client can post it straight back.
        ->and($response->json('data.definition.name'))->toBe('Manufacturer sold')
        ->and($response->json('data.definition.query_templates'))->toBe(['{location} manufacturer acquired']);

    // 200 and nothing created.
    expect(SignalDefinition::query()->count())->toBe(0);
});

it('returns a refusal with its reason rather than an error', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $fake = new FakeAiTransport;
    app()->instance(AiTransport::class, $fake);
    app()->forgetInstance(AiClient::class);

    $fake->queueStructured([
        'is_viable' => false,
        'reason' => 'That describes people rather than an event.',
        'summary' => null,
        'definition' => null,
    ]);

    Sanctum::actingAs($this->user, ['*']);

    // **200, not 422.** The caller asked a reasonable question and got a usable answer: a refusal
    // with a reason is the composer working, not the request being wrong.
    $this->postJson(route('api.v1.signal-definitions.compose'), [
        'brief' => 'find me rich people in Dubai',
    ])
        ->assertOk()
        ->assertJsonPath('data.viable', false)
        ->assertJsonPath('data.reason', 'That describes people rather than an event.');
});

it('validates the brief it is given', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson(route('api.v1.signal-definitions.compose'), ['brief' => 'nope'])
        ->assertApiValidationErrors(['brief']);
});

it('previews a definition over the API without persisting a signal', function (): void {
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

    Sanctum::actingAs($this->user, ['*']);

    $response = $this->postJson(route('api.v1.signal-definitions.preview'), [
        'name' => 'Manufacturer sold',
        'description' => 'A manufacturing business changes hands.',
        'category' => 'liquidity',
        'icon' => 'handshake',
        'query_templates' => ['{location} manufacturer acquired'],
    ])->assertOk();

    expect($response->json('data.kept_count'))->toBe(1)
        ->and($response->json('data.items.0.verdict'))->toBe('kept')
        // The diagnosis is server-side, because "no news", "no events" and "all rejected" are
        // three different fixes and a client re-deriving which is a second implementation.
        ->and($response->json('data.diagnosis'))->toContain('works');

    expect(Signal::query()->count())->toBe(0);
});

it('validates a preview against the same rules as a save', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    // No templates, no name: a preview of something that could not be saved would answer a
    // question nobody asked.
    $this->postJson(route('api.v1.signal-definitions.preview'), ['query_templates' => []])
        ->assertApiValidationErrors(['name', 'description', 'category', 'icon', 'query_templates']);
});

// --- The library, over the API (S15 tasks 4 and 5) -----------------------------------------------

/*
 * The same Actions behind the API as behind the screen, which is this file's whole premise. The two
 * things worth asserting separately are the **scopes** — reading what you offered is not the same
 * credential right as offering something — and that an **empty `fields`** survives the round trip,
 * because it is a real answer and `present` rather than `required` is what lets it through.
 */

/** A type of this team's own, with searches, so it may be offered. */
function apiOwnType(array $attributes = []): SignalDefinition
{
    $team = test()->team;

    return app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'key' => 'mine_to_offer',
        'preset_key' => null,
        'preset_version' => null,
        'query_templates' => ['"{company}" raises'],
    ], $attributes)));
}

it('offers a type to the library, lists what it offered and takes it back', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $definition = apiOwnType(['name' => 'Series B rounds']);

    $created = $this->postJson(
        route('api.v1.signal-definitions.submit', ['definition' => $definition->getKey()]),
        ['note' => 'Three deals from this one.'],
    )->assertCreated();

    expect($created->json('data.status'))->toBe('pending')
        ->and($created->json('data.definition_name'))->toBe('Series B rounds')
        // Prose, deliberately: one person's words to another.
        ->and($created->json('data.note'))->toBe('Three deals from this one.')
        // Translation keys, so the client renders them in its own language.
        ->and($created->json('data.status_label'))->toBe('Waiting for review');

    $this->getJson(route('api.v1.signal-definitions.submissions'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->deleteJson(route('api.v1.signal-definitions.submissions.withdraw', [
        'submission' => $created->json('data.id'),
    ]))->assertOk()->assertJsonPath('data.status', 'withdrawn');

    // And the type is untouched throughout.
    expect($definition->fresh()->name)->toBe('Series B rounds');
});

it('refuses to offer a type with no searches', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $definition = apiOwnType(['query_templates' => []]);

    // The module's own error envelope, not a validation bag: whether a type is worth offering is a
    // domain rule rather than a shape rule.
    $this->postJson(route('api.v1.signal-definitions.submit', ['definition' => $definition->getKey()]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['code', 'message']]);
});

it('keeps one team out of another team submissions over the API', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    $theirs = app(TeamContext::class)->run($otherTeam, fn (): SignalDefinition => SignalDefinition::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'key' => 'theirs_to_offer',
        'query_templates' => ['"{company}" raises'],
    ]));

    $submission = app(SubmitSignalDefinitionAction::class)->handle($otherTeam, $other, $theirs);

    Sanctum::actingAs($this->user, ['*']);

    $this->deleteJson(route('api.v1.signal-definitions.submissions.withdraw', [
        'submission' => $submission->getKey(),
    ]))->assertNotFound();

    $this->getJson(route('api.v1.signal-definitions.submissions'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('reads the library offer and takes one field of it', function (): void {
    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::FUNDING_ROUND);

    // The library has moved on since this team took their copy.
    $preset->forceFill([
        'version' => $preset->version + 1,
        'query_templates' => ['"{company}" closes funding round'],
    ])->save();

    $clone = app(TeamContext::class)->run($this->team, fn (): SignalDefinition => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->create([
            'team_id' => $this->team->getKey(),
            'preset_version' => 1,
            'name' => 'What we call it',
            'query_templates' => ['"{company}" funding'],
        ]));

    Sanctum::actingAs($this->user, ['*']);

    $offers = $this->getJson(route('api.v1.signal-definitions.preset-offers'))->assertOk();

    expect($offers->json('data.offers.'.$clone->getKey().'.preset_version'))->toBe($preset->version)
        ->and($offers->json('data.fields'))->toContain('query_templates');

    $this->patchJson(
        route('api.v1.signal-definitions.adopt-preset', ['definition' => $clone->getKey()]),
        ['fields' => ['query_templates']],
    )->assertOk();

    expect($clone->fresh()->query_templates)->toBe(['"{company}" closes funding round'])
        // Their name is theirs. Taking the templates is a different decision.
        ->and($clone->fresh()->name)->toBe('What we call it');
});

it('accepts an empty answer to a library offer', function (): void {
    $preset = app(PlatformPresetReader::class)->byKey(SignalPresetCatalogue::FUNDING_ROUND);
    $preset->forceFill(['version' => $preset->version + 1, 'query_templates' => ['something new']])->save();

    $clone = app(TeamContext::class)->run($this->team, fn (): SignalDefinition => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->create([
            'team_id' => $this->team->getKey(),
            'preset_version' => 1,
            'query_templates' => ['"{company}" funding'],
        ]));

    Sanctum::actingAs($this->user, ['*']);

    // `present`, not `required`: "I have seen it and I am keeping mine" is the answer that stops
    // the offer coming back, and a rule that rejected it would make the panel ask for ever.
    $this->patchJson(
        route('api.v1.signal-definitions.adopt-preset', ['definition' => $clone->getKey()]),
        ['fields' => []],
    )->assertOk();

    expect($clone->fresh()->query_templates)->toBe(['"{company}" funding'])
        ->and($clone->fresh()->preset_version)->toBe($preset->version);
});

it('asks for a write scope to offer a type and a read scope to see the offers', function (): void {
    $definition = apiOwnType();

    // A read credential may look at both sides of the library and change neither.
    Sanctum::actingAs($this->user, [TokenAbility::SignalsRead->value]);

    $this->getJson(route('api.v1.signal-definitions.preset-offers'))->assertOk();
    $this->getJson(route('api.v1.signal-definitions.submissions'))->assertOk();

    $this->postJson(route('api.v1.signal-definitions.submit', ['definition' => $definition->getKey()]))
        ->assertForbidden();
    $this->patchJson(
        route('api.v1.signal-definitions.adopt-preset', ['definition' => $definition->getKey()]),
        ['fields' => []],
    )->assertForbidden();
});
