<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Data\IcpCalibration;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Services\IcpCalibrator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<CriterionScore>  $breakdown
 */
function scoreFor(Team $team, Icp $icp, ScoreGrade $grade, array $breakdown, bool $excluded = false): LeadScore
{
    return app(TeamContext::class)->run($team, function () use ($team, $icp, $grade, $breakdown, $excluded): LeadScore {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        return LeadScore::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'grade' => $grade,
            'excluded' => $excluded,
            'breakdown' => $breakdown,
        ]);
    });
}

it('summarises the grade mix and per-criterion outcomes from stored scores', function (): void {
    $team = Team::factory()->create();
    $icp = app(TeamContext::class)->run($team, fn (): Icp => Icp::factory()->active()->create(['team_id' => $team->getKey()]));

    // An A whose industry matched and technology was deferred.
    scoreFor($team, $icp, ScoreGrade::A, [
        new CriterionScore(CriterionType::Industry, 'SaaS', 80, CriterionMatch::Matched),
        new CriterionScore(CriterionType::Technographic, 'Shopify', 20, CriterionMatch::Weak),
    ]);

    // A C whose industry was deferred and geography confidently missed.
    scoreFor($team, $icp, ScoreGrade::C, [
        new CriterionScore(CriterionType::Industry, 'SaaS', 80, CriterionMatch::Weak),
        new CriterionScore(CriterionType::Geography, 'London', 20, CriterionMatch::NotMatched),
    ]);

    // An excluded D: its breakdown is only the exclusion row, so it adds to the mix but to no
    // criterion.
    scoreFor($team, $icp, ScoreGrade::D, [
        new CriterionScore(CriterionType::Exclusion, 'acme.test', 0, CriterionMatch::Matched),
    ], excluded: true);

    $calibration = app(TeamContext::class)->run(
        $team,
        fn (): IcpCalibration => app(IcpCalibrator::class)->summarise($icp),
    );

    expect($calibration->scoredLeads)->toBe(3)
        ->and($calibration->excluded)->toBe(1)
        ->and($calibration->grades)->toBe(['A' => 1, 'B' => 0, 'C' => 1, 'D' => 1])
        ->and($calibration->criteria)->toHaveCount(3)
        // Heaviest criterion first.
        ->and($calibration->criteria[0]->type)->toBe(CriterionType::Industry->value);

    $industry = collect($calibration->criteria)->firstWhere('type', CriterionType::Industry->value);

    expect($industry?->matched)->toBe(1)
        ->and($industry?->weak)->toBe(1)
        ->and($industry?->notMatched)->toBe(0)
        ->and($industry?->unknown)->toBe(0)
        ->and($industry?->total())->toBe(2);
});

it('returns an empty calibration when nothing has been scored', function (): void {
    $team = Team::factory()->create();
    $icp = app(TeamContext::class)->run($team, fn (): Icp => Icp::factory()->active()->create(['team_id' => $team->getKey()]));

    $calibration = app(TeamContext::class)->run(
        $team,
        fn (): IcpCalibration => app(IcpCalibrator::class)->summarise($icp),
    );

    expect($calibration->scoredLeads)->toBe(0)
        ->and($calibration->criteria)->toBe([])
        ->and($calibration->grades)->toBe(['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0]);
});

it('binds the calibrator as the calibration source', function (): void {
    expect(app(IcpCalibrationSource::class))->toBeInstanceOf(IcpCalibrator::class);
});

it('keeps one team’s calibration out of another’s', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    $icpA = app(TeamContext::class)->run($teamA, fn (): Icp => Icp::factory()->active()->create(['team_id' => $teamA->getKey()]));
    $icpB = app(TeamContext::class)->run($teamB, fn (): Icp => Icp::factory()->active()->create(['team_id' => $teamB->getKey()]));

    scoreFor($teamA, $icpA, ScoreGrade::A, [
        new CriterionScore(CriterionType::Industry, 'SaaS', 80, CriterionMatch::Matched),
    ]);

    $calibrationB = app(TeamContext::class)->run(
        $teamB,
        fn (): IcpCalibration => app(IcpCalibrator::class)->summarise($icpB),
    );

    expect($calibrationB->scoredLeads)->toBe(0);
});
