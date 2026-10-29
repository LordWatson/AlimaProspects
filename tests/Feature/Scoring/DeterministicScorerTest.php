<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\CriterionScore;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Services\DeterministicScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a team with an active ICP whose criteria are the given [type, value, weight] triples.
 *
 * @param  list<array{CriterionType, string, int|null}>  $criteria
 * @return array{Team, Icp}
 */
function icpWith(array $criteria): array
{
    $team = Team::factory()->create();

    $icp = app(TeamContext::class)->run($team, function () use ($team, $criteria): Icp {
        $icp = Icp::factory()->active()->create(['team_id' => $team->getKey()]);

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

    return [$team, $icp];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function scorableLead(Team $team, array $attributes = []): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'industry' => null,
        'employee_range' => null,
        'technologies' => null,
        'locality' => null,
        'region' => null,
        'postal_code' => null,
        'country' => null,
        'website' => null,
        ...$attributes,
    ]));
}

/**
 * @param  list<CriterionScore>  $breakdown
 */
function rowFor(array $breakdown, CriterionType $type): CriterionScore
{
    $row = collect($breakdown)->first(fn (CriterionScore $row): bool => $row->type === $type);

    expect($row)->not->toBeNull();

    /** @var CriterionScore $row */
    return $row;
}

it('normalises weights so the score is the matched share of what could be assessed', function (): void {
    // Industry (80) matches, technologies (20) does not → 80 / 100 = 80.
    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Coffee roasting', 80],
        [CriterionType::Technographic, 'Shopify', 20],
    ]);

    $lead = scorableLead($team, [
        'industry' => 'Speciality coffee roasting',
        'technologies' => ['WooCommerce'],
    ]);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->score)->toBe(80)
        ->and($result->matchedWeight)->toBe(80)
        ->and($result->assessedWeight)->toBe(100)
        ->and($result->hasSignals())->toBeTrue()
        ->and($result->excluded)->toBeFalse();
});

it('is unaffected by how many criteria an ICP has, only by their weights', function (): void {
    // Same 50/50 split, very different criterion counts: both score 50.
    [$teamA, $icpA] = icpWith([
        [CriterionType::Industry, 'Coffee', 50],
        [CriterionType::Technographic, 'Shopify', 50],
    ]);
    [$teamB, $icpB] = icpWith([
        [CriterionType::Industry, 'Coffee', 10],
        [CriterionType::Technographic, 'Shopify', 10],
        [CriterionType::Geography, 'London', 10],
        [CriterionType::CompanySize, '11-50', 10],
    ]);

    $leadA = scorableLead($teamA, ['industry' => 'Coffee', 'technologies' => ['Stripe']]);
    $leadB = scorableLead($teamB, [
        'industry' => 'Coffee',
        'technologies' => ['Stripe'],
        'locality' => 'London',
        'employee_range' => '200-500',
    ]);

    $scorer = app(DeterministicScorer::class);

    expect($scorer->score($leadA, $icpA)->score)->toBe(50)
        ->and($scorer->score($leadB, $icpB)->score)->toBe(50);
});

it('leaves criteria the lead has no data for out of both sides of the ratio', function (): void {
    // Industry (90) matches; the lead knows nothing about size or technologies, so those weights
    // must not count against it: 90 / 90 = 100, not 90 / 190.
    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Logistics', 90],
        [CriterionType::CompanySize, '11-50', 50],
        [CriterionType::Technographic, 'SAP', 50],
    ]);

    $lead = scorableLead($team, ['industry' => 'Logistics']);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->score)->toBe(100)
        ->and($result->assessedWeight)->toBe(90)
        ->and(rowFor($result->breakdown, CriterionType::CompanySize)->match)->toBe(CriterionMatch::Unknown)
        ->and(rowFor($result->breakdown, CriterionType::Technographic)->match)->toBe(CriterionMatch::Unknown)
        ->and(rowFor($result->breakdown, CriterionType::CompanySize)->evidence)->toContain('No company size');
});

it('always abstains on a buying signal and leaves it to the AI half', function (): void {
    [$team, $icp] = icpWith([[CriterionType::BuyingSignal, 'Recently raised funding', 70]]);
    $lead = scorableLead($team, ['industry' => 'SaaS']);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->hasSignals())->toBeFalse()
        ->and($result->score)->toBe(0)
        ->and(rowFor($result->breakdown, CriterionType::BuyingSignal)->match)->toBe(CriterionMatch::Unknown);
});

it('matches company size on overlapping ranges, not on wording', function (string $wanted, string $actual, bool $expected): void {
    [$team, $icp] = icpWith([[CriterionType::CompanySize, $wanted, 50]]);
    $lead = scorableLead($team, ['employee_range' => $actual]);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->score)->toBe($expected ? 100 : 0);
})->with([
    ['11–50 employees', '25-60', true],
    ['11-50 employees', '200-500', false],
    ['50+', '120-200', true],
    ['under 10', '2-5', true],
    ['under 10', '50-100', false],
]);

it('matches geography through country aliases so UAE finds AE', function (): void {
    [$team, $icp] = icpWith([[CriterionType::Geography, 'UAE', 60]]);
    $lead = scorableLead($team, ['country' => 'AE', 'locality' => 'Dubai']);

    expect(app(DeterministicScorer::class)->score($lead, $icp)->score)->toBe(100);
});

it('reads a comma-separated criterion as "any of these"', function (): void {
    [$team, $icp] = icpWith([[CriterionType::Industry, 'SaaS, Logistics, Hospitality', 60]]);
    $lead = scorableLead($team, ['industry' => 'Third-party logistics']);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->score)->toBe(100)
        ->and(rowFor($result->breakdown, CriterionType::Industry)->evidence)->toBe('Third-party logistics');
});

it('requires a word boundary for very short criterion values', function (): void {
    // "IT" must not match inside "Security", but must match "IT services".
    [$teamA, $icpA] = icpWith([[CriterionType::Industry, 'IT', 50]]);
    [$teamB, $icpB] = icpWith([[CriterionType::Industry, 'IT', 50]]);

    $scorer = app(DeterministicScorer::class);

    expect($scorer->score(scorableLead($teamA, ['industry' => 'Security monitoring']), $icpA)->score)->toBe(0)
        ->and($scorer->score(scorableLead($teamB, ['industry' => 'IT services']), $icpB)->score)->toBe(100);
});

it('matches job titles against the lead’s contacts', function (): void {
    [$team, $icp] = icpWith([[CriterionType::JobTitle, 'Head of Marketing', 70]]);
    $lead = scorableLead($team);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'title' => 'Head of Marketing & Growth',
    ]));

    expect(app(DeterministicScorer::class)->score($lead->fresh() ?? $lead, $icp)->score)->toBe(100);
});

it('disqualifies a lead outright when an exclusion matches', function (): void {
    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Coffee', 90],
        [CriterionType::Exclusion, 'acme.test', null],
    ]);

    $lead = scorableLead($team, ['industry' => 'Coffee', 'website' => 'https://www.acme.test']);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->excluded)->toBeTrue()
        ->and($result->excludedBy)->toBe('acme.test')
        ->and($result->score)->toBe(0)
        ->and($result->breakdown)->toHaveCount(1)
        ->and(rowFor($result->breakdown, CriterionType::Exclusion)->match)->toBe(CriterionMatch::Matched);
});

it('ignores an exclusion that does not match', function (): void {
    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Coffee', 90],
        [CriterionType::Exclusion, 'rival.test', null],
    ]);

    $lead = scorableLead($team, ['industry' => 'Coffee', 'website' => 'https://acme.test']);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    expect($result->excluded)->toBeFalse()
        ->and($result->score)->toBe(100)
        // The unmatched exclusion contributes no breakdown row and no weight.
        ->and($result->breakdown)->toHaveCount(1)
        ->and($result->assessedWeight)->toBe(90);
});

it('weights an unweighted signal with the configured default', function (): void {
    config()->set('scoring.default_signal_weight', 50);

    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Coffee', null],
        [CriterionType::Technographic, 'Shopify', 50],
    ]);

    $lead = scorableLead($team, ['industry' => 'Coffee', 'technologies' => ['Stripe']]);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    // Both carry 50, one matched → 50.
    expect($result->score)->toBe(50)
        ->and(rowFor($result->breakdown, CriterionType::Industry)->weight)->toBe(50);
});

it('spreads contributions across the matched rows so they add up to the score', function (): void {
    [$team, $icp] = icpWith([
        [CriterionType::Industry, 'Coffee', 60],
        [CriterionType::Geography, 'London', 20],
        [CriterionType::Technographic, 'Shopify', 20],
    ]);

    $lead = scorableLead($team, [
        'industry' => 'Coffee',
        'locality' => 'London',
        'technologies' => ['Stripe'],
    ]);

    $result = app(DeterministicScorer::class)->score($lead, $icp);

    $total = array_sum(array_map(static fn (CriterionScore $row): int => $row->contribution, $result->breakdown));

    expect($result->score)->toBe(80)->and($total)->toBe(80);
});

it('scores nothing when the ICP has no criteria at all', function (): void {
    [$team, $icp] = icpWith([]);

    $result = app(DeterministicScorer::class)->score(scorableLead($team), $icp);

    expect($result->hasSignals())->toBeFalse()
        ->and($result->breakdown)->toBe([])
        ->and($result->excluded)->toBeFalse();
});
