<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\AiPlatform\Services\AiCreditGuard;
use App\Modules\AiPlatform\Services\AiUsageLogger;
use App\Modules\AiPlatform\Services\StubCreditSource;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

it('sums a team usage only from its own current-period rows', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    AiRequest::factory()->for($teamA)->create(['cost_micros' => 3_000]);
    AiRequest::factory()->for($teamA)->create(['cost_micros' => 2_000]);
    AiRequest::factory()->for($teamB)->create(['cost_micros' => 9_000]);

    // A row from last month must not count toward this period.
    AiRequest::factory()->for($teamA)->create([
        'cost_micros' => 100_000,
        'created_at' => Carbon::now('UTC')->subMonthNoOverflow()->startOfMonth(),
    ]);

    $usage = app(AiUsageLogger::class);

    expect($usage->usedMicros($teamA))->toBe(5_000)
        ->and($usage->usedMicros($teamB))->toBe(9_000);
});

it('reports the configured allowance and real usage via the stub credit source', function (): void {
    config(['ai-platform.credits.monthly_default' => 10, 'ai-platform.credits.micros_per_credit' => 1_000]);

    $team = Team::factory()->create();
    AiRequest::factory()->for($team)->create(['cost_micros' => 4_000]);

    $allowance = app(StubCreditSource::class)->allowanceFor($team);

    expect($allowance->limitMicros)->toBe(10_000) // 10 credits × 1000 micros
        ->and($allowance->usedMicros)->toBe(4_000)
        ->and($allowance->remainingMicros())->toBe(6_000)
        ->and($allowance->isExhausted())->toBeFalse();
});

it('blocks only the team that is over allowance', function (): void {
    // Five credits a month, on whatever plan either team is on. Since Phase 16 the guard reads the
    // *plan's* allowance through Billing's `CreditSource`, not `ai-platform.credits` — that config
    // is still what converts credits to the ledger's micro-units, which is why it is set too.
    config(['ai-platform.credits.micros_per_credit' => 1_000]);
    Billing::capAllowance(BillingMetric::AiCredits, 5); // 5_000 micros

    $overTeam = Team::factory()->create();
    $underTeam = Team::factory()->create();

    // On a plan that stops at its allowance rather than metering past it. A new team is on the
    // trial plan, which *does* meter — five credits there means work continues to the overage
    // ceiling, and "over allowance" would not yet mean "blocked".
    Billing::onFreePlan($overTeam);
    Billing::onFreePlan($underTeam);

    AiRequest::factory()->for($overTeam)->create(['cost_micros' => 5_000]);  // exhausted
    AiRequest::factory()->for($underTeam)->create(['cost_micros' => 1_000]); // under

    $guard = app(AiCreditGuard::class);

    expect(fn () => $guard->ensureWithinAllowance($overTeam))->toThrow(AiCreditExceededException::class);
    $guard->ensureWithinAllowance($underTeam); // does not throw

    expect(true)->toBeTrue();
});

it('is a no-op when there is no team (system call)', function (): void {
    app(TeamContext::class)->forget();

    app(AiCreditGuard::class)->ensureWithinAllowance(null);

    expect(true)->toBeTrue();
});

it('a team cannot read another team usage rows through the global scope', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    AiRequest::factory()->for($teamA)->create();
    AiRequest::factory()->for($teamB)->create();

    app(TeamContext::class)->set($teamA);
    expect(AiRequest::query()->count())->toBe(1)
        ->and(AiRequest::query()->firstOrFail()->team_id)->toBe($teamA->getKey());

    app(TeamContext::class)->set($teamB);
    expect(AiRequest::query()->count())->toBe(1)
        ->and(AiRequest::query()->firstOrFail()->team_id)->toBe($teamB->getKey());
});
