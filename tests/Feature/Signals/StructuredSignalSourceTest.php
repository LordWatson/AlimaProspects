<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
 * The run's structured partition (S13b, ADR 0023 §6) — end to end.
 *
 * ADR 0023 declared this at S13a and left every source returning null, so the partition shipped
 * unexercised. This is the file that exercises it: an item that arrives already classified must
 * become a signal **without the AI being called at all**, while still being refused if its type was
 * not offered or its floors are not met.
 *
 * The AI call count is asserted against the real `FakeAiTransport`, not a mock expectation, because
 * "no AI call" is the entire claim and the S12 review's lesson was that a test which could pass
 * without the call having happened proves nothing.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->registry = new FakeSignalSource(SignalSourceKind::CompaniesHouse);
    app(SignalSourceRegistry::class)->register($this->registry);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

/** A watch pointed at a one-account watchlist, running one definition on the registry. */
function registryWatch(Team $team, SignalDefinition $definition): SignalWatch
{
    return app(TeamContext::class)->run($team, function () use ($team, $definition): SignalWatch {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Greggs plc',
            'company_number' => '00502851',
            'company_number_checked_at' => now(),
        ]);

        return SignalWatch::factory()
            ->watching([$definition])
            ->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'locations' => [],
            ]);
    });
}

/** A definition that runs on the registry, mapping AP01 to itself. */
function registryDefinition(Team $team): SignalDefinition
{
    $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

    return app(TeamContext::class)->run($team, function () use ($definition): SignalDefinition {
        $definition->forceFill([
            'source_keys' => [SignalSourceKind::CompaniesHouse->value],
            'source_config' => ['companies_house' => ['filings' => ['ap01']]],
        ])->save();

        return $definition->refresh();
    });
}

/** A pre-classified item, as `CompaniesHouseSignalSource` would return one. */
function filingItem(FakeSignalSource $source, string $eventKey, int $confidence = 100, int $relevance = 100): SourceItem
{
    return $source->item(
        title: 'Greggs plc — director appointed: Mr Benjamin Jay Waldron',
        link: 'https://find-and-update.company-information.service.gov.uk/company/00502851/filing-history',
        snippet: 'appoint-person-director-company-with-name-date',
        source: 'Companies House',
        // `Illuminate\Support\Carbon`, not the `now()` helper: that returns a `CarbonImmutable`,
        // which is this class's **sibling** rather than its subclass, and `SourceItem` types the
        // former — the runtime TypeError no static check catches.
        publishedAt: Carbon::parse('-3 days'),
        classification: ExtractedSignal::structured(
            eventKey: $eventKey,
            subjectCompanyName: 'Greggs plc',
            summary: 'A director was appointed.',
            subjectPersonName: 'Mr Benjamin Jay Waldron',
            subjectPersonRole: 'Director',
            location: 'United Kingdom',
            confidence: $confidence,
            relevance: $relevance,
        ),
    );
}

it('turns a pre-classified filing into a signal without calling the AI', function (): void {
    $team = Team::factory()->create();
    $definition = registryDefinition($team);
    $watch = registryWatch($team, $definition);

    $this->registry->willReturn([filingItem($this->registry, $definition->key)]);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->signals_created)->toBe(1)
        // **The claim of the whole source.** No batch was sent, so nothing was spent.
        ->and($this->fakeAi->callCount())->toBe(0);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($signal->source_kind)->toBe(SignalSourceKind::CompaniesHouse)
        ->and($signal->subject_person_name)->toBe('Mr Benjamin Jay Waldron')
        ->and($signal->subject_person_role)->toBe('Director')
        ->and($signal->confidence)->toBe(100)
        // The registry is the UK register, so the jurisdiction resolves and a converted lead will
        // carry the UK restriction (ADR 0019 §4). This is the one source that cannot be unresolvable.
        ->and($signal->jurisdiction)->toBe('GB')
        ->and($signal->resolved_market)->not->toBeNull()
        // And it is still scored, clustered and ranked like anything else: skipping the AI is not
        // skipping the pipeline.
        ->and($signal->heat)->not->toBeNull()
        ->and($signal->score_breakdown)->not->toBeNull();

    // The registry costs nothing, and no AI call means no AI cost either — the only source in the
    // product that can find an event for free.
    expect($run->cost_micros)->toBe(0)
        ->and($run->source_breakdown['companies_house']['cost_micros'])->toBe(0)
        ->and($run->source_breakdown['companies_house']['results'])->toBe(1);
});

it('refuses a pre-classified item whose type the run did not offer', function (): void {
    $team = Team::factory()->create();
    $definition = registryDefinition($team);
    $watch = registryWatch($team, $definition);

    // A source cannot file an event under a type this watch is not watching — the same guard that
    // replaced the AI schema's enum at S7 (ADR 0018 §7), now applied to a source instead of a model.
    // "Structured" means the provider parsed it, not that it may choose the team's taxonomy.
    $this->registry->willReturn([filingItem($this->registry, 'a_type_nobody_offered')]);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->signals_created)->toBe(0)
        ->and($this->fakeAi->callCount())->toBe(0);
});

it('still applies the team’s own thresholds to a pre-classified item', function (): void {
    $team = Team::factory()->create();
    $definition = registryDefinition($team);

    // ADR 0023 §6's second rule: the source asserts a confidence, it does not assert that the team
    // wants the event. A floor the team raised is obeyed whoever produced the item.
    app(TeamContext::class)->run($team, fn () => $definition->forceFill([
        'thresholds' => ['min_confidence' => 90, 'min_relevance' => null],
    ])->save());

    $watch = registryWatch($team, $definition->refresh());

    $this->registry->willReturn([filingItem($this->registry, $definition->key, confidence: 50)]);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($run->signals_created)->toBe(0);
});

it('does not remember a thresholded filing as rejected', function (): void {
    $team = Team::factory()->create();
    $definition = registryDefinition($team);

    app(TeamContext::class)->run($team, fn () => $definition->forceFill([
        'thresholds' => ['min_confidence' => 90, 'min_relevance' => null],
    ])->save());

    $watch = registryWatch($team, $definition->refresh());
    $this->registry->willReturn([filingItem($this->registry, $definition->key, confidence: 50)]);

    app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    // The rejection ledger exists to avoid paying for the same **AI verdict** twice, and there was
    // no verdict to pay for. Remembering this would suppress the filing for thirty days on the
    // strength of a judgement nobody made — so lowering the floor must make it appear.
    app(TeamContext::class)->run($team, fn () => $definition->forceFill([
        'thresholds' => ['min_confidence' => 10, 'min_relevance' => null],
    ])->save());

    $watch->refresh();
    $this->registry->willReturn([filingItem($this->registry, $definition->key, confidence: 50)]);

    $second = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($second->signals_created)->toBe(1);
});

it('mixes a classified and an unclassified source in one run', function (): void {
    $team = Team::factory()->create();

    // One definition on the registry, one on the news. The run must batch only the news item.
    $registryType = registryDefinition($team);
    $newsType = signalDefinition($team, SignalPresetCatalogue::ACQUISITION);

    $news = new FakeSignalSource(SignalSourceKind::News);
    app(SignalSourceRegistry::class)->register($news);

    $watch = app(TeamContext::class)->run($team, function () use ($team, $registryType, $newsType): SignalWatch {
        $list = SignalWatchlist::factory()->create(['team_id' => $team->getKey()]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Company->value,
            'name' => 'Greggs plc',
            'company_number' => '00502851',
            'company_number_checked_at' => now(),
        ]);

        return SignalWatch::factory()
            ->watching([$registryType, $newsType])
            ->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'locations' => [],
            ]);
    });

    $this->registry->willReturn([filingItem($this->registry, $registryType->key)]);
    $news->willReturn([$news->item('Greggs acquires a bakery chain', 'https://gulfbusiness.com/greggs-deal', source: 'Gulf Business')]);
    $this->fakeAi->queueStructured(['events' => [aiEventFor($newsType->key)]]);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    // Two events, one AI call: the filing went straight through and only the article was batched.
    expect($run->signals_created)->toBe(2)
        ->and($this->fakeAi->callCount())->toBe(1);

    $kinds = app(TeamContext::class)->run($team, fn (): array => Signal::query()
        ->pluck('source_kind')
        ->map(fn ($kind): string => $kind instanceof SignalSourceKind ? $kind->value : (string) $kind)
        ->sort()
        ->values()
        ->all());

    expect($kinds)->toBe(['companies_house', 'news']);
});
