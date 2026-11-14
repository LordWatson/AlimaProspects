<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Actions\DraftSignalSuggestionsAction;
use App\Modules\Signals\Actions\RequestSignalSuggestionsAction;
use App\Modules\Signals\Actions\TestSignalSuggestionAction;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Http\Resources\SignalSuggestionBatchResource;
use App\Modules\Signals\Jobs\DraftSignalSuggestionsJob;
use App\Modules\Signals\Jobs\TestSignalSuggestionJob;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Database\Seeders\SignalPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    $this->seed(SignalPresetSeeder::class);

    $this->fakeNews = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

/**
 * @return array{Team, User}
 */
function suggestionTeam(bool $withProfile = true): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $team->forceFill(['market' => 'uae'])->save();

    if ($withProfile) {
        app(TeamContext::class)->run($team, fn () => BusinessProfile::query()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Raseed',
            'industry' => 'Software',
            'value_proposition' => 'E-invoicing for UAE trading companies.',
            'products_services' => ['E-invoicing'],
        ]));
    }

    return [$team, $user];
}

/**
 * @return array<string, mixed>
 */
function draftedDefinition(string $name = 'Distributor opens a warehouse'): array
{
    return [
        'name' => $name,
        'description' => 'A trading company opens or expands a warehouse.',
        'category' => 'growth',
        'icon' => 'building-2',
        'query_templates' => ['{location} distributor opens new warehouse', 'trading company expands warehouse'],
        'match_keywords' => ['warehouse'],
        'includes' => ['a named company opening a named site'],
        'excludes' => ['round-ups'],
        'min_confidence' => 60,
        'min_relevance' => 40,
        'suggested_approach_hint' => 'Ask a mutual connection for an introduction.',
    ];
}

it('queues one batch, hands back the one in flight, and refuses a second inside a day', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();
    $action = app(RequestSignalSuggestionsAction::class);

    $batch = $action->handle($team, $user);

    expect($batch->status)->toBe(SignalSuggestionStatus::Pending)
        ->and($batch->requested_by)->toBe($user->getKey());
    Queue::assertPushed(DraftSignalSuggestionsJob::class, 1);

    // A double click is one request.
    expect($action->handle($team, $user)->getKey())->toBe($batch->getKey());
    Queue::assertPushed(DraftSignalSuggestionsJob::class, 1);

    $batch->forceFill(['status' => SignalSuggestionStatus::Ready, 'suggestions' => []])->save();

    expect(fn () => $action->handle($team, $user))->toThrow(SignalsException::class, 'already suggested');
});

it('refuses a team with no business profile before anything is spent', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam(withProfile: false);

    expect(fn () => app(RequestSignalSuggestionsAction::class)->handle($team, $user))
        ->toThrow(SignalsException::class, 'business profile');

    Queue::assertNothingPushed();
    expect(SignalSuggestionBatch::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->fakeAi->callCount())->toBe(0);
});

it('keeps a library type by key and a valid draft, and drops what it cannot stand behind', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();
    $batch = app(RequestSignalSuggestionsAction::class)->handle($team, $user);

    // The team already has the funding-round type, so suggesting it again is noise.
    app(TeamContext::class)->run($team, fn () => SignalDefinition::factory()
        ->fromCatalogue(SignalPresetCatalogue::FUNDING_ROUND)
        ->create(['team_id' => $team->getKey(), 'preset_key' => SignalPresetCatalogue::FUNDING_ROUND]));

    $this->fakeAi->queueStructured(['proposals' => [
        ['title' => 'An acquisition', 'rationale' => 'A buyer merges two ledgers.', 'preset_key' => 'acquisition', 'definition' => null],
        ['title' => 'A new warehouse', 'rationale' => 'More stock sold on credit.', 'preset_key' => null, 'definition' => draftedDefinition()],
        ['title' => 'Already theirs', 'rationale' => 'They have it.', 'preset_key' => SignalPresetCatalogue::FUNDING_ROUND, 'definition' => null],
        ['title' => 'Invented key', 'rationale' => 'No such type.', 'preset_key' => 'not_in_the_library', 'definition' => null],
        ['title' => 'Unusable draft', 'rationale' => 'No templates.', 'preset_key' => null, 'definition' => [...draftedDefinition('Empty'), 'query_templates' => []]],
    ]]);

    $batch = app(DraftSignalSuggestionsAction::class)->handle($batch->refresh());

    expect($batch->status)->toBe(SignalSuggestionStatus::Ready)
        ->and(array_column($batch->suggestions, 'title'))->toBe(['An acquisition', 'A new warehouse'])
        ->and($batch->suggestions[0]['preset_key'])->toBe('acquisition')
        ->and($batch->suggestions[1]['definition']['name'])->toBe('Distributor opens a warehouse')
        // Nothing is saved: the only definition is the one the team already had.
        ->and(SignalDefinition::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1);

    Queue::assertPushed(TestSignalSuggestionJob::class, 2);
});

it('tests a suggestion against a sample search and ranks what was found first', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();
    $batch = app(RequestSignalSuggestionsAction::class)->handle($team, $user);

    $this->fakeAi->queueStructured(['proposals' => [
        ['title' => 'Finds nothing', 'rationale' => 'A quiet one.', 'preset_key' => null, 'definition' => draftedDefinition('Quiet type')],
        ['title' => 'A new warehouse', 'rationale' => 'More stock sold on credit.', 'preset_key' => null, 'definition' => draftedDefinition()],
    ]]);
    $batch = app(DraftSignalSuggestionsAction::class)->handle($batch->refresh());

    // First suggestion: the search finds nothing at all.
    $this->fakeNews->willReturn([]);
    app(TestSignalSuggestionAction::class)->handle($batch->refresh(), 0);

    // Second: one article, which the classifier reads as a real event of this type.
    $this->fakeNews->willReturn([$this->fakeNews->item('Al Noor Trading opens Sharjah warehouse', 'https://gulf.example/al-noor')]);
    $this->fakeAi->queueStructured(['events' => [[
        ...signalEventDefaults(), 'item_index' => 0, 'is_event' => true, 'event_type' => 'distributor_opens_a_warehouse',
        'subject_company_name' => 'Al Noor Trading', 'confidence' => 90, 'relevance' => 80, 'summary' => 'Al Noor opened a warehouse.',
        'location' => 'Dubai',
    ]]]);
    app(TestSignalSuggestionAction::class)->handle($batch->refresh(), 1);

    $resource = (new SignalSuggestionBatchResource($batch->refresh()))->toArray(request());

    expect($resource['working'])->toBeFalse()
        ->and(array_column($resource['suggestions'], 'title'))->toBe(['A new warehouse', 'Finds nothing'])
        ->and($resource['suggestions'][0]['preview']['kept'])->toBe(1)
        ->and($resource['suggestions'][0]['draft']['definition']['name'])->toBe('Distributor opens a warehouse')
        ->and($resource['suggestions'][1]['preview']['results'])->toBe(0)
        // The sample searches are on the preview ledger, like one a member runs by hand.
        ->and(DB::table('signal_previews')->where('team_id', $team->getKey())->count())->toBe(2)
        // And a second call for a suggestion already tested does nothing.
        ->and(fn () => app(TestSignalSuggestionAction::class)->handle($batch->refresh(), 1))->not->toThrow(Throwable::class);
});

it('marks the batch failed when the draft cannot be made, rather than leaving the screen waiting', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();
    $batch = app(RequestSignalSuggestionsAction::class)->handle($team, $user);

    // Not schema-valid, twice: the client's own retry is used up.
    $this->fakeAi->queueStructured(['nonsense' => true]);
    $this->fakeAi->queueStructured(['nonsense' => true]);

    $batch = app(DraftSignalSuggestionsAction::class)->handle($batch->refresh());

    expect($batch->status)->toBe(SignalSuggestionStatus::Failed);
    Queue::assertNotPushed(TestSignalSuggestionJob::class);
});

it('never shows one team another team’s suggestions', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();
    [$other, $stranger] = suggestionTeam();

    app(RequestSignalSuggestionsAction::class)->handle($team, $user);

    $seen = app(TeamContext::class)->run($other, fn () => SignalSuggestionBatch::query()->count());

    expect($seen)->toBe(0);

    $this->actingAs($stranger)
        ->get(route('signal-definitions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('suggestions', null));
});

it('asks through the route and shows the pending batch on the page', function (): void {
    Queue::fake();
    [$team, $user] = suggestionTeam();

    $this->actingAs($user)
        ->post(route('signal-definitions.suggest'))
        ->assertRedirect(route('signal-definitions.index'));

    Queue::assertPushed(DraftSignalSuggestionsJob::class);

    $this->actingAs($user)
        ->get(route('signal-definitions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('suggestions.status', 'pending')
            ->where('suggestions.working', true));
});
