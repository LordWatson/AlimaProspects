<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Enums\SignalRejectionVerdict;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRejection;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Remembering what was rejected (S11) — the S3 trade-off (b), closed.
 *
 * A weekly round-up sitting inside the recency window used to cost an AI batch **every single day**
 * for as long as it stayed there. These tests are about the two halves of not doing that: writing
 * the verdict down, and honouring it only for the question it answered.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);

    // **The AI Platform's own response cache is off for this file**, and that is the difference
    // between a test of the rejection ledger and a test of nothing. The cache is keyed on the
    // rendered prompt, so re-running a watch over the same article is a cache hit and the transport
    // is never reached - which looks exactly like the ledger having skipped the item. With it off,
    // `callCount()` means "the run paid to classify this", which is the whole claim.
    config()->set('ai-platform.cache.enabled', false);
});

/**
 * @param  list<string>  $keys
 */
function rejectionWatch(Team $team, array $keys = [SignalPresetCatalogue::FUNDING_ROUND]): SignalWatch
{
    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets($keys)
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));
}

function rejectionRun(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

function roundUp(FakeSignalSource $news): array
{
    return [$news->item(
        title: 'The ten biggest Dubai deals of the year so far',
        link: 'https://example.test/round-up-43',
        snippet: 'A look back at the deals that shaped the year.',
        source: 'Gulf Business',
    )];
}

it('remembers a non-event and skips it on the next run', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    // The model says: not one of your events.
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0,
        'is_event' => false,
        'event_type' => null,
        'confidence' => 10,
        'relevance' => 10,
        'summary' => '',
        'suggested_approach' => '',
    ]]]);

    rejectionRun($team, $watch);

    $rejection = SignalRejection::query()->withoutGlobalScopes()->firstOrFail();

    expect($rejection->verdict)->toBe(SignalRejectionVerdict::NotEvent)
        ->and($rejection->seen_count)->toBe(1);

    // Second run: the same article comes back and must not reach the model at all. No response is
    // queued, so an AI call here would fail the test rather than quietly pass it.
    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $second = rejectionRun($team, $watch);

    expect($this->fakeAi->callCount())->toBe(1)
        ->and($second->signals_created)->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(0)
        // And the count is the measure of whether the memory earns its keep.
        ->and($rejection->refresh()->seen_count)->toBe(1);
});

it('remembers a real event that missed the floor, separately', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0,
        'is_event' => true,
        'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'subject_company_name' => 'Acme DMCC',
        'confidence' => 90,
        // Under the relevance floor.
        'relevance' => 5,
        'summary' => 'A real round, not for this team.',
        'suggested_approach' => 'Ask for a LinkedIn introduction.',
    ]]]);

    rejectionRun($team, $watch);

    // The two verdicts are different conversations for somebody tuning a type: the rules, or the
    // floor.
    expect(SignalRejection::query()->withoutGlobalScopes()->firstOrFail()->verdict)
        ->toBe(SignalRejectionVerdict::BelowThreshold);
});

it('asks again when the team adds a signal type', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0, 'is_event' => false, 'event_type' => null,
        'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
    ]]]);
    rejectionRun($team, $watch);

    // A different watch over two types is a **different question** about the same article, so the
    // fingerprint changes and the article is classified again. The alternative — one answer per
    // article — would mean a team that bought a new signal type never saw it applied to anything
    // already in the window.
    $second = rejectionWatch($team, [SignalPresetCatalogue::FUNDING_ROUND, SignalPresetCatalogue::ACQUISITION]);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0,
        'is_event' => true,
        'event_type' => SignalPresetCatalogue::ACQUISITION,
        'subject_company_name' => 'Acme DMCC',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'It was an acquisition after all.',
        'suggested_approach' => 'Ask for a LinkedIn introduction.',
    ]]]);

    $run = rejectionRun($team, $second);

    expect($this->fakeAi->callCount())->toBe(2)
        ->and($run->signals_created)->toBe(1);
});

it('forgets a rejection once it is out of the window', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0, 'is_event' => false, 'event_type' => null,
        'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
    ]]]);
    rejectionRun($team, $watch);

    SignalRejection::query()->withoutGlobalScopes()->update(['last_seen_at' => now()->subDays(90)]);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0, 'is_event' => false, 'event_type' => null,
        'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
    ]]]);
    rejectionRun($team, $watch);

    // Classified again, and the memory refreshed rather than duplicated.
    expect($this->fakeAi->callCount())->toBe(2)
        ->and(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SignalRejection::query()->withoutGlobalScopes()->firstOrFail()->seen_count)->toBe(2);
});

it('remembers the whole story, not only the copy that was sent', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    // Three outlets ran the same round-up. One classification, three memories — which is the point:
    // next week's run skips the coverage rather than re-paying for the two it did not send.
    $this->fakeNews->willReturn([
        $this->fakeNews->item('The ten biggest Dubai deals of the year', 'https://a.test/x', source: 'Gulf News'),
        $this->fakeNews->item('The ten biggest Dubai deals of the year so far', 'https://b.test/x', source: 'Zawya'),
        $this->fakeNews->item('The ten biggest Dubai deals of the year, ranked', 'https://c.test/x', source: 'MEED'),
    ]);
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0, 'is_event' => false, 'event_type' => null,
        'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
    ]]]);

    rejectionRun($team, $watch);

    expect($this->fakeAi->callCount())->toBe(1)
        ->and(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(3);
});

it('never remembers a type the run did not offer', function (): void {
    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    $this->fakeNews->willReturn(roundUp($this->fakeNews));
    // A key nobody offered: our prompt's problem, not a verdict about the article (ADR 0018 §7).
    $this->fakeAi->queueStructured(['events' => [[
        'item_index' => 0,
        'is_event' => true,
        'event_type' => 'hiring_surge',
        'subject_company_name' => 'Acme DMCC',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'Something else entirely.',
        'suggested_approach' => 'Ask for a LinkedIn introduction.',
    ]]]);

    rejectionRun($team, $watch);

    // Caching it would skip an item a corrected prompt would have kept.
    expect(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('classifies everything again when the memory is switched off', function (): void {
    config()->set('signals.rejections.enabled', false);

    $team = Team::factory()->create();
    $watch = rejectionWatch($team);

    foreach (range(1, 2) as $ignored) {
        $this->fakeNews->willReturn(roundUp($this->fakeNews));
        $this->fakeAi->queueStructured(['events' => [[
            'item_index' => 0, 'is_event' => false, 'event_type' => null,
            'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
        ]]]);
        rejectionRun($team, $watch);
    }

    expect($this->fakeAi->callCount())->toBe(2)
        ->and(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('never skips one team’s article because another team rejected it', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $notEvent = ['events' => [[
        'item_index' => 0, 'is_event' => false, 'event_type' => null,
        'confidence' => 10, 'relevance' => 10, 'summary' => '', 'suggested_approach' => '',
    ]]];

    foreach ([$teamA, $teamB] as $team) {
        $watch = rejectionWatch($team);
        $this->fakeNews->willReturn(roundUp($this->fakeNews));
        $this->fakeAi->queueStructured($notEvent);
        rejectionRun($team, $watch);
    }

    // One verdict each. A shared memory would be one team deciding what another may see.
    expect($this->fakeAi->callCount())->toBe(2)
        ->and(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('sweeps expired memories and leaves live ones alone', function (): void {
    $team = Team::factory()->create();

    SignalRejection::factory()->expired()->create(['team_id' => $team->getKey()]);
    SignalRejection::factory()->create(['team_id' => $team->getKey()]);

    $this->artisan('signals:prune')
        ->expectsOutputToContain('Also deleted 1 rejection memory(ies)')
        ->assertSuccessful();

    expect(SignalRejection::query()->withoutGlobalScopes()->count())->toBe(1);
});
