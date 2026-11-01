<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Enums\AiPulseMetric;
use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Events\AiRequestRecorded;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Observability\AiUsageCard;
use App\Modules\AiPlatform\Observability\AiUsageRecorder;
use App\Modules\AiPlatform\Observability\AiUsageSummary;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

/*
 * The live AI usage card (Phase 20, ADR 0017), end to end: the event, the recorder, the entries
 * Pulse stores, and the arithmetic the card does on the way back out.
 *
 * Added in the review round, because none of it was covered. The card's numbers are the kind that
 * are wrong quietly — a renamed Pulse type does not error, the column comes back null and a figure
 * on an operator's dashboard becomes zero — so the test that matters most is the round trip
 * through the real recorder and the real aggregate query rather than a mock of either.
 *
 * `PULSE_ENABLED` is false for the suite (`phpunit.xml`), deliberately, so these turn it on for
 * themselves.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('pulse.enabled', true);
    Config::set('pulse.ingest.driver', 'storage');

    // Pulse reads `shouldRecord` once at resolve time; a config change after that is not seen.
    app()->forgetInstance(Pulse::getFacadeRoot()::class);
    Pulse::stopRecording();
    Pulse::startRecording();
});

/**
 * Raise one usage event through the real recorder and flush Pulse's buffer to storage.
 */
function recordAiUsage(
    string $promptKey = 'test.summary',
    int $costMicros = 1_000,
    int $latencyMs = 400,
    bool $cacheHit = false,
    AiRequestStatus $status = AiRequestStatus::Success,
): void {
    app(AiUsageRecorder::class)->record(new AiRequestRecorded(
        teamId: 1,
        promptKey: $promptKey,
        promptVersion: 1,
        tier: AiTier::Reasoning,
        model: 'claude-sonnet-5-5',
        inputTokens: 100,
        outputTokens: 50,
        costMicros: $costMicros,
        latencyMs: $latencyMs,
        cacheHit: $cacheHit,
        status: $status,
        errorCode: $status === AiRequestStatus::Failed ? 'ai.transport' : null,
    ));

    Pulse::ingest();
}

/**
 * @return array<string, int> type => summed value, for one prompt key
 */
function recordedPulseTotals(string $promptKey = 'test.summary'): array
{
    return DB::table('pulse_entries')
        ->where('key', $promptKey)
        ->get()
        ->groupBy('type')
        ->map(fn ($rows): int => (int) $rows->sum('value'))
        ->all();
}

it('records a successful provider call as calls, cost and latency', function (): void {
    recordAiUsage(costMicros: 10_500, latencyMs: 1_200);

    expect(recordedPulseTotals())->toEqual([
        AiPulseMetric::Requests->value => 1,
        AiPulseMetric::CostMicros->value => 10_500,
        AiPulseMetric::LatencyMs->value => 1_200,
    ]);
});

it('counts a cache hit without charging it cost or latency', function (): void {
    // The reason the early return exists: a cache hit takes a millisecond and costs nothing, and
    // counting it as a provider call would flatter both numbers on the card.
    recordAiUsage(costMicros: 0, latencyMs: 2, cacheHit: true);

    $totals = recordedPulseTotals();

    expect($totals[AiPulseMetric::Requests->value] ?? 0)->toBe(1)
        ->and($totals[AiPulseMetric::Cached->value] ?? 0)->toBe(1)
        ->and($totals)->not->toHaveKey(AiPulseMetric::CostMicros->value)
        ->and($totals)->not->toHaveKey(AiPulseMetric::LatencyMs->value);
});

it('counts a failure as both a call and a failure', function (): void {
    recordAiUsage(status: AiRequestStatus::Failed);

    $totals = recordedPulseTotals();

    // A failed call still spent tokens and still took time, so it counts in all four — the failure
    // is an extra fact about it, not a different kind of row.
    expect($totals[AiPulseMetric::Requests->value] ?? 0)->toBe(1)
        ->and($totals[AiPulseMetric::Failed->value] ?? 0)->toBe(1)
        ->and($totals[AiPulseMetric::LatencyMs->value] ?? 0)->toBeGreaterThan(0);
});

it('means the latency over provider calls only, so a cache hit does not flatter it', function (): void {
    // Two provider calls at 300ms and 500ms, plus three cache hits. The mean must be 400, not 160.
    recordAiUsage(latencyMs: 300);
    recordAiUsage(latencyMs: 500);
    recordAiUsage(cacheHit: true, costMicros: 0, latencyMs: 1);
    recordAiUsage(cacheHit: true, costMicros: 0, latencyMs: 1);
    recordAiUsage(cacheHit: true, costMicros: 0, latencyMs: 1);

    $rows = app(AiUsageSummary::class)->forPeriod(CarbonInterval::hour());

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->key)->toBe('test.summary')
        ->and($row->calls)->toBe(5)
        ->and($row->cached)->toBe(3)
        ->and($row->latencyMs)->toBe(400)
        ->and($row->costMicros)->toBe(2_000);
});

it('does not divide by zero when every call was a cache hit', function (): void {
    // `max(1, …)` is load-bearing: five cache hits and no provider call is a real state, and the
    // honest answer for the mean is zero rather than a crash on the operator's dashboard.
    recordAiUsage(cacheHit: true, costMicros: 0, latencyMs: 1);

    $row = app(AiUsageSummary::class)->forPeriod(CarbonInterval::hour())->first();

    expect($row->calls)->toBe(1)
        ->and($row->cached)->toBe(1)
        ->and($row->latencyMs)->toBe(0);
});

it('renders the card on the dashboard', function (): void {
    recordAiUsage();

    // Through Livewire, because the component is registered by name in the dashboard view and a
    // test that only called `render()` would still pass if that registration were removed.
    // `withoutLazyLoading`, because the card is `#[Lazy]`: its first render is a placeholder, and a
    // test that asserted on that would pass with the query removed.
    Livewire::withoutLazyLoading();

    Livewire::test(AiUsageCard::class)
        ->assertOk()
        ->assertSee('AI usage')
        ->assertSee('test.summary')
        // The disclaimer is part of the card's contract rather than decoration: the figure is
        // sampled and trimmed, and the ledger is what anybody bills from.
        ->assertSee('not the billing figure');
});

it('raises no usage event, and so records nothing, when the prompt is refused for size', function (): void {
    AiPromptTemplate::factory()->key('test.summary')->create([
        'tier' => AiTier::Reasoning,
        'body' => 'Summarise: {{subject}}',
        'schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']],
    ]);

    Config::set('ai-platform.limits.max_prompt_chars', 500);
    Config::set('ai-platform.limits.max_untrusted_chars', 0);

    $team = Team::factory()->create();
    app(TeamContext::class)->set($team);

    [$client] = bootAi();

    try {
        $client->structured('test.summary', ['subject' => str_repeat('b', 2_000)]);
    } catch (Throwable) {
        // Expected: the refusal is asserted in AiInputLimitTest.
    }

    Pulse::ingest();

    // Deliberate, and worth a test so nobody reads the card as a count of *attempts*: a refused
    // prompt spends nothing, writes no ledger row and raises no event, so it is invisible here.
    // The 413 is what tells the caller, and the rate limiter is what bounds the retrying.
    expect(recordedPulseTotals())->toBe([]);
});
