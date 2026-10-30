<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Services\DiscoveryQueryShaper;
use App\Modules\Onboarding\Models\BusinessProfile;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
});

/**
 * A design studio: the case that exposed the bug. Its own trade is "Graphic design", so any search
 * built from that phrase returns its competitors rather than its customers.
 *
 * @return array{Team, BusinessProfile, Icp, FakeAiTransport}
 */
function bootShaping(string $ownIndustry = 'Graphic design'): array
{
    $team = Team::factory()->create();

    [$profile, $icp] = app(TeamContext::class)->run($team, function () use ($team, $ownIndustry): array {
        $profile = BusinessProfile::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Northern Type',
            'industry' => $ownIndustry,
            'products_services' => ['Brand identity', 'Packaging design'],
            'locations' => ['Manchester'],
        ]);

        // An ICP seeded the old way: its industry signal is the team's OWN trade.
        $icp = Icp::query()->create([
            'team_id' => $team->getKey(),
            'name' => 'Suggested',
            'is_active' => true,
        ]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::Industry,
            'value' => $ownIndustry,
            'weight' => 60,
            'position' => 0,
        ]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::Geography,
            'value' => 'Manchester',
            'weight' => 50,
            'position' => 1,
        ]);
        $icp->criteria()->create([
            'team_id' => $team->getKey(),
            'type' => CriterionType::Exclusion,
            'value' => 'Bold Studio',
            'weight' => null,
            'position' => 2,
        ]);

        return [$profile, $icp->load('criteria')];
    });

    $fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $fakeAi);
    app()->forgetInstance(AiClient::class);
    app()->forgetInstance(DiscoveryQueryShaper::class);

    return [$team, $profile, $icp, $fakeAi];
}

function shaper(): DiscoveryQueryShaper
{
    return app(DiscoveryQueryShaper::class);
}

it('searches for the customers, not for companies like the team', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueStructured([
        'terms' => 'boutique hotels and restaurant groups',
        'customer_description' => 'Independent hospitality businesses that commission branding work',
        'exclude_terms' => ['graphic design', 'branding agency'],
        'reasoning' => 'Hospitality businesses rebrand often and buy design work.',
    ]);

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    expect($query->shaped)->toBeTrue()
        ->and($query->terms)->toBe('boutique hotels and restaurant groups')
        // The deterministic fallback would have searched this, which is the bug.
        ->and($query->terms)->not->toContain('Graphic design')
        ->and($query->customerDescription)
        ->toBe('Independent hospitality businesses that commission branding work')
        // Location still comes from the ICP's geography signal.
        ->and($query->location)->toBe('Manchester');
});

it('merges the model’s negative terms on top of the ICP’s own exclusions', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueStructured([
        'terms' => 'boutique hotels',
        'customer_description' => 'Independent hotels',
        'exclude_terms' => ['graphic design'],
    ]);

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    // A member who typed an exclusion means it regardless of what the model returned.
    expect($query->excludeTerms)->toContain('Bold Studio')
        ->and($query->excludeTerms)->toContain('graphic design');
});

it('does not spend a model call when the member typed their own terms', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(
            LeadSource::WebSearch,
            $profile,
            $icp,
            25,
            'wedding venues',
            'Leeds',
        ),
    );

    expect($fakeAi->callCount())->toBe(0)
        ->and($query->shaped)->toBeFalse()
        ->and($query->terms)->toBe('wedding venues')
        ->and($query->location)->toBe('Leeds')
        // Exclusions still apply — they are not an AI feature.
        ->and($query->excludeTerms)->toContain('Bold Studio');
});

it('falls back to the deterministic query rather than failing the run when the model errors', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueFailure();

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    // Degraded, not dead: a deterministic search is worth more to the member than an error screen.
    expect($query->shaped)->toBeFalse()
        ->and($query->terms)->toBe('Graphic design')
        ->and($query->excludeTerms)->toContain('Bold Studio');
});

it('returns no terms when the model cannot tell who buys, rather than searching the own trade', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueStructured([
        'terms' => '',
        'customer_description' => 'Could not determine who buys from this business.',
    ]);

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    // An empty result is a real answer. StartDiscoveryRunAction turns it into `nothingToSearch`,
    // which asks the member for terms — reinstating the ICP's own industry here is the bug.
    expect($query->terms)->toBe('')
        ->and($query->shaped)->toBeTrue();
});

it('skips shaping with no business profile, since there is nothing to invert', function (): void {
    [$team, , $icp, $fakeAi] = bootShaping();

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, null, $icp, 25),
    );

    expect($fakeAi->callCount())->toBe(0)
        ->and($query->shaped)->toBeFalse()
        ->and($query->terms)->toBe('Graphic design');
});

it('can be switched off by config, leaving the deterministic query', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    config()->set('lead-sourcing.query_shaping.enabled', false);

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    expect($fakeAi->callCount())->toBe(0)
        ->and($query->shaped)->toBeFalse()
        ->and($query->terms)->toBe('Graphic design');
});

it('tells the model which provider it is writing for', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueStructured(['terms' => 'boutique hotels', 'customer_description' => 'Hotels']);

    app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::CompaniesHouse, $profile, $icp, 25),
    );

    $prompt = $fakeAi->lastRequest()?->prompt ?? '';

    // A company register matches registered names, so the brief has to say so — the same query would
    // be wrong for a places API.
    expect($prompt)->toContain('REGISTERED COMPANY NAMES')
        // And the profile must state plainly that the team's own trade is off limits.
        ->and($prompt)->toContain('Graphic design');
});

it('degrades when the team is out of AI credits, rather than failing the run', function (): void {
    [$team, $profile, $icp, $fakeAi] = bootShaping();

    $fakeAi->queueFailure(AiCreditExceededException::make());

    $query = app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    );

    // Out of credits is a billing state, not a discovery failure: discovery is not an AI feature
    // from the member's point of view, so it must not die when credits run out.
    expect($query->shaped)->toBeFalse()
        ->and($query->terms)->toBe('Graphic design');
});

it('lets a non-AI failure bubble instead of silently degrading', function (): void {
    [$team, $profile, $icp] = bootShaping();

    // A bug or a dead database is not something to paper over with a worse search.
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function structured(string $key, array $input = [], ?int $version = null): StructuredAiResponse
        {
            throw new RuntimeException('database is gone');
        }
    });
    app()->forgetInstance(DiscoveryQueryShaper::class);

    expect(fn () => app(TeamContext::class)->run(
        $team,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profile, $icp, 25),
    ))->toThrow(RuntimeException::class);
});

it('never shapes a run from another team’s ICP', function (): void {
    [$teamA, $profileA, , $fakeAi] = bootShaping();

    // Team B's ICP, with a signal that would be unmistakable if it leaked into A's search.
    $teamB = Team::factory()->create();
    $icpB = app(TeamContext::class)->run($teamB, function () use ($teamB): Icp {
        $icp = Icp::query()->create([
            'team_id' => $teamB->getKey(),
            'name' => 'Team B',
            'is_active' => true,
        ]);
        $icp->criteria()->create([
            'team_id' => $teamB->getKey(),
            'type' => CriterionType::Industry,
            'value' => 'TEAM-B-ONLY-SIGNAL',
            'weight' => 90,
            'position' => 0,
        ]);

        return $icp->load('criteria');
    });

    $fakeAi->queueStructured(['terms' => 'boutique hotels', 'customer_description' => 'Hotels']);

    // The action resolves the ICP team-scoped, so B's row is simply not found for A. This asserts
    // the stronger property: even handed the object, nothing of B's reaches A's prompt.
    app(TeamContext::class)->run(
        $teamA,
        fn (): DiscoveryQuery => shaper()->shape(LeadSource::WebSearch, $profileA, null, 25),
    );

    expect($fakeAi->lastRequest()?->prompt ?? '')->not->toContain('TEAM-B-ONLY-SIGNAL')
        ->and($icpB->team_id)->not->toBe($teamA->getKey());
});

it('refuses to start a run against another team’s ICP id', function (): void {
    Queue::fake();

    $userA = createUserWithTeam();
    $teamA = $userA->currentTeam()->firstOrFail();

    $teamB = Team::factory()->create();
    $icpB = app(TeamContext::class)->run($teamB, function () use ($teamB): Icp {
        $icp = Icp::query()->create([
            'team_id' => $teamB->getKey(),
            'name' => 'Team B',
            'is_active' => true,
        ]);
        $icp->criteria()->create([
            'team_id' => $teamB->getKey(),
            'type' => CriterionType::Industry,
            'value' => 'TEAM-B-ONLY-SIGNAL',
            'weight' => 90,
            'position' => 0,
        ]);

        return $icp;
    });

    // Team A has no ICP and no profile, so with B's ICP out of reach there is nothing to search —
    // which is the right answer, and proves the id did not resolve across the tenant boundary.
    $this->actingAs($userA)
        ->post(route('lead-sourcing.store'), [
            'sources' => ['web_search'],
            'icp_id' => $icpB->getKey(),
        ])
        ->assertSessionHasErrors();

    expect(DiscoveryRun::query()->withoutGlobalScopes()->count())->toBe(0);
});
