<?php

declare(strict_types=1);

use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The seller/buyer split in a seeded ICP.
 *
 * The bug these cover: the background check returned one `suggested_industries` list, which was
 * written to the team's own profile industry *and* seeded as the ICP's industry signal. A graphic
 * design studio therefore got an ICP that said "look for graphic design" — and discovery dutifully
 * returned its competitors. Each test here pins one side of the split so the two can never collapse
 * back into each other.
 */
function designStudioReport(Team $team, array $findings = []): BusinessIntelReport
{
    return BusinessIntelReport::factory()->create([
        'team_id' => $team->getKey(),
        'status' => BackgroundCheckStatus::Ready,
        'findings' => array_merge([
            'summary' => 'An independent graphic design studio in Manchester.',
            'offering' => 'Brand identity, packaging and print design.',
            'positioning' => 'Craft-led, for businesses that care about their brand.',
            'own_industry' => 'Graphic design',
            'customer_industries' => ['Hospitality', 'Property development'],
            'target_customers' => ['Independent restaurant groups'],
            'decision_maker_titles' => ['Marketing Director'],
            'competitors' => ['Bold Studio'],
            'confidence' => 0.8,
        ], $findings),
    ]);
}

/**
 * @return array{Team, BusinessIntelReport}
 */
function acceptWithIcp(array $findings = []): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'industry' => null,
    ]));

    $report = designStudioReport($team, $findings);

    test()->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), [
            'apply_to_profile' => true,
            'seed_icp' => true,
        ])
        ->assertRedirect();

    return [$team, $report];
}

/**
 * @return array<string, list<string>>
 */
function criteriaByType(Team $team): array
{
    return app(TeamContext::class)->run($team, function () use ($team): array {
        $icp = Icp::query()->where('team_id', $team->getKey())->with('criteria')->first();

        if (! $icp instanceof Icp) {
            return [];
        }

        $grouped = [];
        foreach ($icp->criteria as $criterion) {
            /** @var IcpCriterion $criterion */
            $grouped[$criterion->type->value][] = $criterion->value;
        }

        return $grouped;
    });
}

it('seeds the ICP from the customer industries, never the team’s own trade', function (): void {
    [$team] = acceptWithIcp();

    $byType = criteriaByType($team);

    expect($byType[CriterionType::Industry->value])
        ->toBe(['Hospitality', 'Property development'])
        // The whole point: "Graphic design" must not be something we go looking for.
        ->not->toContain('Graphic design');
});

it('excludes the team’s own trade and its competitors so discovery skips them', function (): void {
    [$team] = acceptWithIcp();

    $byType = criteriaByType($team);

    expect($byType[CriterionType::Exclusion->value])
        ->toContain('Graphic design')
        ->toContain('Bold Studio');
});

it('puts decision-maker titles in the job-title signal, not the industry one', function (): void {
    [$team] = acceptWithIcp();

    $byType = criteriaByType($team);

    expect($byType[CriterionType::JobTitle->value])->toBe(['Marketing Director'])
        ->and($byType[CriterionType::Industry->value])->not->toContain('Marketing Director');
});

it('writes the team’s own industry to their profile, not a customer industry', function (): void {
    [$team] = acceptWithIcp();

    app(TeamContext::class)->run($team, function () use ($team): void {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();

        expect($profile->industry)->toBe('Graphic design');
    });
});

it('seeds no ICP at all when the check could not tell who buys', function (): void {
    // A v1-era report: one list of industries meaning the team's own trade, and nothing about buyers.
    [$team] = acceptWithIcp([
        'own_industry' => null,
        'customer_industries' => [],
        'target_customers' => [],
        'decision_maker_titles' => [],
        'suggested_industries' => ['Graphic design'],
    ]);

    // An ICP built from the seller's own trade is worse than none, because discovery and scoring
    // both act on it without asking again.
    expect(criteriaByType($team))->toBe([]);
});

it('reads a legacy v1 report’s industries as the team’s own, not as customers', function (): void {
    [$team] = acceptWithIcp([
        'own_industry' => null,
        'customer_industries' => [],
        'target_customers' => [],
        'decision_maker_titles' => [],
        'suggested_industries' => ['Graphic design', 'Branding'],
    ]);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $profile = BusinessProfile::query()->where('team_id', $team->getKey())->firstOrFail();

        expect($profile->industry)->toBe('Graphic design');
    });
});

it('falls back to the target customers when no customer industry came back', function (): void {
    [$team] = acceptWithIcp([
        'customer_industries' => [],
        'decision_maker_titles' => [],
    ]);

    $byType = criteriaByType($team);

    // "Independent restaurant groups" is a kind of company, so it belongs in the industry signal
    // rather than the job-title one.
    expect($byType[CriterionType::Industry->value])->toBe(['Independent restaurant groups'])
        ->and($byType)->not->toHaveKey(CriterionType::JobTitle->value);
});
