<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeNewsSearchClient;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The redirect defect, closed (S11).
 *
 * Serper sometimes returns `https://google.com/goto?url=<opaque token>` with the publisher only in
 * `source`. The token is Google's own encoding, so there is nothing to unwrap locally — and if it
 * varies between requests, `url_hash` stops being the article's identity and the same article
 * re-enters as a new signal on **every run**, costing a fresh Serper query and a fresh AI batch each
 * time. That is the regression this file exists for.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeNewsSearchClient;
    app()->instance(NewsSearchClient::class, $this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
    // The AI Platform's own response cache is off here: it is keyed on the rendered prompt, so a
    // second run over the same article is a cache hit and the transport is never reached - which
    // looks identical to the dedupe having worked. With it off, `callCount()` means "the run paid
    // to classify this", which is what these tests are actually claiming.
    config()->set('ai-platform.cache.enabled', false);
});

function redirectWatch(Team $team): SignalWatch
{
    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));
}

function redirectRun(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

/**
 * @return array<string, mixed>
 */
function redirectEvent(): array
{
    return [
        'item_index' => 0,
        'is_event' => true,
        'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'subject_person_name' => null,
        'subject_person_role' => null,
        'subject_company_name' => 'Acme DMCC',
        'counterparty_name' => null,
        'amount' => 40_000_000,
        'currency' => 'USD',
        'location' => 'Dubai',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => 'Acme raised a $40m Series A.',
        'suggested_approach' => 'Ask a mutual connection for a LinkedIn introduction.',
    ];
}

it('produces one signal across two runs when the redirect token changes', function (): void {
    $team = Team::factory()->create();
    $watch = redirectWatch($team);

    $this->fakeNews->willReturn([$this->fakeNews->item(
        title: 'Acme closes $40m Series A in Dubai',
        link: 'https://google.com/goto?url=TOKEN-ONE',
        snippet: 'Acme DMCC has raised a $40m Series A.',
        source: 'Construction Week Online',
    )]);
    $this->fakeAi->queueStructured(['events' => [redirectEvent()]]);

    $first = redirectRun($team, $watch);

    // Same article, same outlet, a different token — which is exactly what was observed in real
    // data and what used to cost a second AI batch.
    $this->fakeNews->willReturn([$this->fakeNews->item(
        title: 'Acme closes $40m Series A in Dubai',
        link: 'https://google.com/goto?url=TOKEN-TWO',
        snippet: 'Acme DMCC has raised a $40m Series A.',
        source: 'Construction Week Online',
    )]);

    $second = redirectRun($team, $watch);

    expect($first->signals_created)->toBe(1)
        ->and($second->signals_created)->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(1)
        // And it cost nothing to find that out: the item never reached the model.
        ->and($this->fakeAi->callCount())->toBe(1);
});

it('still keys an ordinary publisher link by its URL', function (): void {
    $team = Team::factory()->create();
    $watch = redirectWatch($team);

    $this->fakeNews->willReturn([$this->fakeNews->item(
        title: 'Acme closes $40m Series A in Dubai',
        link: 'https://gulfbusiness.com/acme',
        source: 'Gulf Business',
    )]);
    $this->fakeAi->queueStructured(['events' => [redirectEvent()]]);

    redirectRun($team, $watch);

    $signal = Signal::query()->withoutGlobalScopes()->firstOrFail();

    // `url_hash` is still the truth about the link, and for a normal link it **is** the identity.
    expect($signal->dedupe_hash)->toBe($signal->url_hash)
        ->and($signal->content_hash)->not->toBeNull();
});

it('keeps the content hash beside a redirected item’s identity', function (): void {
    $team = Team::factory()->create();
    $watch = redirectWatch($team);

    $this->fakeNews->willReturn([$this->fakeNews->item(
        title: 'Acme closes $40m Series A in Dubai',
        link: 'https://google.com/goto?url=TOKEN',
        source: 'Construction Week Online',
    )]);
    $this->fakeAi->queueStructured(['events' => [redirectEvent()]]);

    redirectRun($team, $watch);

    $signal = Signal::query()->withoutGlobalScopes()->firstOrFail();

    expect($signal->dedupe_hash)->toBe($signal->content_hash)
        ->and($signal->dedupe_hash)->not->toBe($signal->url_hash);
});

it('never dedupes one team’s redirected article against another team’s', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    foreach ([$teamA, $teamB] as $team) {
        $watch = redirectWatch($team);

        $this->fakeNews->willReturn([$this->fakeNews->item(
            title: 'Acme closes $40m Series A in Dubai',
            link: 'https://google.com/goto?url=TOKEN',
            source: 'Construction Week Online',
        )]);
        $this->fakeAi->queueStructured(['events' => [redirectEvent()]]);

        redirectRun($team, $watch);
    }

    // The unique index is `(team_id, dedupe_hash)`, so the same article is one signal **each**.
    expect(Signal::query()->withoutGlobalScopes()->count())->toBe(2);
});
