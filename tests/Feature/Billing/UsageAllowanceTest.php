<?php

declare(strict_types=1);

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\UsageEntry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The reserve / commit / release ledger (PL4).
 *
 * These go through {@see UsageAllowance} and, where the claim is about the seam, through the
 * `CreditReserver` the container resolves — because the promise PL3 made was that its no-op would
 * become real as a **binding**, with nothing in Enrichment changing. A test that instantiated
 * `PlanCreditReserver` by hand would pass whether or not that promise had been kept.
 */
function allowanceFor(): UsageAllowance
{
    return app(UsageAllowance::class);
}

/**
 * Give every plan the same figure for one contact metric, so the test does not depend on which
 * plan the fixture team landed on.
 */
function cappedContacts(BillingMetric $metric, int $amount): void
{
    Fixtures::capAllowance($metric, $amount);
}

it('counts a committed reserve as used and leaves the rest alone', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $handle = allowanceFor()->reserve($team, BillingMetric::EmailReveal, 4);

        expect($handle)->toBeString();
        // A hold counts against what is left, because it is money about to be spent.
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(6);
        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(0);

        allowanceFor()->commit((string) $handle, 1);

        // Three of the four were never spent and come back.
        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(1);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(9);
    });
});

it('gives the whole hold back on a release', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::PhoneReveal, 5);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $handle = (string) allowanceFor()->reserve($team, BillingMetric::PhoneReveal, 3);

        allowanceFor()->release($handle);

        expect(allowanceFor()->used($team, BillingMetric::PhoneReveal))->toBe(0);
        expect(allowanceFor()->remaining($team, BillingMetric::PhoneReveal))->toBe(5);
    });
});

it('settles a reservation once, however many times it is told to', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $handle = (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 2);

        // The failure this guards against: a worker that died between its last provider call and
        // its commit is retried, and a double commit is a team charged twice for one run.
        allowanceFor()->commit($handle, 2);
        allowanceFor()->commit($handle, 2);
        allowanceFor()->release($handle);

        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(2);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(8);
    });
});

it('refuses a reserve that would go over, and lets a smaller one through', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 3);

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(allowanceFor()->reserve($team, BillingMetric::EmailReveal, 4))->toBeNull();
        expect(allowanceFor()->reserve($team, BillingMetric::EmailReveal, 3))->toBeString();
        // And nothing is left for a second one.
        expect(allowanceFor()->reserve($team, BillingMetric::EmailReveal, 1))->toBeNull();
    });
});

it('refuses a second reserve for the last unit, counting the first one’s hold', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::PhoneReveal, 1);

    // **Sequential, not concurrent, and the name says so.** The two reserves run one after the
    // other, and what is asserted is that the second counts the first's *hold* rather than the
    // position before it — which is the arithmetic half of the guarantee.
    //
    // The other half cannot be proved here. `lockForUpdate()` is a no-op on SQLite, so this test
    // passes whether or not the locking read in `remainingUnderLock()` is there, and it is the
    // locking read that makes the recount see a sibling transaction's commit on MySQL (see that
    // method's note on REPEATABLE READ). A two-connection MySQL test in the style of
    // `SignalsMysqlDialectTest` is what would catch a regression; it is recorded as an open item in
    // `docs/prelaunch/progress.md`.
    app(TeamContext::class)->run($team, function () use ($team): void {
        $first = allowanceFor()->reserve($team, BillingMetric::PhoneReveal, 1);
        $second = allowanceFor()->reserve($team, BillingMetric::PhoneReveal, 1);

        expect($first)->toBeString();
        expect($second)->toBeNull();
    });
});

it('treats a plan that says nothing about a reveal as none at all', function (): void {
    [, $team] = Fixtures::team();

    // The asymmetry PL4 introduces: an absent `leads` figure means unlimited, and an absent
    // `email_reveal` means zero — because a reveal costs money per unit and a config that forgot
    // one must fail closed rather than hand out an unmetered vendor bill.
    foreach (PlanKey::cases() as $plan) {
        config()->set("billing.plans.{$plan->value}.contacts", []);
    }

    Fixtures::capAllowance(BillingMetric::Leads, null);
    app(PlanResolver::class)->forget();

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(0);
        expect(allowanceFor()->reserve($team, BillingMetric::EmailReveal, 1))->toBeNull();

        // ...while the metrics that had a ledger before PL4 keep reading null as unlimited.
        expect(allowanceFor()->remaining($team, BillingMetric::Leads))->toBeNull();
    });
});

it('adds a top-up to what the team may spend, once per Stripe event', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 2);

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(allowanceFor()->grant(
            $team,
            BillingMetric::EmailReveal,
            50,
            LedgerDirection::TopUp,
            idempotencyKey: 'stripe:evt_1',
        ))->toBeTrue();

        // A redelivery of the same event grants nothing.
        expect(allowanceFor()->grant(
            $team,
            BillingMetric::EmailReveal,
            50,
            LedgerDirection::TopUp,
            idempotencyKey: 'stripe:evt_1',
        ))->toBeFalse();

        expect(allowanceFor()->entitled($team, BillingMetric::EmailReveal))->toBe(52);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(52);
    });
});

it('keeps one team’s ledger out of another’s', function (): void {
    [, $team] = Fixtures::team();
    [, $other] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, fn () => allowanceFor()->grant($team, BillingMetric::EmailReveal, 7));

    app(TeamContext::class)->run($other, function () use ($other): void {
        expect(allowanceFor()->entitled($other, BillingMetric::EmailReveal))->toBe(10);
        expect(allowanceFor()->added($other, BillingMetric::EmailReveal))->toBe(0);
        // And the other team's rows are not even visible.
        expect(UsageEntry::query()->count())->toBe(0);
    });
});

it('leaves last month’s ledger out of this month’s allowance', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, function () use ($team): void {
        // A commit stamped with the previous period — what a team spent before the month turned
        // over, and the reason a top-up bought then does not follow them into this one either.
        UsageEntry::factory()->for($team)->meter(BillingMetric::EmailReveal)->create([
            'quantity' => 9,
            'period' => BillingPeriod::containing()->previous()->key(),
        ]);

        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(0);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(10);
    });
});

it('shows a reveal on the usage screen through the ledger, with top-ups in the allowance', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 4);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $handle = (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 2);
        allowanceFor()->commit($handle, 2);
        allowanceFor()->grant($team, BillingMetric::EmailReveal, 10, LedgerDirection::TopUp);

        $usage = app(UsageLedger::class)->usage($team, BillingMetric::EmailReveal);

        expect($usage->used)->toBe(2);
        // Plan plus what was bought — a bar that ignored the top-up would show a team who had just
        // paid us as still being out.
        expect($usage->included)->toBe(14);
        // And a reveal never meters: it is bought in advance, not invoiced afterwards.
        expect($usage->overageAllowed)->toBeFalse();
    });
});

it('is the reserver the container hands Enrichment', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::PhoneReveal, 1);

    // The seam, not the class: PL3 shipped a permissive default and promised the real one would
    // arrive as a binding.
    $reserver = app(CreditReserver::class);

    app(TeamContext::class)->run($team, function () use ($team, $reserver): void {
        $first = $reserver->reserve($team, EnrichmentScope::Phone, 1);
        expect($first->granted())->toBeTrue();

        $second = $reserver->reserve($team, EnrichmentScope::Phone, 1);
        expect($second->granted())->toBeFalse();
        // The code names which allowance ran out, so the screen can offer the right top-up.
        expect($second->refusalCode)->toBe('billing.no_allowance.phone_reveal');
    });
});

it('never commits more than was held', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $handle = (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 2);

        // A caller that over-reports — a bug, or a provider counted twice — must not be able to
        // consume allowance that was never held, which is the one direction this may not be wrong
        // in: the hold is what the team was told they were spending.
        allowanceFor()->commit($handle, 99);

        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(2);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(8);
    });
});

it('ignores a release that arrives after the commit, and a commit after the release', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, function () use ($team): void {
        // The waterfall's job commits and then releases in a `finally`, so this ordering is the
        // normal one rather than an edge case.
        $committed = (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 2);
        allowanceFor()->commit($committed, 2);
        allowanceFor()->release($committed);

        $released = (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 3);
        allowanceFor()->release($released);
        allowanceFor()->commit($released, 3);

        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(2);
        expect(allowanceFor()->held($team, BillingMetric::EmailReveal))->toBe(0);
        expect(allowanceFor()->remaining($team, BillingMetric::EmailReveal))->toBe(8);
    });
});

it('settles a hold from a worker that has lost its team context', function (): void {
    [, $team] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    $handle = app(TeamContext::class)->run(
        $team,
        fn (): string => (string) allowanceFor()->reserve($team, BillingMetric::EmailReveal, 4),
    );

    // The reason the settle path reads `withoutGlobalScopes()`: a commit arrives from a queued job,
    // and a hold nobody can settle is allowance lost until the month turns over.
    allowanceFor()->commit($handle, 1);

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(allowanceFor()->used($team, BillingMetric::EmailReveal))->toBe(1);
        expect(allowanceFor()->held($team, BillingMetric::EmailReveal))->toBe(0);
    });
});

it('reads a team’s own position from inside another team’s context', function (): void {
    [, $team] = Fixtures::team();
    [, $other] = Fixtures::team();
    cappedContacts(BillingMetric::EmailReveal, 10);

    app(TeamContext::class)->run($team, fn () => allowanceFor()->grant($team, BillingMetric::EmailReveal, 5));

    // A tenancy scope doing its job in the wrong place: with it left on, a read for `$team` made
    // while `$other` is in context gains a second, conflicting `team_id`, sums to zero, and reports
    // the **full** allowance as remaining — an over-spend rather than a leak.
    app(TeamContext::class)->run($other, function () use ($team): void {
        expect(allowanceFor()->added($team, BillingMetric::EmailReveal))->toBe(5);
        expect(allowanceFor()->entitled($team, BillingMetric::EmailReveal))->toBe(15);
    });
});
