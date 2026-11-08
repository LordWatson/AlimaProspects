<?php

declare(strict_types=1);

use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Actions\RecalculateLeadConfidenceAction;
use App\Modules\LeadSourcing\Actions\RecordLeadEvidenceAction;
use App\Modules\LeadSourcing\Actions\SubmitEvidenceFeedbackAction;
use App\Modules\LeadSourcing\Data\EvidenceItem;
use App\Modules\LeadSourcing\Enums\EvidenceKind;
use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Jobs\GenerateWhyNowSummaryJob;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Models\LeadEvidenceFeedback;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

/*
 * Recording a reason, and what happens to the lead when one arrives (PL1).
 *
 * The things asserted here are the ones that are invisible until they go wrong: that every lead
 * has evidence whatever created it, that the same fact arriving twice does not count twice, that
 * flagging a fact as wrong lowers the number *immediately* rather than within a queue cycle, and
 * that the summary is not bought twice for the same evidence.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function leadIn(Team $team, array $attributes = []): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        ...$attributes,
    ]));
}

function recordIn(Team $team, Lead $lead, EvidenceItem $item): LeadEvidence
{
    return app(TeamContext::class)->run(
        $team,
        fn (): LeadEvidence => app(RecordLeadEvidenceAction::class)->handle($lead, $item),
    );
}

function newsItem(string $url = 'https://example.test/news/round', ?Carbon $published = null): EvidenceItem
{
    return new EvidenceItem(
        kind: EvidenceKind::Signal,
        source: 'news',
        title: 'Raises Series A funding',
        summary: 'Closed a round led by an institutional investor.',
        sourceLabel: 'Example Wire',
        sourceUrl: $url,
        observedAt: $published ?? Carbon::now()->subDays(2),
    );
}

it('gives every new lead its own provenance as evidence, whatever created it', function (): void {
    $team = Team::factory()->create();

    $lead = leadIn($team, [
        'source' => LeadSource::CompaniesHouse,
        'company_number' => '01234567',
        'source_url' => 'https://find-and-update.company-information.service.gov.uk/company/01234567',
    ]);

    $evidence = $lead->evidence()->get();

    // Structural, not remembered: the observer hangs off the row, so the next path that creates a
    // lead cannot ship one with no reasons and therefore no confidence.
    expect($evidence)->toHaveCount(1)
        ->and($evidence->first()->kind)->toBe(EvidenceKind::RegisterRecord)
        ->and($evidence->first()->source)->toBe('companies_house')
        ->and($evidence->first()->source_label)->toBe('Companies House')
        // The weight is config's, stamped on the row at capture — not the provider's own claim.
        ->and($evidence->first()->reliability)->toBe((int) config('evidence.reliability.companies_house'))
        ->and($lead->fresh()->confidence)->not->toBeNull();
});

it('weights a lead by the source that found it', function (): void {
    $team = Team::factory()->create();

    $register = leadIn($team, ['source' => LeadSource::CompaniesHouse]);
    $guess = leadIn($team, ['source' => LeadSource::Ai]);

    expect($register->fresh()->confidence)->toBeGreaterThan((int) $guess->fresh()->confidence);
});

it('updates the same fact in place rather than counting it twice', function (): void {
    $team = Team::factory()->create();
    $lead = leadIn($team);

    recordIn($team, $lead, newsItem());
    recordIn($team, $lead, newsItem());

    // Two runs finding the same article is the ordinary case, and the identity is the URL — so
    // corroboration keeps meaning independent agreement rather than repetition.
    expect($lead->evidence()->where('source', 'news')->count())->toBe(1);
});

it('identifies an urlless fact by its own content rather than collapsing two of them', function (): void {
    $team = Team::factory()->create();
    $lead = leadIn($team);

    $first = new EvidenceItem(
        kind: EvidenceKind::Firmographic,
        source: 'manual',
        title: 'Met at a trade show',
        observedAt: Carbon::parse('2026-03-01'),
    );

    $second = new EvidenceItem(
        kind: EvidenceKind::Firmographic,
        source: 'manual',
        title: 'Met at a trade show',
        observedAt: Carbon::parse('2026-09-01'),
    );

    recordIn($team, $lead, $first);
    recordIn($team, $lead, $second);

    expect($lead->evidence()->where('source', 'manual')->count())->toBe(2);
});

it('gives a lead a timing score only once a trigger event arrives', function (): void {
    $team = Team::factory()->create();
    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);

    expect($lead->fresh()->timing_score)->toBeNull();

    recordIn($team, $lead, newsItem());

    expect($lead->fresh()->timing_score)->not->toBeNull();
});

it('hides a lead below the team’s threshold and reveals it when the team moves its line', function (): void {
    $team = Team::factory()->create(['min_lead_confidence' => 90]);

    // A web-search lead found today: reliability 45, barely decayed. Strong enough to clear a
    // threshold of 30 and nowhere near one of 90, which is the pair this test needs.
    $lead = leadIn($team, [
        'source' => LeadSource::WebSearch,
        'incorporated_on' => null,
        'collected_at' => Carbon::now(),
    ]);

    expect($lead->fresh()->suppressed_low_confidence)->toBeTrue();

    $team->forceFill(['min_lead_confidence' => 30])->save();

    app(TeamContext::class)->run($team, fn () => app(
        RecalculateLeadConfidenceAction::class
    )->handle($lead, $team));

    expect($lead->fresh()->suppressed_low_confidence)->toBeFalse();
});

it('never hides a lead whose confidence has not been worked out', function (): void {
    $team = Team::factory()->create(['min_lead_confidence' => 90]);
    $lead = leadIn($team);

    // Evidence gone (a retention sweep, a dispute) leaves the lead unjudged, not judged poor.
    app(TeamContext::class)->run($team, function () use ($lead, $team): void {
        $lead->evidence()->delete();
        app(RecalculateLeadConfidenceAction::class)->handle($lead, $team);
    });

    expect($lead->fresh()->confidence)->toBeNull()
        ->and($lead->fresh()->suppressed_low_confidence)->toBeFalse();
});

it('lowers the confidence immediately when a member flags a fact as wrong', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);
    recordIn($team, $lead, newsItem());

    $before = (int) $lead->fresh()->confidence;

    /** @var LeadEvidence $filing */
    $filing = $lead->evidence()->where('source', 'companies_house')->firstOrFail();

    app(TeamContext::class)->run($team, fn () => app(SubmitEvidenceFeedbackAction::class)
        ->handle($team, $filing, $actor, EvidenceVerdict::Wrong, 'Different company of the same name.'));

    // Immediately, not within a queue cycle: the whole calculation is arithmetic over rows this
    // request has just written.
    expect((int) $lead->fresh()->confidence)->toBeLessThan($before)
        ->and($filing->fresh()->is_disputed)->toBeTrue();
});

it('restores a fact when the only member disputing it changes their mind', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);
    /** @var LeadEvidence $filing */
    $filing = $lead->evidence()->firstOrFail();

    $submit = app(SubmitEvidenceFeedbackAction::class);

    app(TeamContext::class)->run($team, function () use ($submit, $team, $filing, $actor): void {
        $submit->handle($team, $filing, $actor, EvidenceVerdict::Wrong);
        $submit->handle($team, $filing, $actor, EvidenceVerdict::Useful);
    });

    expect($filing->fresh()->is_disputed)->toBeFalse()
        // One verdict per member: changing your mind replaces it rather than voting twice.
        ->and(LeadEvidenceFeedback::query()->where('lead_evidence_id', $filing->getKey())->count())->toBe(1);
});

it('keeps a fact disputed while another member is still disputing it', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();
    $colleague = LeadsWorkspaceFixtures::member($team, TeamRole::Member);

    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);
    /** @var LeadEvidence $filing */
    $filing = $lead->evidence()->firstOrFail();

    $submit = app(SubmitEvidenceFeedbackAction::class);

    app(TeamContext::class)->run($team, function () use ($submit, $team, $filing, $actor, $colleague): void {
        $submit->handle($team, $filing, $actor, EvidenceVerdict::Wrong);
        $submit->handle($team, $filing, $colleague, EvidenceVerdict::Wrong);
        // One of the two changes their mind; the other is still saying it is wrong.
        $submit->handle($team, $filing, $actor, EvidenceVerdict::Useful);
    });

    expect($filing->fresh()->is_disputed)->toBeTrue();
});

it('records a flag in the audit trail without copying the member’s words into it', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);
    /** @var LeadEvidence $filing */
    $filing = $lead->evidence()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => app(SubmitEvidenceFeedbackAction::class)
        ->handle($team, $filing, $actor, EvidenceVerdict::Wrong, 'Secret note about a prospect.'));

    $entry = AuditLog::query()->where('action', 'lead.evidence_flagged')->latest('id')->firstOrFail();

    expect($entry->properties['verdict'])->toBe('wrong')
        ->and($entry->properties['has_note'])->toBeTrue()
        ->and(json_encode($entry->properties))->not->toContain('Secret note');
});

it('queues the summary once when the evidence changes and never when it has not', function (): void {
    $team = Team::factory()->create();

    // The sentence is part of the feature, so it is behind the feature's flag: an AI call for a
    // team that cannot see the panel would be spending their credit on something they have not
    // got. The arithmetic is not gated — only the call is.
    Feature::for($team)->activate(PrelaunchFeature::WhyNow->value);

    $lead = leadIn($team, ['source' => LeadSource::CompaniesHouse]);

    Queue::assertPushed(GenerateWhyNowSummaryJob::class, 1);

    // The lead has been summarised for this evidence set. Recording the same fact again must not
    // buy a second sentence — that is the whole point of the hash.
    app(TeamContext::class)->run($team, function () use ($lead): void {
        $hash = app(LeadConfidence::class)->for($lead->fresh())->evidenceHash;
        $lead->forceFill(['why_now_evidence_hash' => $hash])->save();
    });

    Queue::fake();

    app(TeamContext::class)->run($team, fn () => app(
        RecalculateLeadConfidenceAction::class
    )->handle($lead->fresh(), $team));

    Queue::assertNotPushed(GenerateWhyNowSummaryJob::class);
});

it('does not spend a team’s AI credit on a sentence they cannot see', function (): void {
    $team = Team::factory()->create();

    leadIn($team, ['source' => LeadSource::CompaniesHouse]);

    // The confidence is there; the call is not. This is the cost half of the dark launch, and the
    // reason existing teams' discovery runs did not quietly start buying summaries.
    Queue::assertNotPushed(GenerateWhyNowSummaryJob::class);
});

it('keeps one team’s evidence out of another’s reach', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    $theirLead = leadIn($theirs, ['source' => LeadSource::CompaniesHouse]);

    // Read inside my own team's context, which is how every Action and controller runs.
    $visible = app(TeamContext::class)->run(
        $mine,
        fn (): int => LeadEvidence::query()->count(),
    );

    expect($visible)->toBe(0)
        ->and($theirLead->evidence()->count())->toBe(1);
});

it('leaves the list exactly as it was for a team without the feature', function (): void {
    $team = Team::factory()->create(['min_lead_confidence' => 90]);
    $lead = leadIn($team, ['source' => LeadSource::Ai]);

    // The column is filled in regardless — the calculation is dark-launched — so the *flag* has to
    // be what decides whether anything is hidden. Otherwise a dark launch stops being dark.
    expect($lead->fresh()->suppressed_low_confidence)->toBeTrue()
        ->and(PrelaunchFeature::WhyNow->activeFor($team))->toBeFalse();

    $filters = LeadFilters::fromArray([]);
    $builder = app(LeadQueryBuilder::class);

    $withoutFlag = app(TeamContext::class)->run(
        $team,
        fn (): int => $builder->filtered($team, $filters)->count(),
    );

    Feature::for($team)->activate(PrelaunchFeature::WhyNow->value);

    $withFlag = app(TeamContext::class)->run(
        $team,
        fn (): int => $builder->filtered($team, $filters)->count(),
    );

    expect($withoutFlag)->toBe(1)
        ->and($withFlag)->toBe(0);
});
