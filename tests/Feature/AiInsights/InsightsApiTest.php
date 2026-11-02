<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InsightFixtures;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/insights')->assertUnauthorized();
});

it('returns the open insights as resources', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now(),
    ]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/insights')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', InsightType::ContactToday->value)
        ->assertJsonPath('data.0.status', InsightStatus::New->value)
        ->assertJsonStructure([
            'data' => [['id', 'type', 'type_label', 'severity', 'payload', 'generated_at', 'deletes_in_days']],
            'links',
            'meta',
        ])
        // Our cost and provenance trace is not a client's business.
        ->assertJsonMissingPath('data.0.prompt_key')
        ->assertJsonMissingPath('data.0.cost_micros');
});

it('filters to the dismissed tab', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now(),
    ]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/insights?status=dismissed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', InsightType::StaleLeads->value);
});

it('rejects a page size beyond the ceiling', function (): void {
    [$user] = InsightFixtures::team();

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/insights?per_page=500')
        ->assertUnprocessable()
        ->assertApiValidationErrors('per_page');
});

it('opens the default tab for an unknown status rather than refusing it', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);

    Sanctum::actingAs($user, ['*']);

    // Permissive on purpose: a client built against a status we later removed should still get a
    // feed. The bound-checking rules are there to keep rubbish out of a query, not to 422 a link.
    $this->getJson('/api/v1/insights?status=applied')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', InsightStatus::New->value);
});

it('shows and dismisses one insight, returning its new state', function (): void {
    [$user, $team] = InsightFixtures::team();

    $insight = InsightFixtures::insight($team, InsightType::StaleLeads);

    Sanctum::actingAs($user, ['*']);

    $this->getJson("/api/v1/insights/{$insight->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', (int) $insight->getKey());

    $this->postJson("/api/v1/insights/{$insight->getKey()}/dismiss")
        ->assertOk()
        ->assertJsonPath('data.status', InsightStatus::Dismissed->value);

    expect($insight->refresh()->status)->toBe(InsightStatus::Dismissed);
});

it('refuses to let a read-only member dismiss over the API', function (): void {
    [, $team] = InsightFixtures::team();

    $reader = LeadsWorkspaceFixtures::member($team, TeamRole::ReadOnly);
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    Sanctum::actingAs($reader, ['*']);

    $this->postJson("/api/v1/insights/{$insight->getKey()}/dismiss")->assertForbidden();
});
