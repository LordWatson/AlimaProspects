<?php

declare(strict_types=1);

use App\Modules\AiInsights\Actions\ListInsightsAction;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Services\InsightGenerator;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Scoring\Enums\ScoreGrade;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InsightFixtures;

uses(RefreshDatabase::class);

/**
 * Every tenant model needs a test proving cross-tenant access fails (CLAUDE.md §2). For insights
 * the stakes are concrete: a payload names the companies a team is working and how neglected they
 * are, which is exactly what a competitor would like to read.
 */
beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    app()->instance(AiTransport::class, new FakeAiTransport);
    app()->forgetInstance(AiClient::class);
    config()->set('ai-insights.ai.enabled', false);
});

it('never shows another team’s insights on the feed', function (): void {
    [$user, $team] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    $mine = InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($otherTeam, InsightType::ContactToday);

    $this->actingAs($user)
        ->get(route('insights.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('insights.data', 1)
            ->where('insights.data.0.id', (int) $mine->getKey())
            ->where('counts.new', 1));
});

it('404s rather than 403s on another team’s insight', function (): void {
    [$user] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    $foreign = InsightFixtures::insight($otherTeam, InsightType::StaleLeads);

    // A 404, not a 403: they have no business learning it exists. The global scope hides the row
    // before the policy is ever consulted.
    $this->actingAs($user)
        ->post(route('insights.dismiss', ['insight' => $foreign->getKey()]))
        ->assertNotFound();

    expect($foreign->refresh()->status)->toBe(InsightStatus::New);
});

it('404s on another team’s insight over the API, for reads and writes alike', function (): void {
    [$user] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    $foreign = InsightFixtures::insight($otherTeam, InsightType::StaleLeads);

    Sanctum::actingAs($user, ['*']);

    $this->getJson("/api/v1/insights/{$foreign->getKey()}")->assertNotFound();
    $this->postJson("/api/v1/insights/{$foreign->getKey()}/dismiss")->assertNotFound();
});

it('counts only the acting team’s insights in the tabs', function (): void {
    [, $team] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($otherTeam, InsightType::ContactToday);
    InsightFixtures::insight($otherTeam, InsightType::StaleLeads);

    expect(app(ListInsightsAction::class)->counts($team))->toBe([InsightStatus::New->value => 1]);
});

it('generates from one team’s leads only', function (): void {
    [, $team, $icp] = InsightFixtures::team();
    [, $otherTeam, $otherIcp] = InsightFixtures::team();

    $mine = InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);
    InsightFixtures::uncontactedStrongLead($otherTeam, $otherIcp, ScoreGrade::A, 99);

    app(InsightGenerator::class)->forTeam($team);

    $insight = app(TeamContext::class)->run($team, fn () => Insight::query()
        ->where('team_id', $team->getKey())
        ->firstOrFail());

    expect(array_column($insight->payload['leads'], 'id'))->toBe([(int) $mine->getKey()])
        // And nothing was written for the other team.
        ->and(Insight::query()->withoutGlobalScopes()->count())->toBe(1);
});
