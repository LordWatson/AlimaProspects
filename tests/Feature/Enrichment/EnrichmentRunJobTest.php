<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Contracts\CreditReserver;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\CreditReservation;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Events\LeadEnrichmentUpdated;
use App\Modules\Enrichment\Jobs\RunEnrichmentWaterfallJob;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\EnrichmentProviderRegistry;
use App\Modules\Enrichment\Services\EnrichmentWaterfall;
use App\Modules\Enrichment\Testing\FakeEmailFinder;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * The job that brackets the spending (PL3).
 *
 * What is tested here is the money: that the allowance is held before the calls and settled against
 * what the **attempts** say was spent, that a refusal is a refusal and not a failure, and that a
 * reservation is never left held by a job that died.
 */

beforeEach(function (): void {
    Event::fake([LeadEnrichmentUpdated::class]);

    $this->team = Team::factory()->create();

    $this->lead = app(TeamContext::class)->run($this->team, function (): Lead {
        $lead = Lead::factory()->create([
            'team_id' => $this->team->getKey(),
            'website' => 'https://zephyr.test',
        ]);

        LeadContact::factory()->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => null,
            'email_hash' => null,
            'name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        return $lead->fresh(['contacts']);
    });
});

/**
 * A reserver that records what it was asked and what it was told.
 */
function spyReserver(bool $grant = true): object
{
    return new class($grant) implements CreditReserver
    {
        public int $reserved = 0;

        public int $committedMicros = -1;

        public int $released = 0;

        public function __construct(private readonly bool $grant) {}

        public function reserve(Team $team, EnrichmentScope $scope, int $units): CreditReservation
        {
            $this->reserved = $units;

            return $this->grant
                ? CreditReservation::grant($scope, $units)
                : CreditReservation::refuse($scope, 'enrichment.allowance_spent');
        }

        public function commit(CreditReservation $reservation, int $spentMicros, int $unitsUsed): void
        {
            $this->committedMicros = $spentMicros;
        }

        public function release(CreditReservation $reservation): void
        {
            $this->released++;
        }
    };
}

function runWith(Team $team, Lead $lead, EnrichmentScope $scope): EnrichmentRun
{
    return app(TeamContext::class)->run($team, fn (): EnrichmentRun => EnrichmentRun::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'scope' => $scope,
        'state' => EnrichmentRunState::Queued,
    ]));
}

function registerFinder(FakeEmailFinder $finder): void
{
    $registry = new EnrichmentProviderRegistry;
    $registry->register($finder);
    app()->instance(EnrichmentProviderRegistry::class, $registry);
    config([ProviderCapability::EmailFind->waterfallKey() => [$finder->key()]]);
}

it('reserves before the calls and commits what the attempts actually cost', function (): void {
    registerFinder((new FakeEmailFinder('finder', cost: new CostProfile(perSuccessMicros: 500)))->willFind('jane@zephyr.test'));

    $reserver = spyReserver();
    $this->app->instance(CreditReserver::class, $reserver);

    $run = runWith($this->team, $this->lead, EnrichmentScope::Email);

    (new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey()))
        ->handle(app(TeamContext::class), app(EnrichmentWaterfall::class), $reserver);

    expect($reserver->reserved)->toBe(1)
        // From the attempts, not from the summary: a run whose worker died between its last call
        // and its summary still owes for the calls it made.
        ->and($reserver->committedMicros)->toBe(500)
        ->and($run->fresh()->state)->toBe(EnrichmentRunState::Completed);
});

it('refuses without calling anybody when the allowance is spent', function (): void {
    $finder = (new FakeEmailFinder('finder'))->willFind('jane@zephyr.test');
    registerFinder($finder);

    $reserver = spyReserver(grant: false);
    $this->app->instance(CreditReserver::class, $reserver);

    $run = runWith($this->team, $this->lead, EnrichmentScope::Email);

    (new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey()))
        ->handle(app(TeamContext::class), app(EnrichmentWaterfall::class), $reserver);

    expect($finder->callCount())->toBe(0)
        ->and($run->fresh()->state)->toBe(EnrichmentRunState::Refused)
        ->and($run->fresh()->error_code)->toBe('enrichment.allowance_spent')
        // A team at its ceiling is **not** a provider outage, and the operations screen must not
        // be told it is.
        ->and($run->fresh()->state->isProviderFailure())->toBeFalse();
});

it('announces the run on the way in and the way out', function (): void {
    registerFinder((new FakeEmailFinder('finder'))->willFind('jane@zephyr.test'));

    $reserver = spyReserver();
    $this->app->instance(CreditReserver::class, $reserver);

    $run = runWith($this->team, $this->lead, EnrichmentScope::Email);

    (new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey()))
        ->handle(app(TeamContext::class), app(EnrichmentWaterfall::class), $reserver);

    // Twice: running, then finished. A screen that opened mid-run sees the spinner rather than a
    // result that appears from nowhere.
    Event::assertDispatchedTimes(LeadEnrichmentUpdated::class, 2);

    Event::assertDispatched(LeadEnrichmentUpdated::class, function (LeadEnrichmentUpdated $event): bool {
        $payload = $event->broadcastWith();

        // Counts only. A revealed address in a broadcast is a paid reveal delivered to every
        // member of the team with a screen open.
        return $payload['lead_id'] === (int) $this->lead->getKey()
            && ! str_contains(json_encode($payload), '@');
    });
});

it('does nothing twice when the run has already settled', function (): void {
    $finder = (new FakeEmailFinder('finder'))->willFind('jane@zephyr.test');
    registerFinder($finder);

    $reserver = spyReserver();
    $this->app->instance(CreditReserver::class, $reserver);

    $run = app(TeamContext::class)->run($this->team, fn (): EnrichmentRun => EnrichmentRun::factory()
        ->completed(['emails_found' => 1])
        ->create([
            'team_id' => $this->team->getKey(),
            'lead_id' => $this->lead->getKey(),
            'scope' => EnrichmentScope::Email,
        ]));

    (new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey()))
        ->handle(app(TeamContext::class), app(EnrichmentWaterfall::class), $reserver);

    // A retried job must not re-pay for a waterfall that has already run.
    expect($finder->callCount())->toBe(0)
        ->and($reserver->reserved)->toBe(0);
});

it('refuses a run whose lead has gone', function (): void {
    registerFinder((new FakeEmailFinder('finder'))->willFind('jane@zephyr.test'));

    $reserver = spyReserver();
    $this->app->instance(CreditReserver::class, $reserver);

    $run = runWith($this->team, $this->lead, EnrichmentScope::Email);

    app(TeamContext::class)->run($this->team, fn () => $this->lead->delete());

    (new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey()))
        ->handle(app(TeamContext::class), app(EnrichmentWaterfall::class), $reserver);

    // Nothing reserved, because nothing was going to be spent.
    expect($reserver->reserved)->toBe(0);
});

it('is unique per run', function (): void {
    $run = runWith($this->team, $this->lead, EnrichmentScope::Email);

    $job = new RunEnrichmentWaterfallJob((int) $this->team->getKey(), (int) $run->getKey());

    // Per run, not per lead-and-scope: the Action already refuses a second request while one is in
    // flight, and this is the narrower guarantee that one run is not executed by two workers.
    expect($job->uniqueId())->toBe((string) $run->getKey())
        ->and($job->tries)->toBe(1);
});
