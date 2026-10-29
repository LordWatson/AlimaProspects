<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Actions\ScoreLeadAction;
use App\Modules\Scoring\Jobs\ScoreLeadJob;
use App\Modules\Scoring\Models\LeadScore;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    config()->set('ai-platform.cache.enabled', false);
});

/**
 * @return array{Team, Icp, Lead, FakeAiTransport}
 */
function bootJobScoring(): array
{
    $fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $fakeAi);
    app()->forgetInstance(AiClient::class);

    $team = Team::factory()->create();

    [$icp, $lead] = app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::Industry,
            'value' => 'Coffee',
            'weight' => 80,
            'position' => 0,
        ]);

        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'industry' => 'Coffee',
            'enriched_at' => now()->subMinute(),
        ]);

        return [$icp->load('criteria'), $lead];
    });

    return [$team, $icp, $lead, $fakeAi];
}

it('scores the lead inside the team’s context', function (): void {
    [$team, , $lead, $fakeAi] = bootJobScoring();

    $fakeAi->queueStructured(['score' => 70, 'grade' => 'B', 'rationale' => 'Good fit.']);

    // No team is active before the job runs — the job must establish it itself.
    app(TeamContext::class)->forget();

    (new ScoreLeadJob((int) $team->getKey(), (int) $lead->getKey()))
        ->handle(app(ScoreLeadAction::class), app(TeamContext::class));

    $score = LeadScore::query()->withoutGlobalScopes()->firstOrFail();

    expect((int) $score->lead_id)->toBe((int) $lead->getKey())
        ->and((int) $score->team_id)->toBe((int) $team->getKey())
        ->and($score->ai_score)->toBe(70)
        ->and(app(TeamContext::class)->has())->toBeFalse();
});

it('no-ops when the lead has gone (deleted, or merged away by de-duplication)', function (): void {
    [$team, , $lead, $fakeAi] = bootJobScoring();

    app(TeamContext::class)->run($team, fn () => $lead->delete());

    (new ScoreLeadJob((int) $team->getKey(), (int) $lead->getKey()))
        ->handle(app(ScoreLeadAction::class), app(TeamContext::class));

    expect(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($fakeAi->callCount())->toBe(0);
});

it('skips rather than silently falling back when the named ICP has gone', function (): void {
    [$team, $icp, $lead, $fakeAi] = bootJobScoring();
    $icpId = (int) $icp->getKey();

    app(TeamContext::class)->run($team, fn () => $icp->delete());

    (new ScoreLeadJob((int) $team->getKey(), (int) $lead->getKey(), $icpId))
        ->handle(app(ScoreLeadAction::class), app(TeamContext::class));

    expect(LeadScore::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($fakeAi->callCount())->toBe(0);
});

it('keeps an automatic and a forced score for the same lead as distinct queued work', function (): void {
    $auto = new ScoreLeadJob(1, 7);
    $autoForIcp = new ScoreLeadJob(1, 7, 3);
    $forced = new ScoreLeadJob(1, 7, 3, force: true);
    $other = new ScoreLeadJob(1, 8);

    expect($auto->uniqueId())->toBe($auto->uniqueId())
        ->and($autoForIcp->uniqueId())->not->toBe($forced->uniqueId())
        ->and($auto->uniqueId())->not->toBe($autoForIcp->uniqueId())
        ->and($auto->uniqueId())->not->toBe($other->uniqueId());
});
