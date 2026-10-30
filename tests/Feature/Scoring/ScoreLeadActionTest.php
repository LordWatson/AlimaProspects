<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Actions\ScoreLeadAction;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    // The AI response cache would mask a second call; scoring is tested call-by-call.
    config()->set('ai-platform.cache.enabled', false);
});

/**
 * @return array<string, mixed>
 */
function fitPayload(int $score = 70, string $grade = 'B'): array
{
    return [
        'score' => $score,
        'grade' => $grade,
        'rationale' => 'A speciality roaster in London selling to offices — squarely in the profile.',
        'strengths' => ['Right industry', 'Right city'],
        'concerns' => ['No size on record'],
    ];
}

/**
 * Bind the fake AI transport and build a team with an active ICP.
 *
 * @param  list<array{CriterionType, string, int|null}>  $criteria
 * @return array{Team, Icp, FakeAiTransport}
 */
function bootScoring(array $criteria = [[CriterionType::Industry, 'Coffee roasting', 80]]): array
{
    $fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $fakeAi);
    app()->forgetInstance(AiClient::class);

    $team = Team::factory()->create();

    $icp = app(TeamContext::class)->run($team, function () use ($team, $criteria): Icp {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey(), 'name' => 'UK coffee']);

        foreach ($criteria as $position => [$type, $value, $weight]) {
            $icp->criteria()->create([
                'team_id' => $team->getKey(),
                'type' => $type,
                'value' => $value,
                'weight' => $weight,
                'position' => $position,
            ]);
        }

        return $icp->load('criteria');
    });

    return [$team, $icp, $fakeAi];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function leadToScore(Team $team, array $attributes = []): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'industry' => 'Speciality coffee roasting',
        'locality' => 'London',
        'website' => 'https://acme.test',
        'employee_range' => null,
        'technologies' => null,
        'enriched_at' => now()->subMinute(),
        ...$attributes,
    ]));
}

it('blends the rules with the AI assessment into a graded score with an explanation', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload(score: 60));

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->not->toBeNull();

    // Rules say 100 (industry matched, nothing else assessable), the AI says 60 → 0.6/0.4 blend = 84.
    expect($score->deterministic_score)->toBe(100)
        ->and($score->ai_score)->toBe(60)
        ->and($score->score)->toBe(84)
        ->and($score->grade)->toBe(ScoreGrade::A)
        ->and($score->explanation)->toContain('speciality roaster')
        ->and($score->strengths)->toBe(['Right industry', 'Right city'])
        ->and($score->concerns)->toBe(['No size on record'])
        ->and($score->prompt_key)->toBe('scoring.qualitative_fit')
        ->and($score->prompt_version)->toBe(1)
        ->and($score->breakdown)->toHaveCount(1)
        ->and($score->scored_at)->not->toBeNull()
        ->and($score->cost_micros)->toBeGreaterThan(0);

    expect($lead->fresh()?->status)->toBe(LeadStatus::Scored);
});

it('sends only stored facts to the model, as untrusted data', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload());

    app(TeamContext::class)->run($team, fn () => app(ScoreLeadAction::class)->execute($lead));

    $prompt = $fakeAi->lastRequest()?->prompt ?? '';

    expect($prompt)->toContain('UNTRUSTED DATA')
        ->and($prompt)->toContain('Speciality coffee roasting')
        ->and($prompt)->toContain('Coffee roasting')
        // A field we don't hold is named as unknown rather than left out, so the model can't fill it.
        ->and($prompt)->toContain('Employee range: unknown');
});

it('does not score when the team has no active ICP', function (): void {
    [$team, $icp, $fakeAi] = bootScoring();
    app(TeamContext::class)->run($team, fn () => $icp->forceFill(['is_active' => false])->save());

    $lead = leadToScore($team);

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->toBeNull()
        ->and($fakeAi->callCount())->toBe(0)
        ->and(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('grades an excluded lead D without spending anything on the AI', function (): void {
    [$team, , $fakeAi] = bootScoring([
        [CriterionType::Industry, 'Coffee roasting', 80],
        [CriterionType::Exclusion, 'acme.test', null],
    ]);

    $lead = leadToScore($team);

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->not->toBeNull();
    expect($score->excluded)->toBeTrue()
        ->and($score->excluded_by)->toBe('acme.test')
        ->and($score->score)->toBe(0)
        ->and($score->grade)->toBe(ScoreGrade::D)
        ->and($score->ai_score)->toBeNull()
        // No invented prose for an exclusion — the matched exclusion is the explanation.
        ->and($score->explanation)->toBeNull()
        ->and($score->cost_micros)->toBe(0)
        ->and($fakeAi->callCount())->toBe(0);
});

it('falls back to the rules alone when the AI half is unavailable', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    // An un-validatable response, twice (the client retries once), exhausts the attempts.
    $fakeAi->queueText('not json')->queueText('still not json');

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->not->toBeNull();
    expect($score->ai_score)->toBeNull()
        ->and($score->deterministic_score)->toBe(100)
        ->and($score->score)->toBe(100)
        ->and($score->grade)->toBe(ScoreGrade::A)
        ->and($score->explanation)->toBeNull()
        ->and($score->prompt_key)->toBeNull();
});

it('writes no score when neither half has anything to say', function (): void {
    // Only a buying signal: the rules must abstain, and the AI is unavailable.
    [$team, , $fakeAi] = bootScoring([[CriterionType::BuyingSignal, 'Raised funding', 70]]);
    $lead = leadToScore($team);

    $fakeAi->queueText('not json')->queueText('still not json');

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->toBeNull()
        ->and(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('reuses a score that is already newer than its inputs', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload());

    $action = app(ScoreLeadAction::class);

    $first = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));
    $second = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead->fresh() ?? $lead));

    expect($fakeAi->callCount())->toBe(1)
        ->and($second?->getKey())->toBe($first?->getKey())
        ->and($second?->scored_at?->toIso8601String())->toBe($first?->scored_at?->toIso8601String());
});

it('re-scores in place when forced, keeping one row per lead and ICP', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload(score: 60))->queueStructured(fitPayload(score: 20));

    $action = app(ScoreLeadAction::class);

    $first = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));
    $second = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead->fresh() ?? $lead, force: true));

    expect($fakeAi->callCount())->toBe(2)
        ->and($second?->getKey())->toBe($first?->getKey())
        ->and($second?->ai_score)->toBe(20)
        ->and($second?->score)->toBe(68)
        ->and($second?->cost_micros)->toBeGreaterThan((int) $first?->cost_micros)
        ->and(LeadScore::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('re-scores when the lead’s enriched data has moved on', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload(score: 60))->queueStructured(fitPayload(score: 90));

    $action = app(ScoreLeadAction::class);

    app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));

    // Enrichment ran again and found more, after the score was taken.
    app(TeamContext::class)->run($team, fn () => $lead->forceFill([
        'employee_range' => '11-50',
        'enriched_at' => now()->addMinute(),
    ])->save());

    $second = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead->fresh() ?? $lead));

    expect($fakeAi->callCount())->toBe(2)->and($second?->ai_score)->toBe(90);
});

it('clamps a runaway AI response rather than storing it', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    // Schema-valid, but verbose: a 10k-character rationale and forty "strengths".
    $fakeAi->queueStructured([
        'score' => 100,
        'grade' => 'A',
        'rationale' => str_repeat('long ', 2_000),
        'strengths' => array_merge(array_fill(0, 40, 'A strength'), ['Another one']),
        'concerns' => [],
    ]);

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score?->ai_score)->toBe(100)
        ->and(mb_strlen((string) $score?->explanation))->toBeLessThanOrEqual(1_500)
        // Duplicates collapse and the list is capped.
        ->and($score?->strengths)->toBe(['A strength', 'Another one']);
});

it('leaves an AI-backed score alone when a re-score finds the AI half down', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload(score: 60));

    $action = app(ScoreLeadAction::class);
    $first = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));

    // The forced re-score can't reach the model (two un-validatable attempts exhaust the retry).
    $fakeAi->queueText('not json')->queueText('still not json');

    $second = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => $action->execute($lead->fresh() ?? $lead, force: true),
    );

    // Replacing a grounded explanation with a rules-only score would be a silent loss — a
    // team-wide re-score that ran out of credit would strip every remaining lead.
    expect($second?->getKey())->toBe($first?->getKey())
        ->and($second?->ai_score)->toBe(60)
        ->and($second?->explanation)->toBe($first?->explanation)
        ->and($second?->score)->toBe($first?->score)
        ->and($second?->scored_at?->toIso8601String())->toBe($first?->scored_at?->toIso8601String());
});

it('refuses to grade a lead it knows almost nothing about, without spending anything', function (): void {
    // One criterion the rules can't assess, on a lead that has never been enriched.
    [$team, , $fakeAi] = bootScoring([[CriterionType::Technographic, 'Shopify', 60]]);

    $lead = leadToScore($team, [
        'industry' => null,
        'locality' => null,
        'country' => null,
        'website' => null,
        'enriched_at' => null,
    ]);

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    expect($score)->toBeNull()
        // The guard runs before the prompt, so a bare lead costs nothing at all.
        ->and($fakeAi->callCount())->toBe(0)
        ->and(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('treats a response with no usable score as no AI half at all', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    // The prompt's schema requires a numeric score, so drop the schema to reach the action's own
    // guard — the case a future un-schema'd prompt version would otherwise walk into.
    AiPromptTemplate::query()
        ->where('key', 'scoring.qualitative_fit')
        ->update(['schema' => null]);

    // Shaped like an assessment but with no score to blend with.
    $fakeAi->queueStructured(['grade' => 'A', 'rationale' => 'Looks great.']);

    $score = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => app(ScoreLeadAction::class)->execute($lead),
    );

    // A missing score must not become a confident zero dragging the blend down.
    expect($score?->ai_score)->toBeNull()
        ->and($score?->score)->toBe(100)
        ->and($score?->explanation)->toBeNull();
});

it('does not invalidate its own score by advancing the lead’s stage', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload());

    $action = app(ScoreLeadAction::class);
    app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));

    // Scoring advances the lead to Scored, which touches `updated_at`. That must not read as a
    // changed input, or every score would be stale the moment it was written.
    $fresh = $lead->fresh();
    expect($fresh?->status)->toBe(LeadStatus::Scored);

    app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($fresh ?? $lead));

    expect($fakeAi->callCount())->toBe(1);
});

it('keeps counting the cost across re-scores', function (): void {
    [$team, , $fakeAi] = bootScoring();
    $lead = leadToScore($team);

    $fakeAi->queueStructured(fitPayload())->queueStructured(fitPayload());

    $action = app(ScoreLeadAction::class);
    $first = app(TeamContext::class)->run($team, fn (): ?LeadScore => $action->execute($lead));
    $second = app(TeamContext::class)->run(
        $team,
        fn (): ?LeadScore => $action->execute($lead->fresh() ?? $lead, force: true),
    );

    expect($second?->cost_micros)->toBe(2 * (int) $first?->cost_micros);
});
