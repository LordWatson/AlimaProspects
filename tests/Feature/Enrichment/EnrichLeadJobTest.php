<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Actions\EnrichLeadAction;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the enrich action for the lead inside the team context', function (): void {
    $team = Team::factory()->create();
    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'website' => null, // no website → no fetch/AI; the action just settles the lead
    ]));

    (new EnrichLeadJob((int) $team->getKey(), (int) $lead->getKey()))->handle(
        app(EnrichLeadAction::class),
        app(TeamContext::class),
    );

    expect($lead->refresh()->enrichment_status)->toBe(EnrichmentStatus::Enriched);
});

it('is a no-op when the lead no longer exists', function (): void {
    $team = Team::factory()->create();

    // A missing lead id must not throw.
    (new EnrichLeadJob((int) $team->getKey(), 999_999))->handle(
        app(EnrichLeadAction::class),
        app(TeamContext::class),
    );

    expect(true)->toBeTrue();
});

it('is unique per lead', function (): void {
    $job = new EnrichLeadJob(1, 42);

    expect($job->uniqueId())->toBe('42');
});

it('marks the lead failed on final failure without touching a terminal lead', function (): void {
    $team = Team::factory()->create();

    [$pending, $enriched] = app(TeamContext::class)->run($team, fn (): array => [
        Lead::factory()->create(['team_id' => $team->getKey(), 'enrichment_status' => EnrichmentStatus::Pending]),
        Lead::factory()->create(['team_id' => $team->getKey(), 'enrichment_status' => EnrichmentStatus::Enriched]),
    ]);

    (new EnrichLeadJob((int) $team->getKey(), (int) $pending->getKey()))->failed(new RuntimeException('boom'));
    (new EnrichLeadJob((int) $team->getKey(), (int) $enriched->getKey()))->failed(new RuntimeException('boom'));

    expect($pending->refresh()->enrichment_status)->toBe(EnrichmentStatus::Failed)
        // An already-enriched lead is left alone.
        ->and($enriched->refresh()->enrichment_status)->toBe(EnrichmentStatus::Enriched);
});
