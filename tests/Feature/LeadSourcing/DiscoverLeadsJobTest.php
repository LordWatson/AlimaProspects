<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\DiscoveryRunStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Events\DiscoveryRunProgressed;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Testing\FakeLeadSourceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

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

it('marks the run failed via failed() after a provider error', function (): void {
    Event::fake([DiscoveryRunProgressed::class]);

    $team = Team::factory()->create();
    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create(['team_id' => $team->getKey()]));

    app(LeadSourceProviderRegistry::class)->register((new FakeLeadSourceProvider)->willFail(new RuntimeException('provider down')));

    $job = new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey());

    // Running throws (so the queue would retry); the run is left non-terminal, then failed() settles it.
    expect(fn () => $job->handle(app(LeadSourceProviderRegistry::class), app(TeamContext::class)))
        ->toThrow(RuntimeException::class);

    $job->failed(new RuntimeException('provider down'));

    expect($run->fresh()->status)->toBe(DiscoveryRunStatus::Failed)
        ->and($run->fresh()->error_message)->not->toBeNull();

    Event::assertDispatched(
        DiscoveryRunProgressed::class,
        fn (DiscoveryRunProgressed $e): bool => $e->status === DiscoveryRunStatus::Failed,
    );
});
