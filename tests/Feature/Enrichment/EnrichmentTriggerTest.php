<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Jobs\DiscoverLeadsJob;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Testing\FakeLeadSourceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues enrichment for each still-pending lead when a discovery run completes', function (): void {
    Queue::fake();

    $team = Team::factory()->create();

    [$run, $pendingIds] = app(TeamContext::class)->run($team, function () use ($team): array {
        $run = DiscoveryRun::factory()->create(['team_id' => $team->getKey()]);

        $pending = Lead::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'discovery_run_id' => $run->getKey(),
            'enrichment_status' => EnrichmentStatus::Pending,
        ]);

        // An already-enriched lead in the same run must not be re-queued.
        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'discovery_run_id' => $run->getKey(),
            'enrichment_status' => EnrichmentStatus::Enriched,
        ]);

        return [$run, $pending->pluck('id')->all()];
    });

    event(new DiscoveryRunCompleted((int) $team->getKey(), (int) $run->getKey()));

    Queue::assertPushed(EnrichLeadJob::class, 2);
    Queue::assertPushed(
        EnrichLeadJob::class,
        fn (EnrichLeadJob $job): bool => in_array($job->leadId, $pendingIds, strict: true),
    );
});

it('fans out enrichment from a completed discovery run', function (): void {
    Queue::fake();

    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): DiscoveryRun => DiscoveryRun::factory()->create([
        'team_id' => $team->getKey(),
        'requested_limit' => 25,
    ]));

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider)->willReturn([
            new SourcedLead(source: LeadSource::CompaniesHouse, companyName: 'Acme Ltd', companyNumber: '11111111'),
        ]),
    );

    (new DiscoverLeadsJob((int) $team->getKey(), (int) $run->getKey()))->handle(
        app(LeadSourceProviderRegistry::class),
        app(TeamContext::class),
        app(LeadCandidateFilter::class),
    );

    Queue::assertPushed(EnrichLeadJob::class, 1);
});
