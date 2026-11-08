<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\MarginGuard;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Models\EnrichmentAttempt;
use App\Modules\Enrichment\Models\EnrichmentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The margin guard (PL4): what a team costs us against what they pay.
 *
 * **A different question from the allowance**, which is the whole reason it exists. A team inside
 * its plan's reveal allowance can still be unprofitable — the allowances are sized for a normal
 * month, and `UsageAllowance` counts units where the problem is money.
 */
function spendVendorMicros(Team $team, int $micros): void
{
    app(TeamContext::class)->run($team, function () use ($team, $micros): void {
        $run = EnrichmentRun::factory()->create(['team_id' => $team->getKey()]);

        EnrichmentAttempt::factory()->create([
            'team_id' => $team->getKey(),
            'enrichment_run_id' => $run->getKey(),
            'capability' => ProviderCapability::PhoneFind,
            'provider' => 'test',
            'outcome' => AttemptOutcome::Hit,
            'cost_micros' => $micros,
        ]);
    });
}

it('stops phone reveals once a team costs more than the configured share of what they pay', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::capAllowance(BillingMetric::PhoneReveal, 100);
    config()->set('billing.margin_guard.max_vendor_cost_ratio', 0.35);

    $reserver = app(CreditReserver::class);

    // Starter is £49 = 49,000,000 micros. A third of that is comfortably under the limit.
    spendVendorMicros($team, 10_000_000);

    app(TeamContext::class)->run($team, function () use ($team, $reserver): void {
        expect($reserver->reserve($team, EnrichmentScope::Phone, 1)->granted())->toBeTrue();
    });

    // Past it, and the one expensive line item stops.
    spendVendorMicros($team, 10_000_000);

    app(TeamContext::class)->run($team, function () use ($team, $reserver): void {
        $refused = $reserver->reserve($team, EnrichmentScope::Phone, 1);

        expect($refused->granted())->toBeFalse()
            ->and($refused->refusalCode)->toBe('billing.margin_guard.phone_reveal');

        // **And nothing else stops.** A guard that refused everything would turn a pricing problem
        // into an outage for a customer who is paying us.
        expect($reserver->reserve($team, EnrichmentScope::Email, 1)->granted())->toBeTrue();
    });
});

it('holds nothing against the allowance when it refuses', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::capAllowance(BillingMetric::PhoneReveal, 100);

    spendVendorMicros($team, 49_000_000);

    app(TeamContext::class)->run($team, function () use ($team): void {
        app(CreditReserver::class)->reserve($team, EnrichmentScope::Phone, 1);

        // The check runs **before** the hold: a refusal that had reserved first would leave a hold
        // nobody settles sitting against the allowance until the month turned over.
        expect(app(UsageAllowance::class)
            ->held($team, BillingMetric::PhoneReveal))->toBe(0);
    });
});

it('exempts a team with no price to divide by', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);
    Fixtures::capAllowance(BillingMetric::PhoneReveal, 5);

    spendVendorMicros($team, 50_000_000);

    app(TeamContext::class)->run($team, function () use ($team): void {
        // The ratio has no denominator on a free plan or a trial, and reading "any cost at all" as
        // a breach would refuse the first phone reveal of every trial.
        expect(app(MarginGuard::class)->ratio($team))->toBeNull();
        expect(app(CreditReserver::class)->reserve($team, EnrichmentScope::Phone, 1)->granted())->toBeTrue();
    });
});

it('can be switched off', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);
    Fixtures::capAllowance(BillingMetric::PhoneReveal, 100);
    config()->set('billing.margin_guard.enabled', false);

    spendVendorMicros($team, 100_000_000);

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(CreditReserver::class)->reserve($team, EnrichmentScope::Phone, 1)->granted())->toBeTrue();
    });
});
