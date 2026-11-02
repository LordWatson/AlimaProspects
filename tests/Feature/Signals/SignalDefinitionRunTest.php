<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeNewsSearchClient;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * What a run does now that signal types are rows (S7, ADR 0018).
 *
 * The first test here is the one that matters most. v1 constrained the AI's `event_type` with a
 * JSON-schema `enum`, so a response naming a type outside the set was refused before our code saw
 * it — **the schema was the guard**. With types as data that enum cannot exist, so
 * `RunSignalWatchAction::resolveDefinition()` took the job. A prompt describes a constraint; it does
 * not enforce one, and this file is where that claim is checked.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeNewsSearchClient;
    app()->instance(NewsSearchClient::class, $this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aiEventFor(string $eventKey, array $overrides = []): array
{
    return [
        'item_index' => 0,
        'is_event' => true,
        'event_type' => $eventKey,
        'subject_person_name' => 'Jane Founder',
        'subject_person_role' => 'Founder & CEO',
        'subject_company_name' => 'Acme DMCC',
        'counterparty_name' => null,
        'amount' => null,
        'currency' => null,
        'location' => 'Dubai',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'Acme did something notable.',
        'suggested_approach' => 'Ask a mutual connection for a LinkedIn introduction.',
        ...$overrides,
    ];
}

/**
 * A watch over the given definitions, run once against a single canned article.
 *
 * @param  list<SignalDefinition>  $definitions
 * @param  array<string, mixed>  $aiResponse
 */
function runOver(Team $team, array $definitions, array $aiResponse): SignalRun
{
    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watching($definitions)
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));

    test()->fakeNews->willReturn([
        test()->fakeNews->item('Acme did something notable', 'https://gulfbusiness.com/acme'),
    ]);
    test()->fakeAi->queueStructured($aiResponse);

    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

it('drops an item whose event type was not one the run offered', function (): void {
    $team = Team::factory()->create();
    $funding = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    // The watch offered `funding_round` only. A response naming anything else is either a
    // hallucination or a steered answer, and in both cases writing it would file a signal under a
    // type the team never asked to watch for.
    $run = runOver($team, [$funding], ['events' => [aiEventFor('ipo_filed')]]);

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->signals_created)->toBe(0);

    expect(app(TeamContext::class)->run($team, fn (): int => Signal::query()->count()))->toBe(0);
});

it('drops an item naming a type the team owns but this watch does not watch', function (): void {
    $team = Team::factory()->create();
    $funding = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);
    signalDefinition($team, SignalPresetCatalogue::ACQUISITION);

    // Owning a type is not the same as watching for it. The guard is per **run**, not per team.
    $run = runOver($team, [$funding], ['events' => [aiEventFor(SignalPresetCatalogue::ACQUISITION)]]);

    expect($run->signals_created)->toBe(0);
});

it('writes both the definition id and the stored key for a type it did offer', function (): void {
    $team = Team::factory()->create();
    $funding = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    $run = runOver($team, [$funding], ['events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND)]]);

    expect($run->signals_created)->toBe(1);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    // The id is the live attribution; the key is what survives the type being deleted
    // (ADR 0018 §4). The key comes off the **resolved definition**, not off the model's answer, so
    // a row can never carry a key the run did not offer.
    expect($signal->signal_definition_id)->toBe($funding->getKey())
        ->and($signal->event_key)->toBe(SignalPresetCatalogue::FUNDING_ROUND);
});

it('finds a hand-authored type the team wrote themselves', function (): void {
    $team = Team::factory()->create();

    $hiring = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->custom()
        ->create(['team_id' => $team->getKey()]));

    // The phase's point, end to end: a type nobody shipped produces a signal.
    $run = runOver($team, [$hiring], ['events' => [aiEventFor('hiring_surge')]]);

    expect($run->signals_created)->toBe(1);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($signal->event_key)->toBe('hiring_surge')
        ->and($signal->signal_definition_id)->toBe($hiring->getKey());
});

it('applies the definition’s own thresholds over the global ones', function (): void {
    $team = Team::factory()->create();

    // The global floors are 60/40. This type was found too noisy and had its confidence raised.
    $strict = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->withThresholds(['min_confidence' => 95])
        ->create(['team_id' => $team->getKey()]));

    expect($strict->effectiveThresholds())->toBe(['min_confidence' => 95, 'min_relevance' => 40]);

    $run = runOver($team, [$strict], [
        'events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND, ['confidence' => 90])],
    ]);

    // 90 clears the product's floor and not this type's, which is the whole reason the column
    // exists: a team tunes one type without touching the rest.
    expect($run->signals_created)->toBe(0);
});

it('completes an empty run rather than failing when every type is paused', function (): void {
    $team = Team::factory()->create();

    $paused = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->inactive()
        ->create(['team_id' => $team->getKey()]));

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watching([$paused])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    // Nothing went wrong, so a Failed run would be a lie that reads as the news provider breaking.
    // Nothing was searched for either, so nothing was spent.
    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->queries_count)->toBe(0)
        ->and($run->cost_micros)->toBe(0)
        ->and($run->signals_created)->toBe(0)
        ->and($this->fakeNews->queries())->toBe([]);
});

it('offers the AI each type’s key, name and rules', function (): void {
    $team = Team::factory()->create();

    $funding = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->create(['team_id' => $team->getKey()]));

    runOver($team, [$funding], ['events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND)]]);

    $prompt = $this->fakeAi->lastRequest()->prompt;

    // The enum's three hard-coded descriptions used to carry this; now it is per team, and the
    // exclusions are the half that keeps round-ups and trackers out.
    expect($prompt)->toContain(SignalPresetCatalogue::FUNDING_ROUND)
        ->and($prompt)->toContain('Funding round')
        ->and($prompt)->toContain('Does NOT count')
        ->and($prompt)->toContain('funding trackers');
});
