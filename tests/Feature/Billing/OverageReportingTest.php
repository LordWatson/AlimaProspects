<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\UsageMeter;
use App\Modules\Billing\Services\OverageReporter;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Metered overage: the part of this module that moves money, and so the part tested hardest.
 *
 * Stripe **sums** the usage events it is sent over a billing period, which means the only bug
 * worth really guarding against is sending the same units twice — once to Stripe is a line on an
 * invoice, twice is a charge the customer never agreed to.
 */
// `DateTimeInterface`, not `Illuminate\Support\Carbon`: the project's datetimes are
// `CarbonImmutable`, which is a sibling of that class rather than a subclass, and a narrower hint
// here is a runtime TypeError PHPStan will not catch.
function leadsFor(Team $team, int $leads, ?DateTimeInterface $at = null): void
{
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Completed,
        'leads_created' => $leads,
        'completed_at' => $at ?? now(),
    ]));
}

function creditsFor(Team $team, int $credits): void
{
    app(TeamContext::class)->run($team, fn () => AiRequest::factory()->create([
        'team_id' => $team->getKey(),
        'status' => AiRequestStatus::Success,
        'cost_micros' => BillingMetric::AiCredits->toMicros($credits),
    ]));
}

function leadMeter(Team $team): UsageMeter
{
    return app(TeamContext::class)->run(
        $team,
        fn (): UsageMeter => app(UsageLedger::class)->meter($team, BillingMetric::Leads),
    );
}

it('reports only what the team is over by', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $allowance = (int) config('billing.plans.starter.leads');
    leadsFor($team, $allowance + 30);

    app(OverageReporter::class)->report($team);

    $calls = $gateway->callsOf('report_overage');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['stripe_price'])->toBe(config('billing.overage.leads.stripe_price'))
        ->and($calls[0]['quantity'])->toBe(30);
});

it('never bills the same units twice, however often it runs', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $price = (string) config('billing.overage.leads.stripe_price');
    $allowance = (int) config('billing.plans.starter.leads');

    leadsFor($team, $allowance + 30);

    app(OverageReporter::class)->report($team);
    app(OverageReporter::class)->report($team);
    app(OverageReporter::class)->report($team);

    // Three runs, thirty units. `usage_meters.reported` is what makes that true.
    expect($gateway->reportedQuantityFor($price))->toBe(30)
        ->and(leadMeter($team)->reported)->toBe(30);
});

it('reports only the new overage on a later run', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $price = (string) config('billing.overage.leads.stripe_price');
    $allowance = (int) config('billing.plans.starter.leads');

    leadsFor($team, $allowance + 30);
    app(OverageReporter::class)->report($team);

    leadsFor($team, 12);
    app(OverageReporter::class)->report($team);

    $calls = $gateway->callsOf('report_overage');

    expect($calls)->toHaveCount(2)
        ->and($calls[1]['quantity'])->toBe(12)
        ->and($gateway->reportedQuantityFor($price))->toBe(42);
});

it('bills credits in thousands and carries the remainder to the next run', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $price = (string) config('billing.overage.ai_credits.stripe_price');
    $allowance = (int) config('billing.plans.starter.credits');

    creditsFor($team, $allowance + 1_500);
    app(OverageReporter::class)->report($team);

    // One whole unit of 1,000. The remaining 500 is deliberately not claimed — rounding it up
    // would overcharge, and rounding it away would lose it.
    expect($gateway->reportedQuantityFor($price))->toBe(1);

    creditsFor($team, 700);
    app(OverageReporter::class)->report($team);

    // 500 carried + 700 new = 1,200 → one more whole unit.
    expect($gateway->reportedQuantityFor($price))->toBe(2);
});

it('no-ops instead of failing when the metered price has not been created yet', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // The state every environment is in until somebody creates the meters in Stripe. The usage is
    // still measured and the ceiling still caps it — only the billing of the excess waits.
    config()->set('billing.overage.leads.stripe_price', null);
    config()->set('billing.overage.ai_credits.stripe_price', null);

    leadsFor($team, (int) config('billing.plans.starter.leads') + 30);

    expect(app(OverageReporter::class)->report($team))->toBe(0)
        ->and($gateway->callsOf('report_overage'))->toBeEmpty();

    // Nothing was claimed either, so the units are still owed once the price exists.
    expect(leadMeter($team)->unreportedOverage())->toBe(30);
});

it('reports nothing on a plan that stops instead of metering', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::onFreePlan($team);

    leadsFor($team, (int) config('billing.plans.free.leads') + 20);

    expect(app(OverageReporter::class)->report($team))->toBe(0)
        ->and($gateway->callsOf('report_overage'))->toBeEmpty();
});

it('releases the claim when Stripe definitely refused, so the units are not lost', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    leadsFor($team, (int) config('billing.plans.starter.leads') + 30);

    // The gateway reports a refusal — Stripe answered, and the answer was no, so nothing was
    // recorded and these units are safe to send again.
    $gateway->failWith = BillingException::overageRefused();

    expect(fn () => app(OverageReporter::class)->report($team))->toThrow(BillingException::class);

    expect(leadMeter($team)->reported)->toBe(0);

    $gateway->failWith = null;
    app(OverageReporter::class)->report($team);

    expect($gateway->reportedQuantityFor((string) config('billing.overage.leads.stripe_price')))->toBe(30);
});

it('keeps the claim when the outcome is unknown, rather than risk billing twice', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    leadsFor($team, (int) config('billing.plans.starter.leads') + 30);

    // A timeout, a 5xx, a dropped connection: Stripe may have recorded the event and the response
    // may have been lost coming back. Releasing on this is how the same units get billed twice.
    $gateway->failWith = BillingException::gatewayFailed();

    expect(fn () => app(OverageReporter::class)->report($team))->toThrow(BillingException::class);

    // Held. The error in the billing log is what a human reconciles from — under-billing one claim,
    // loudly, beats charging somebody for units they used once.
    expect(leadMeter($team)->reported)->toBe(30);

    $gateway->failWith = null;
    app(OverageReporter::class)->report($team);

    expect($gateway->callsOf('report_overage'))->toBeEmpty();
});

it('survives the nastiest failure: Stripe accepted it and the answer never came back', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $price = (string) config('billing.overage.leads.stripe_price');

    leadsFor($team, (int) config('billing.plans.starter.leads') + 30);

    // The event is recorded, then the request blows up. This is the case the identifier exists for.
    $gateway->failAfterAcceptingOverage = BillingException::gatewayFailed();

    expect(fn () => app(OverageReporter::class)->report($team))->toThrow(BillingException::class);

    $gateway->failAfterAcceptingOverage = null;

    // Retrying the same claim sends the same identifier, which Stripe discards — so the thirty
    // units are billed once, not twice. Without a deterministic identifier this would be sixty.
    app(OverageReporter::class)->report($team);
    app(OverageReporter::class)->report($team);

    expect($gateway->reportedQuantityFor($price))->toBe(30);
});

it('claims atomically, so two reporters racing cannot both send the same units', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $price = (string) config('billing.overage.leads.stripe_price');

    leadsFor($team, (int) config('billing.plans.starter.leads') + 30);

    // Two reporters that both read the meter before either claimed — the 04:10 schedule and
    // somebody running the command by hand. The claim is a conditional UPDATE, so the second one's
    // update matches no row and it sends nothing.
    $first = app()->make(OverageReporter::class);
    $second = app()->make(OverageReporter::class);

    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team));

    expect(leadMeter($team)->unreportedOverage())->toBe(30);

    $first->report($team);
    $second->report($team);

    expect($gateway->reportedQuantityFor($price))->toBe(30);
});

it('keeps one team’s overage off another team’s invoice', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $a] = Fixtures::team();
    [, $b] = Fixtures::team();

    Fixtures::subscribe($a, PlanKey::Starter);
    Fixtures::subscribe($b, PlanKey::Starter);

    leadsFor($a, (int) config('billing.plans.starter.leads') + 15);

    app(OverageReporter::class)->report($a);
    app(OverageReporter::class)->report($b);

    $calls = $gateway->callsOf('report_overage');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['team_id'])->toBe((int) $a->getKey());
});

it('attributes usage to the month the run finished in', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $period = BillingPeriod::containing();

    leadsFor($team, 40);
    leadsFor($team, 500, $period->start->subDay());

    // A run that finished last month belongs to last month's invoice, not this one's.
    expect(app(UsageLedger::class)->usage($team, BillingMetric::Leads)->used)->toBe(40);
});

it('never rewrites a month that has closed', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $closed = BillingPeriod::containing()->previous();

    leadsFor($team, 700, $closed->start->addDays(10));

    // The first sync after the month closed snapshots it and seals it.
    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team, $closed));

    $meter = app(TeamContext::class)->run(
        $team,
        fn () => app(UsageLedger::class)->meter($team, BillingMetric::Leads, $closed),
    );

    expect($meter->used)->toBe(700)
        ->and($meter->included)->toBe((int) config('billing.plans.starter.leads'));

    // Now the two things that would corrupt a settled month: Compliance's retention sweep prunes
    // the runs behind it, and the team moves to a plan with a different allowance.
    DiscoveryRun::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->delete();
    $team->subscription()->forceFill([
        'stripe_price' => (string) config('billing.plans.scale.stripe_price'),
    ])->save();
    app(PlanResolver::class)->forget($team);

    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team, $closed));

    $meter->refresh();

    // Unchanged. A settled month that re-read the live ledgers would be billed for less than it
    // cost, and one that re-read the current plan's allowance would be sized against a plan the
    // team was not on at the time.
    expect($meter->used)->toBe(700)
        ->and($meter->included)->toBe((int) config('billing.plans.starter.leads'));
});

it('still bills a closed month after the team has moved to a plan with no overage', function (): void {
    $gateway = Fixtures::fakeGateway();
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    $closed = BillingPeriod::containing()->previous();
    $allowance = (int) config('billing.plans.starter.leads');

    leadsFor($team, $allowance + 40, $closed->start->addDays(5));

    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team, $closed));

    // They have since dropped to a plan that stops rather than metering. Last month's overage is
    // still owed — it was run up under a plan that permitted it.
    Fixtures::onFreePlan($team);

    app(OverageReporter::class)->report($team, $closed);

    expect($gateway->reportedQuantityFor((string) config('billing.overage.leads.stripe_price')))->toBe(40);
});

it('counts the companies a failed run already delivered', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    // A run persists its leads and *then* settles. One that dies in between has given the team its
    // companies, and counting only completed runs would make those free.
    app(TeamContext::class)->run($team, fn () => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'status' => DiscoveryRunStatus::Failed,
        'leads_created' => 25,
        'completed_at' => now(),
    ]));

    expect(app(UsageLedger::class)->usage($team, BillingMetric::Leads)->used)->toBe(25);
});

it('writes a meter row that survives the ledger behind it', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::subscribe($team, PlanKey::Starter);

    leadsFor($team, 120);
    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team));

    $meter = leadMeter($team);

    expect($meter->used)->toBe(120)
        ->and($meter->included)->toBe((int) config('billing.plans.starter.leads'))
        ->and($meter->synced_at)->not->toBeNull();

    // Syncing again must not double anything — it writes absolute values, it does not add.
    app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team));

    expect(leadMeter($team)->used)->toBe(120)
        ->and(UsageMeter::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(2);
});
