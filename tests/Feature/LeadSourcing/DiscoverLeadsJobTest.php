<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Events\DiscoveryRunProgressed;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Testing\FakeLeadSourceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// A completed run now fans out enrichment jobs (Phase 8). These tests drive the discovery job
// directly and assert on the run/leads only, so fake the queue to record — not execute — that
// hand-off; the trigger itself is covered in tests/Feature/Enrichment.
beforeEach(fn () => Queue::fake());

/**
 * @param  list<SourcedLead>  $leads
 * @return array{Team, DiscoveryRun, FakeLeadSourceProvider}
 */
function bootDiscovery(array $leads = [], int $requestedLimit = 25): array
{
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'requested_limit' => $requestedLimit,
    ]));

    $fake = (new FakeLeadSourceProvider)->willReturn($leads);
    app(LeadSourceProviderRegistry::class)->register($fake);

    return [$team, $run, $fake];
}

function sourcedCompany(string $name, string $number): SourcedLead
{
    return new SourcedLead(
        source: LeadSource::CompaniesHouse,
        companyName: $name,
        companyNumber: $number,
        companyStatus: 'active',
        country: 'GB',
    );
}

function runDiscovery(Team $team, DiscoveryRun $run): void
{
    (new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey()))->handle(
        app(LeadSourceProviderRegistry::class),
        app(TeamContext::class),
        app(LeadCandidateFilter::class),
    );
}

it('persists discovered leads and completes the run with counts', function (): void {
    [$team, $run] = bootDiscovery([
        sourcedCompany('Acme Ltd', '11111111'),
        sourcedCompany('Beta Ltd', '22222222'),
    ]);

    runDiscovery($team, $run);

    $run->refresh();
    expect($run->status)->toBe(DiscoveryRunStatus::Completed)
        ->and($run->leads_found)->toBe(2)
        ->and($run->leads_created)->toBe(2)
        ->and($run->completed_at)->not->toBeNull();

    $leads = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->get();
    expect($leads)->toHaveCount(2)
        ->and($leads->first()->discovery_run_id)->toBe($run->getKey())
        ->and($leads->first()->team_id)->toBe($team->getKey())
        ->and($leads->first()->collected_at)->not->toBeNull()
        ->and($leads->first()->source)->toBe(LeadSource::CompaniesHouse);
});

it('de-duplicates leads by dedupe key across runs (placeholder)', function (): void {
    [$team, $run] = bootDiscovery([sourcedCompany('Acme Ltd', '11111111')]);
    runDiscovery($team, $run);

    // A second run that finds the same company updates in place — no duplicate row.
    $run2 = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $team->getKey()]));
    app(LeadSourceProviderRegistry::class)->register((new FakeLeadSourceProvider)->willReturn([sourcedCompany('Acme Ltd', '11111111')]));

    runDiscovery($team, $run2);

    expect(Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        ->and($run2->fresh()->leads_found)->toBe(1)
        ->and($run2->fresh()->leads_created)->toBe(0); // updated, not created
});

it('de-duplicates the same company found by two different providers', function (): void {
    $team = Team::factory()->create();

    // A Google Places run finds acme.com.
    $placesRun = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::GooglePlaces,
    ]));
    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::GooglePlaces))->willReturn([
            new SourcedLead(source: LeadSource::GooglePlaces, companyName: 'Acme', website: 'https://acme.com'),
        ]),
    );
    runDiscovery($team, $placesRun);

    // A web-search run then finds the same site (different URL path, same domain).
    $webRun = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
    ]));
    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))->willReturn([
            new SourcedLead(source: LeadSource::WebSearch, companyName: 'Acme Ltd', website: 'https://www.acme.com/about'),
        ]),
    );
    runDiscovery($team, $webRun);

    // One lead: both resolved to dedupe key `domain:acme.com`.
    $lead = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect(Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1)
        ->and($webRun->fresh()->leads_created)->toBe(0) // the web run updated, not created
        ->and($placesRun->fresh()->leads_created)->toBe(1)
        // The lower-ranked web-search run must NOT overwrite the richer Google Places attribution.
        ->and($lead->source)->toBe(LeadSource::GooglePlaces)
        ->and($lead->company_name)->toBe('Acme')
        ->and($lead->discovery_run_id)->toBe($placesRun->getKey());
});

it('upgrades attribution when a higher-ranked provider re-finds a lead', function (): void {
    $team = Team::factory()->create();

    // A web-search run finds acme.com first.
    $webRun = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
    ]));
    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))->willReturn([
            new SourcedLead(source: LeadSource::WebSearch, companyName: 'Acme (web title)', website: 'https://acme.com'),
        ]),
    );
    runDiscovery($team, $webRun);

    // Then Google Places (higher rank) re-finds the same domain with a cleaner name.
    $placesRun = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::GooglePlaces,
    ]));
    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::GooglePlaces))->willReturn([
            new SourcedLead(source: LeadSource::GooglePlaces, companyName: 'Acme Coffee Roasters', website: 'https://acme.com'),
        ]),
    );
    runDiscovery($team, $placesRun);

    $lead = Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($lead->source)->toBe(LeadSource::GooglePlaces)
        ->and($lead->company_name)->toBe('Acme Coffee Roasters')
        ->and($lead->discovery_run_id)->toBe($webRun->getKey()); // original discoverer preserved
});

it('respects the requested limit from the query', function (): void {
    [$team, $run, $fake] = bootDiscovery([
        sourcedCompany('A Ltd', '1'),
        sourcedCompany('B Ltd', '2'),
        sourcedCompany('C Ltd', '3'),
    ], requestedLimit: 2);

    // The run factory's params default limit is 25, so set it to match the requested cap.
    $run->forceFill(['params' => [...$run->params, 'limit' => 2]])->save();

    runDiscovery($team, $run);

    expect($fake->lastQuery()?->limit)->toBe(2)
        ->and(Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(2);
});

it('broadcasts progress and a terminal completed event', function (): void {
    Event::fake([DiscoveryRunProgressed::class]);

    [$team, $run] = bootDiscovery([sourcedCompany('Acme Ltd', '11111111')]);

    runDiscovery($team, $run);

    Event::assertDispatched(
        DiscoveryRunProgressed::class,
        fn (DiscoveryRunProgressed $e): bool => $e->runId === (int) $run->getKey()
            && $e->status === DiscoveryRunStatus::Completed
            && $e->percent === 100,
    );
});

it('no-ops on a run that is already terminal (idempotent retry)', function (): void {
    $team = Team::factory()->create();
    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->completed()->create(['team_id' => $team->getKey()]));

    $fake = (new FakeLeadSourceProvider)->willReturn([sourcedCompany('Acme Ltd', '11111111')]);
    app(LeadSourceProviderRegistry::class)->register($fake);

    runDiscovery($team, $run);

    expect($fake->lastQuery())->toBeNull() // provider never called
        ->and(Lead::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(0);
});

it('fails the run fast (no retry) on a non-retryable provider error', function (): void {
    $team = Team::factory()->create();
    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $team->getKey()]));

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider)->willFail(LeadSourcingException::providerMisconfigured('companies_house')),
    );

    // A misconfiguration is terminal: the job handles it without throwing (so the queue won't retry).
    runDiscovery($team, $run);

    expect($run->fresh()->status)->toBe(DiscoveryRunStatus::Failed)
        ->and($run->fresh()->error_message)->not->toBeNull();
});

it('marks the run failed via failed() after a provider error', function (): void {
    Event::fake([DiscoveryRunProgressed::class]);

    $team = Team::factory()->create();
    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $team->getKey()]));

    app(LeadSourceProviderRegistry::class)->register((new FakeLeadSourceProvider)->willFail(new RuntimeException('provider down')));

    $job = new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey());

    // Running throws (so the queue would retry); the run is left non-terminal, then failed() settles it.
    expect(fn () => $job->handle(
        app(LeadSourceProviderRegistry::class),
        app(TeamContext::class),
        app(LeadCandidateFilter::class),
    ))->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('provider down'));

    expect($run->fresh()->status)->toBe(DiscoveryRunStatus::Failed)
        ->and($run->fresh()->error_message)->not->toBeNull();

    Event::assertDispatched(
        DiscoveryRunProgressed::class,
        fn (DiscoveryRunProgressed $e): bool => $e->status === DiscoveryRunStatus::Failed,
    );
});

it('never persists a candidate the run’s exclusions reject', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'params' => [
            'terms' => 'boutique hotels',
            'location' => 'Manchester',
            'limit' => 25,
            'icp_id' => null,
            'industries' => [],
            'exclude_terms' => ['graphic design'],
            'customer_description' => 'Independent hotels',
            'shaped' => true,
        ],
    ]));

    app(LeadSourceProviderRegistry::class)->register((new FakeLeadSourceProvider)->willReturn([
        sourcedCompany('The Grand Hotel', '11111111'),
        sourcedCompany('Bright Graphic Design Ltd', '22222222'),
    ]));

    runDiscovery($team, $run);

    // The point of filtering before persistence: an excluded company never reaches the workspace, so
    // it is never enriched (paid calls), never counted against the allowance, and never read.
    $names = app(TeamContext::class)->run(
        $team,
        fn (): array => Lead::query()->where('team_id', $team->getKey())->pluck('company_name')->all(),
    );

    expect($names)->toBe(['The Grand Hotel'])
        ->and($run->fresh()->leads_found)->toBe(1)
        ->and($run->fresh()->leads_created)->toBe(1);
});

it('still meters the provider cost for a run that excluded everything', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
        'params' => [
            'terms' => 'boutique hotels',
            'limit' => 25,
            'exclude_terms' => ['design'],
        ],
    ]));

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))
            ->willReturn([sourcedCompany('Apex Design', '33333333')])
            ->withCostMicros(3_000),
    );

    runDiscovery($team, $run);

    // The request was made and billed whether or not we kept its results. Metering the kept count
    // would show this run as free.
    expect($run->fresh()->leads_found)->toBe(0)
        ->and($run->fresh()->cost_micros)->toBe(3_000);
});

it('meters cost on what the provider returned, not on what the run cap kept', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::WebSearch,
        'requested_limit' => 2,
        'params' => ['terms' => 'hotels', 'limit' => 2],
    ]));

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))
            ->willReturn([
                sourcedCompany('Alpha Hotel', '11111111'),
                sourcedCompany('Beta Hotel', '22222222'),
                sourcedCompany('Gamma Hotel', '33333333'),
                sourcedCompany('Delta Hotel', '44444444'),
            ])
            ->withCostMicros(3_000),
    );

    runDiscovery($team, $run);

    // The cap is ours, not the provider's: it returned four and billed for the request, so the run
    // keeps two and still records the cost.
    expect($run->fresh()->leads_found)->toBe(2)
        ->and($run->fresh()->leads_created)->toBe(2)
        ->and($run->fresh()->cost_micros)->toBe(3_000);
});
