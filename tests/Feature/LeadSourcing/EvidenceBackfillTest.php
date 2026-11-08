<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * `evidence:backfill` (PL1): giving the leads that existed before this phase the evidence they
 * would have been created with.
 *
 * The property worth testing is **idempotence**, because the first run on a real team will be
 * interrupted — a worker restart, a deploy, a laptop lid — and a backfill that doubled a lead's
 * reasons on the second attempt would double its corroboration bonus with them.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * A lead with its provenance row deliberately removed, the way a pre-PL1 lead looks.
 */
function preEvidenceLead(Team $team, LeadSource $source = LeadSource::CompaniesHouse): Lead
{
    return app(TeamContext::class)->run($team, function () use ($team, $source): Lead {
        // `incorporated_on` null and collected today, so the decay is not what this suite is
        // measuring: a backfilled provenance row should carry close to its source's full weight.
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => $source,
            'incorporated_on' => null,
            'collected_at' => now(),
        ]);

        $lead->evidence()->delete();
        $lead->forceFill([
            'confidence' => null,
            'timing_score' => null,
            'confidence_at' => null,
            'suppressed_low_confidence' => false,
        ])->save();

        return $lead->fresh();
    });
}

it('builds a lead’s provenance and gives it a confidence', function (): void {
    $team = Team::factory()->create();
    $lead = preEvidenceLead($team);

    expect($lead->evidence()->count())->toBe(0);

    $this->artisan('evidence:backfill')->assertSuccessful();

    $fresh = $lead->fresh();

    expect($fresh->evidence()->count())->toBe(1)
        ->and($fresh->confidence)->not->toBeNull();
});

it('is idempotent — running it twice leaves one row per fact', function (): void {
    $team = Team::factory()->create();
    $lead = preEvidenceLead($team);

    $this->artisan('evidence:backfill')->assertSuccessful();
    $after = (int) $lead->fresh()->confidence;

    // `--fresh` forces it to re-record rather than skipping leads that already have evidence, so
    // this exercises the write path a second time rather than the skip.
    $this->artisan('evidence:backfill', ['--fresh' => true])->assertSuccessful();

    expect($lead->fresh()->evidence()->count())->toBe(1)
        ->and((int) $lead->fresh()->confidence)->toBe($after);
});

it('surfaces the signal a lead was converted from, and the ones merely about it', function (): void {
    $team = Team::factory()->create();
    $lead = preEvidenceLead($team, LeadSource::Signal);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'lead_id' => $lead->getKey(),
            'headline' => 'Acquired a competitor',
            'source_url' => 'https://example.test/news/acquisition',
        ]);

        // The account-monitoring case (S10): an event matched to a company the team already had,
        // which nobody converted. Invisible on the lead until this backfill.
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'headline' => 'Appointed a new finance director',
            'source_url' => 'https://example.test/news/appointment',
        ]);
    });

    $this->artisan('evidence:backfill')->assertSuccessful();

    $titles = $lead->fresh()->evidence()->pluck('title')->all();

    expect($titles)->toContain('Acquired a competitor')
        ->and($titles)->toContain('Appointed a new finance director')
        // Signal-sourced, so no generic "converted from a signal" row beside the articles.
        ->and($lead->fresh()->evidence()->where('kind', EvidenceKind::Signal)->count())->toBe(2);
});

it('judges each team against its own threshold', function (): void {
    $strict = Team::factory()->create(['min_lead_confidence' => 90]);
    $tolerant = Team::factory()->create(['min_lead_confidence' => 30]);

    $strictLead = preEvidenceLead($strict, LeadSource::WebSearch);
    $tolerantLead = preEvidenceLead($tolerant, LeadSource::WebSearch);

    $this->artisan('evidence:backfill')->assertSuccessful();

    expect($strictLead->fresh()->suppressed_low_confidence)->toBeTrue()
        ->and($tolerantLead->fresh()->suppressed_low_confidence)->toBeFalse();
});

it('can be limited to one team', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    $myLead = preEvidenceLead($mine);
    $theirLead = preEvidenceLead($theirs);

    $this->artisan('evidence:backfill', ['--team' => $mine->getKey()])->assertSuccessful();

    expect($myLead->fresh()->evidence()->count())->toBe(1)
        ->and(LeadEvidence::query()->withoutGlobalScopes()->where('lead_id', $theirLead->getKey())->count())->toBe(0);
});

it('writes a confidence for a lead whose evidence an interrupted run already recorded', function (): void {
    $team = Team::factory()->create();
    $lead = preEvidenceLead($team);

    // Exactly the state an interrupted run leaves: the evidence row written, the number not.
    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        LeadEvidence::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
        ]);
    });

    expect($lead->fresh()->confidence)->toBeNull();

    $this->artisan('evidence:backfill')->assertSuccessful();

    // Without `--fresh` the lead is skipped for *recording* but not for recalculating — otherwise
    // a resumed run would leave a tail of leads with reasons and no figure.
    expect($lead->fresh()->confidence)->not->toBeNull();
});
