<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Data\SourceItem;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalClusterer;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\BillingFixtures as Billing;

/*
 * One event, one card (S11), end to end: the run, the cluster rows, the feed and the actions.
 *
 * The two numbers this phase exists for are both asserted here — **one AI call** for a story eight
 * outlets covered, and **one card** for it in the feed — because either one alone is only half the
 * claim.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
    // The AI Platform's own response cache is off here: it is keyed on the rendered prompt, so a
    // second run over the same article is a cache hit and the transport is never reached - which
    // looks identical to the dedupe having worked. With it off, `callCount()` means "the run paid
    // to classify this", which is what these tests are actually claiming.
    config()->set('ai-platform.cache.enabled', false);
});

/**
 * One Dubai acquisition, as eight outlets wrote it.
 *
 * @return list<SourceItem>
 */
function clusterCoverage(FakeSignalSource $news, string $link = 'zephyr'): array
{
    $headlines = [
        ['Regional Holdings acquires Dubai logistics firm Zephyr', 'Gulf News'],
        ['Regional Holdings acquires Dubai logistics firm Zephyr in cash deal', 'Khaleej Times'],
        ['Regional Holdings acquires Dubai logistics firm Zephyr for undisclosed sum', 'Zawya'],
        ['Regional Holdings acquires Dubai logistics group Zephyr', 'Arabian Business'],
        ['Regional Holdings acquires Zephyr, the Dubai logistics firm', 'The National'],
        ['Dubai logistics firm Zephyr acquired by Regional Holdings', 'Gulf Business'],
        ['Regional Holdings seals acquisition of Dubai logistics firm Zephyr', 'MEED'],
        ['Zephyr acquired: Regional Holdings adds Dubai logistics firm', 'Logistics Middle East'],
    ];

    $items = [];

    foreach ($headlines as $index => [$title, $source]) {
        $items[] = $news->item(
            title: $title,
            link: 'https://example.test/'.$link.'-'.$index,
            snippet: 'Regional Holdings has agreed to buy Zephyr.',
            source: $source,
            publishedAt: Carbon::parse('2026-11-03 09:00:00')->utc(),
        );
    }

    return $items;
}

/**
 * The AI's verdict on the Zephyr acquisition, for the representative at `$index`.
 *
 * @return array<string, mixed>
 */
function zephyrEvent(int $index = 0, int $confidence = 90): array
{
    return [
        'item_index' => $index,
        'is_event' => true,
        'event_type' => SignalPresetCatalogue::ACQUISITION,
        'subject_person_name' => null,
        'subject_person_role' => null,
        'subject_company_name' => 'Zephyr Logistics LLC',
        'counterparty_name' => 'Regional Holdings PJSC',
        'amount' => null,
        'currency' => null,
        'location' => 'Dubai',
        'confidence' => $confidence,
        'relevance' => 80,
        'summary' => 'Regional Holdings has acquired Dubai logistics firm Zephyr.',
        'suggested_approach' => 'Ask a mutual connection for a LinkedIn introduction.',
    ];
}

function acquisitionWatch(Team $team): SignalWatch
{
    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets([SignalPresetCatalogue::ACQUISITION])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));
}

function runFor(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

// --- The cost win and the card win --------------------------------------------------------------

it('classifies one event once and keeps every article that reported it', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);

    $run = runFor($team, $watch);

    // One AI call for eight articles: the phase's cost win, measured.
    expect($this->fakeAi->callCount())->toBe(1)
        // **`signals_created` counts events, not rows.** Eight rows were written and one card was
        // added, and "signals created: 8" on the watch card over one card in the feed is the
        // discrepancy this phase exists to remove.
        ->and($run->signals_created)->toBe(1)
        // Every article is still its own row — each one is a real link worth reading.
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(8)
        // `results_count` is the raw provider count across the watch's three templates, which is
        // what it has always meant: the same eight articles seen three times.
        ->and($run->results_count)->toBe(24);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();

    expect($cluster->sources_count)->toBe(8)
        ->and($cluster->event_key)->toBe(SignalPresetCatalogue::ACQUISITION)
        ->and($cluster->subject_fingerprint)->toBe('zephyr-logistics')
        ->and($cluster->primary_signal_id)->not->toBeNull()
        // Every member points back at it, so the card can list the coverage.
        ->and(Signal::query()->withoutGlobalScopes()->where('signal_cluster_id', $cluster->getKey())->count())
        ->toBe(8);
});

it('shows one card and counts one event, not eight articles', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);

    runFor($team, $watch);

    $list = app(ListSignalsAction::class);
    $page = $list->handle($team, SignalFilters::fromArray([]));

    // "New 8" over a list of one card is a member being told the feature is broken, which is why
    // the counts and the list share one definition of what a row is.
    expect($page->total())->toBe(1)
        ->and($list->counts($team)[SignalStatus::New->value] ?? 0)->toBe(1);
});

it('reports the coverage on the card', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);

    runFor($team, $watch);

    $page = app(ListSignalsAction::class)
        ->handle($team, SignalFilters::fromArray([]));

    $payload = SignalResource::make($page->items()[0])
        ->resolve(request());

    expect($payload['cluster']['sources_count'])->toBe(8)
        ->and($payload['cluster']['sources'])->toHaveCount(8)
        // A link, an outlet and a date - never a second copy of the summary per source.
        ->and(array_keys($payload['cluster']['sources'][0]))
        ->toBe(['id', 'source_name', 'source_url', 'published_at']);
});

it('says nothing about sources when an event stands alone', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn([
        $this->fakeNews->item(
            'Regional Holdings acquires Dubai logistics firm Zephyr',
            'https://example.test/only',
            source: 'Gulf News',
            publishedAt: Carbon::parse('2026-11-03 09:00:00')->utc(),
        ),
    ]);
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);

    runFor($team, $watch);

    $page = app(ListSignalsAction::class)
        ->handle($team, SignalFilters::fromArray([]));

    $payload = SignalResource::make($page->items()[0])
        ->resolve(request());

    // "Reported by 1 source" would be noise on most of the feed.
    expect($payload['cluster'])->toBeNull();
});

it('joins a story covered again on a later run to the cluster it already has', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 2));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    // Two days later, two more outlets pick it up. A headline-only grouping could never see this -
    // it is the subject-based key that makes a cluster survive across runs.
    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews, 'zephyr-later'), 2, 2));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->sources_count)->toBe(4);
});

it('leaves an event that names no company as its own card', function (): void {
    $team = Team::factory()->create();
    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets([SignalPresetCatalogue::RELOCATION])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));

    $this->fakeNews->willReturn([
        $this->fakeNews->item('UK founder relocates to Dubai', 'https://example.test/move', source: 'The National'),
    ]);
    $this->fakeAi->queueStructured(['events' => [array_merge(zephyrEvent(), [
        'event_type' => SignalPresetCatalogue::RELOCATION,
        'subject_company_name' => null,
        'subject_person_name' => 'A Founder',
        'counterparty_name' => null,
    ])]]);

    runFor($team, $watch);

    // A cluster is not swept by retention, so a person's name on one would outlive the rows it came
    // from. No company, no cluster - see the module doc's "Known limitations".
    expect(SignalCluster::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->firstOrFail()->signal_cluster_id)->toBeNull();
});

// --- Actions move the whole event ---------------------------------------------------------------

it('dismisses the cluster, not just the card', function (): void {
    [$user, $team] = Billing::team();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $primary = SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->primary_signal_id;

    $this->actingAs($user)
        ->post('/signals/'.$primary.'/dismiss', ['reason' => 'not_relevant'])
        ->assertRedirect();

    // Every member follows. A member left on `New` would be a row nothing shows, nothing counts and
    // retention deletes in 180 days - quietly shortening the coverage on a card somebody is reading.
    expect(Signal::query()->withoutGlobalScopes()->where('status', SignalStatus::Dismissed)->count())->toBe(8)
        ->and(Signal::query()->withoutGlobalScopes()->where('status', SignalStatus::New)->count())->toBe(0);
});

it('brings the whole cluster back on restore', function (): void {
    [$user, $team] = Billing::team();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $primary = SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->primary_signal_id;

    $this->actingAs($user)->post('/signals/'.$primary.'/dismiss')->assertRedirect();
    $this->actingAs($user)->post('/signals/'.$primary.'/restore')->assertRedirect();

    expect(Signal::query()->withoutGlobalScopes()->where('status', SignalStatus::New)->count())->toBe(8);
});

it('keeps the primary on an actioned cluster even when a better article arrives', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 1));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent(confidence: 60)]]);
    runFor($team, $watch);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();
    $first = (int) $cluster->primary_signal_id;

    // The member converts it: this row now carries `lead_id`, which is the only copy of where that
    // lead came from.
    Signal::query()->withoutGlobalScopes()->whereKey($first)->update(['status' => SignalStatus::Converted->value]);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews, 'zephyr-better'), 1, 1));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent(confidence: 99)]]);
    runFor($team, $watch);

    // A higher-confidence article joined, and the primary did **not** move: re-pointing a converted
    // cluster at a different article would strand the provenance of a real lead.
    expect((int) $cluster->refresh()->primary_signal_id)->toBe($first)
        ->and($cluster->sources_count)->toBe(2);
});

// --- Tenancy ------------------------------------------------------------------------------------

it('never puts two teams’ signals in one cluster', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    foreach ([$teamA, $teamB] as $team) {
        $watch = acquisitionWatch($team);
        $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 2));
        $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
        runFor($team, $watch);
    }

    $clusters = SignalCluster::query()->withoutGlobalScopes()->get();

    // The same event, the same key, two teams: two clusters, because `(team_id, cluster_key)` is
    // the unique index and every read names the team.
    expect($clusters)->toHaveCount(2)
        ->and($clusters->pluck('team_id')->unique())->toHaveCount(2)
        ->and($clusters->pluck('cluster_key')->unique())->toHaveCount(1)
        ->and($clusters->pluck('sources_count')->all())->toBe([2, 2]);
});

it('hides another team’s cluster from the tenant-scoped query', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $watch = acquisitionWatch($teamA);
    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 2));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($teamA, $watch);

    $seen = app(TeamContext::class)->run($teamB, fn (): int => SignalCluster::query()->count());

    expect($seen)->toBe(0);
});

// --- The flag -----------------------------------------------------------------------------------

it('classifies every article when cluster_before_ai is off, and still shows one card', function (): void {
    config()->set('signals.cluster_before_ai', false);

    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 3));

    // Three articles, three positions in one batch — v1's behaviour exactly.
    $this->fakeAi->queueStructured(['events' => [zephyrEvent(0), zephyrEvent(1), zephyrEvent(2)]]);

    $run = runFor($team, $watch);

    expect($run->signals_created)->toBe(1)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(3)
        // The cards are still consolidated: that half is the subject-based key, not the flag.
        ->and(SignalCluster::query()->withoutGlobalScopes()->firstOrFail()->sources_count)->toBe(3);
});

it('reconciles the cluster from its rows rather than trusting a counter', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();

    // Retention takes a member. A tally the app incremented would now be wrong for ever; a record
    // over the rows is simply recomputed (ADR 0012's shape, applied here).
    Signal::query()->withoutGlobalScopes()
        ->where('signal_cluster_id', $cluster->getKey())
        ->whereKeyNot($cluster->primary_signal_id)
        ->limit(1)
        ->delete();

    app(SignalClusterer::class)->reconcile($cluster);

    expect($cluster->refresh()->sources_count)->toBe(7);
});

it('announces one new signal for an event eight outlets reported', function (): void {
    Event::fake([SignalsDetected::class]);

    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);

    runFor($team, $watch);

    // "8 new signals" leading to a feed with one card in it is worse than silence.
    Event::assertDispatched(SignalsDetected::class, fn (SignalsDetected $e): bool => $e->count === 1);
});

it('announces nothing when a run only adds coverage of an event the team already has', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews), 0, 1));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    Event::fake([SignalsDetected::class]);

    $this->fakeNews->willReturn(array_slice(clusterCoverage($this->fakeNews, 'zephyr-more'), 1, 2));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    $second = runFor($team, $watch);

    // Rows were written; no card appeared. Nothing to tell anybody.
    expect($second->signals_created)->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(3);

    Event::assertNotDispatched(SignalsDetected::class);
});

it('keeps showing a cluster’s members when retention takes its primary', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();

    // Retention deletes per signal, so it can take the primary and leave the members. The FK nulls
    // `primary_signal_id`, and a predicate asking "are you the primary?" would answer no for all
    // seven survivors — the whole story would vanish from the feed with the rows still in the
    // table. Too many cards is a fault somebody reports; none is one nobody can see.
    Signal::query()->withoutGlobalScopes()->whereKey($cluster->primary_signal_id)->delete();

    $page = app(ListSignalsAction::class)->handle($team, SignalFilters::fromArray([]));

    expect($cluster->refresh()->primary_signal_id)->toBeNull()
        ->and($page->total())->toBe(7);
});

it('reconciles a cluster whose primary was swept, and shows one card again', function (): void {
    $team = Team::factory()->create();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();
    Signal::query()->withoutGlobalScopes()->whereKey($cluster->primary_signal_id)->delete();

    // The nightly sweep repairs it rather than leaving the feed showing seven cards for ever.
    $this->artisan('signals:prune')->assertSuccessful();

    expect($cluster->refresh()->primary_signal_id)->not->toBeNull()
        ->and($cluster->sources_count)->toBe(7)
        ->and(app(ListSignalsAction::class)->handle($team, SignalFilters::fromArray([]))->total())->toBe(1);
});

it('never dismisses a converted member along with the rest of the cluster', function (): void {
    [$user, $team] = Billing::team();
    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $cluster = SignalCluster::query()->withoutGlobalScopes()->firstOrFail();
    $primary = (int) $cluster->primary_signal_id;

    /** @var Signal $member */
    $member = Signal::query()->withoutGlobalScopes()
        ->where('signal_cluster_id', $cluster->getKey())
        ->whereKeyNot($primary)
        ->firstOrFail();

    $member->forceFill(['status' => SignalStatus::Converted->value])->save();

    $this->actingAs($user)->post('/signals/'.$primary.'/dismiss')->assertRedirect();

    // A converted signal has no retention window and a dismissed one has 90 days, so flipping it
    // would have scheduled the deletion of a working lead's provenance.
    expect($member->refresh()->status)->toBe(SignalStatus::Converted)
        ->and(Signal::query()->withoutGlobalScopes()->where('status', SignalStatus::Dismissed)->count())->toBe(7);
});

it('writes one timeline entry per event on a matched lead, not one per article', function (): void {
    $team = Team::factory()->create();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'company_name' => 'Zephyr Logistics LLC',
    ]));

    $watch = acquisitionWatch($team);

    $this->fakeNews->willReturn(clusterCoverage($this->fakeNews));
    $this->fakeAi->queueStructured(['events' => [zephyrEvent()]]);
    runFor($team, $watch);

    $entries = app(TeamContext::class)->run($team, fn (): int => LeadActivity::query()
        ->where('lead_id', $lead->getKey())
        ->where('type', LeadActivityType::SignalMatched)
        ->count());

    // Eight outlets covering the sale of a company somebody is working is one thing that happened.
    // Eight identical entries in one morning is the feed's old defect moved somewhere a member
    // cannot dismiss it.
    expect($entries)->toBe(1);
});
