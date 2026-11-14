<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiResearcher;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\AiWebSearch;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Providers\SignalsServiceProvider;
use App\Modules\Signals\Services\AiResearchSignalSource;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    config(['signals.sources.ai_research.enabled' => true]);

    $this->fakeNews = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($this->fakeNews);
    // Registered by hand: the registry may have been built before the switch above was thrown.
    app(SignalSourceRegistry::class)->register(SignalsServiceProvider::aiResearchSource(app()));

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

/**
 * A Dubai watch over the funding-round type, with AI research ticked on it.
 *
 * @return array{Team, SignalWatch}
 */
function researchWatch(): array
{
    $team = Team::factory()->create();

    $watch = app(TeamContext::class)->run($team, function () use ($team): SignalWatch {
        $watch = SignalWatch::factory()
            ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
            ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]);

        SignalDefinition::query()->where('team_id', $team->getKey())->update([
            'source_keys' => [SignalSourceKind::News->value, SignalSourceKind::AiResearch->value],
        ]);

        return $watch;
    });

    return [$team, $watch];
}

function researchRun(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

/**
 * @return list<array{url: string, title: ?string, page_age: ?string}>
 */
function pagesReturned(): array
{
    return [
        ['url' => 'https://gulftrade.example/al-noor-raises', 'title' => 'Al Noor Trading raises $15m', 'page_age' => null],
        ['url' => 'https://gulftrade.example/other-story', 'title' => 'Something else', 'page_age' => null],
    ];
}

it('charges the search fee, returns the pages searched, and never caches a research call', function (): void {
    AiPromptTemplate::factory()->key('test.research')->create([
        'body' => 'Find: {{subject}}',
        'schema' => ['type' => 'object', 'properties' => ['found' => ['type' => 'boolean']], 'required' => ['found']],
    ]);

    $team = Team::factory()->create();
    $ask = fn () => app(TeamContext::class)->run($team, fn () => app(AiResearcher::class)->research(
        'test.research',
        ['subject' => 'funding rounds'],
        new AiWebSearch(maxSearches: 2, country: 'AE'),
    ));

    $this->fakeAi->queueResearch(['found' => true], pagesReturned(), webSearches: 2, inputTokens: 1_000, outputTokens: 500);
    $response = $ask();

    // Sonnet: 1000 * 2 + 500 * 10 = 7_000, plus two searches at 10_000 each.
    expect($response->costMicros)->toBe(27_000)
        ->and($response->webSearches)->toBe(2)
        ->and(array_column($response->sources, 'url'))->toBe(['https://gulftrade.example/al-noor-raises', 'https://gulftrade.example/other-story'])
        ->and($this->fakeAi->lastRequest()?->webSearch?->maxSearches)->toBe(2)
        ->and(AiRequest::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sum('cost_micros'))->toEqual(27_000);

    // Asked again with the same input: the web has moved on, so the provider is asked again.
    $this->fakeAi->queueResearch(['found' => false], [], webSearches: 1);
    $ask();

    expect($this->fakeAi->callCount())->toBe(2);
});

it('keeps only events found on a page the search returned, and stores that page’s own link', function (): void {
    [$team, $watch] = researchWatch();

    // The news search finds nothing, so research is worth running.
    $this->fakeNews->willReturn([]);

    $this->fakeAi->queueResearch(['events' => [
        // Found on a returned page; the model tidied the URL, which still names the same page.
        ['source_url' => 'https://gulftrade.example/al-noor-raises/', 'headline' => 'Al Noor Trading raises $15m', 'summary' => 'Al Noor Trading raised $15m.', 'outlet' => 'Gulf Trade Journal', 'published' => now()->subDays(2)->toDateString()],
        // The model's own memory: no search returned this page.
        ['source_url' => 'https://elsewhere.example/remembered', 'headline' => 'Remembered Co raises $9m', 'summary' => 'Remembered.', 'outlet' => null, 'published' => null],
    ]], pagesReturned(), webSearches: 3);

    // The one grounded item then goes through the ordinary classifier.
    $this->fakeAi->queueStructured(['events' => [[
        ...signalEventDefaults(), 'item_index' => 0, 'is_event' => true, 'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'subject_company_name' => 'Al Noor Trading', 'confidence' => 90, 'relevance' => 80, 'summary' => 'Al Noor Trading raised $15m.', 'location' => 'Dubai',
    ]]]);

    $run = researchRun($team, $watch);
    $signals = Signal::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->get();

    expect($run->signals_created)->toBe(1)
        ->and($signals)->toHaveCount(1)
        ->and($signals[0]->source_kind)->toBe(SignalSourceKind::AiResearch)
        // The tool's URL, not the model's rendering of it.
        ->and($signals[0]->source_url)->toBe('https://gulftrade.example/al-noor-raises')
        ->and($signals[0]->subject_company_name)->toBe('Al Noor Trading');
});

it('is not asked when the other sources found enough', function (): void {
    [$team, $watch] = researchWatch();
    config(['signals.sources.ai_research.min_results' => 1]);

    $this->fakeNews->willReturn([$this->fakeNews->item('Acme closes $40m Series A', 'https://gulfbusiness.com/acme')]);
    $this->fakeAi->queueStructured(['events' => [[
        ...signalEventDefaults(), 'item_index' => 0, 'is_event' => true, 'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'subject_company_name' => 'Acme DMCC', 'confidence' => 90, 'relevance' => 80, 'summary' => 'Acme raised.', 'location' => 'Dubai',
    ]]]);

    researchRun($team, $watch);

    // One call: the classifier. Research would have been a second, and nothing was queued for it.
    expect($this->fakeAi->callCount())->toBe(1);
});

it('steps aside when the month’s allowance is spent, when it is switched off, and when it fails', function (): void {
    [$team, $watch] = researchWatch();
    $this->fakeNews->willReturn([]);

    // Spent: no call at all.
    $spent = AiRequest::factory()->create(['team_id' => $team->getKey(), 'prompt_key' => AiResearchSignalSource::PROMPT_KEY, 'cost_micros' => 2_000_000]);
    expect(researchRun($team, $watch)->status->value)->toBe('completed')
        ->and($this->fakeAi->callCount())->toBe(0)
        ->and(app(SignalSpendGuard::class)->remainingAiResearchMicros($team))->toBe(0);

    // Failing: the run still completes, and nothing is retried on its account.
    $spent->delete();
    $this->fakeAi->queueFailure(new AiTransportException('provider down'));
    expect(researchRun($team, $watch)->status->value)->toBe('completed');

    // Switched off for the platform: it declines every type, so it is not offered or planned.
    config(['signals.sources.ai_research.enabled' => false]);
    $calls = $this->fakeAi->callCount();
    researchRun($team, $watch);

    expect($this->fakeAi->callCount())->toBe($calls)
        ->and(SignalSourceKind::AiResearch->isAvailable())->toBeFalse()
        ->and(in_array(SignalSourceKind::AiResearch, SignalSourceKind::available(), true))->toBeFalse();
});

it('counts one team’s research spend against that team only', function (): void {
    [$team] = researchWatch();
    $other = Team::factory()->create();

    AiRequest::factory()->create(['team_id' => $team->getKey(), 'prompt_key' => AiResearchSignalSource::PROMPT_KEY, 'cost_micros' => 500_000]);

    $guard = app(SignalSpendGuard::class);

    expect($guard->remainingAiResearchMicros($team))->toBe(1_500_000)
        ->and($guard->remainingAiResearchMicros($other))->toBe(2_000_000);
});
