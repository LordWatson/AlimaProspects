<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Services\InsightRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InsightFixtures;

uses(RefreshDatabase::class);

function remainingInsightIds(): array
{
    return Insight::query()
        ->withoutGlobalScopes()
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

it('deletes dismissed and unactioned insights past their windows, and keeps the rest', function (): void {
    [, $team] = InsightFixtures::team();

    config()->set('ai-insights.retention.dismissed_days', 30);
    config()->set('ai-insights.retention.unactioned_days', 60);

    $oldDismissed = InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now()->subDays(40),
        'generated_at' => now()->subDays(40),
    ]);
    $recentDismissed = InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now()->subDays(5),
        'generated_at' => now()->subDays(5),
    ]);
    $oldOpen = InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(90),
    ]);
    $recentOpen = InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(2),
    ]);

    $this->artisan('insights:prune')
        ->expectsOutputToContain('Deleted 1 dismissed and 1 unactioned')
        ->assertSuccessful();

    expect(remainingInsightIds())
        ->toBe([(int) $recentDismissed->getKey(), (int) $recentOpen->getKey()]);

    expect([$oldDismissed->getKey(), $oldOpen->getKey()])->toHaveCount(2);
});

it('is idempotent', function (): void {
    [, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(90),
    ]);

    $this->artisan('insights:prune')->assertSuccessful();
    $this->artisan('insights:prune')
        ->expectsOutputToContain('Deleted 0 dismissed and 0 unactioned')
        ->assertSuccessful();

    expect(remainingInsightIds())->toBe([]);
});

it('deletes nothing on a dry run', function (): void {
    [, $team] = InsightFixtures::team();

    $insight = InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(90),
    ]);

    $this->artisan('insights:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 0 dismissed and 1 unactioned')
        ->assertSuccessful();

    expect(remainingInsightIds())->toBe([(int) $insight->getKey()]);
});

it('tells the member the same window the sweep will apply', function (): void {
    [, $team] = InsightFixtures::team();

    config()->set('ai-insights.retention.unactioned_days', 60);

    $insight = InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(50),
    ]);

    // The screen's countdown and the sweep's rule are the same rule, not two copies of it.
    expect(app(InsightRetention::class)->deletesInDays($insight))->toBe(10);
});

it('starts a dismissed insight’s window at the dismissal, not the generation', function (): void {
    [, $team] = InsightFixtures::team();

    config()->set('ai-insights.retention.dismissed_days', 30);

    // Generated six weeks ago and cleared away yesterday. Measured from `generated_at` it would be
    // past a 30-day window the moment it was dismissed — gone at tonight's sweep, and reading
    // "clears in 0 days" in the tab that exists so somebody can go back and read it.
    $insight = InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'generated_at' => now()->subDays(42),
        'dismissed_at' => now()->subDay(),
    ]);

    $this->artisan('insights:prune')
        ->expectsOutputToContain('Deleted 0 dismissed')
        ->assertSuccessful();

    expect(remainingInsightIds())->toBe([(int) $insight->getKey()])
        ->and(app(InsightRetention::class)->deletesInDays($insight))->toBe(29);
});

it('never counts down past zero', function (): void {
    [, $team] = InsightFixtures::team();

    $insight = InsightFixtures::insight($team, InsightType::ContactToday, [
        'generated_at' => now()->subDays(200),
    ]);

    // The sweep runs nightly, so a row can sit past its window for a few hours: "clears in -140
    // days" tells a member nothing.
    expect(app(InsightRetention::class)->deletesInDays($insight))->toBe(0);
});

it('sweeps across every team, because it is a system job', function (): void {
    [, $team] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday, ['generated_at' => now()->subDays(90)]);
    InsightFixtures::insight($otherTeam, InsightType::ContactToday, ['generated_at' => now()->subDays(90)]);

    $this->artisan('insights:prune')
        ->expectsOutputToContain('Deleted 0 dismissed and 2 unactioned')
        ->assertSuccessful();

    expect(remainingInsightIds())->toBe([]);
});
