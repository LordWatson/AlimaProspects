<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InsightFixtures;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

// --- Auth --------------------------------------------------------------------------------------

it('sends a guest to log in rather than showing the feed', function (): void {
    [, $team] = InsightFixtures::team();

    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    $this->get(route('insights.index'))->assertRedirect(route('login'));
    $this->post(route('insights.dismiss', ['insight' => $insight->getKey()]))
        ->assertRedirect(route('login'));

    expect($insight->refresh()->status)->toBe(InsightStatus::New);
});

// --- The screen ---------------------------------------------------------------------------------

it('renders the feed with the open insights, tab counts and the next run', function (): void {
    [$user, $team] = InsightFixtures::team();

    $team->forceFill(['timezone' => 'Asia/Dubai'])->save();
    config()->set('ai-insights.generate_at_hour', 7);

    InsightFixtures::insight($team, InsightType::ContactToday);
    InsightFixtures::insight($team, InsightType::StaleLeads);
    InsightFixtures::insight($team, InsightType::PeriodicDigest, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('insights.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('insights/Index')
            ->where('filters.status', InsightStatus::New->value)
            ->where('counts.new', 2)
            ->where('counts.dismissed', 1)
            ->has('insights.data', 2)
            ->where('schedule.hour', 7)
            // The team's clock, so an empty feed can say when the next run is in words the member
            // recognises.
            ->where('schedule.timezone', 'Asia/Dubai')
            ->where('can.dismiss', true));
});

it('shows what to do first: the action insights above the merely interesting', function (): void {
    [$user, $team] = InsightFixtures::team();

    // Written in the opposite order to the one they should come back in.
    $info = InsightFixtures::insight($team, InsightType::PeriodicDigest);
    $action = InsightFixtures::insight($team, InsightType::ContactToday);

    $this->actingAs($user)
        ->get(route('insights.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('insights.data.0.id', (int) $action->getKey())
            ->where('insights.data.1.id', (int) $info->getKey()));
});

it('sends no prose for a deterministic insight and the model’s own words for the digest', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);

    $this->actingAs($user)
        ->get(route('insights.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Worded on the client from type + payload, so the card reads in Arabic too.
            ->where('insights.data.0.headline', null)
            ->where('insights.data.0.body', null)
            ->where('insights.data.0.is_written_by_ai', false)
            ->has('insights.data.0.payload.leads')
            // And the retention promise, from the same rule the sweep applies.
            ->where('insights.data.0.deletes_in_days', 60));

    app(TeamContext::class)->run($team, fn () => Insight::query()->delete());

    app(TeamContext::class)->run($team, fn (): Insight => Insight::factory()
        ->fromAi('Your reply rate doubled on LinkedIn')
        ->create(['team_id' => $team->getKey()]));

    $this->actingAs($user)
        ->get(route('insights.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('insights.data.0.headline', 'Your reply rate doubled on LinkedIn')
            ->where('insights.data.0.is_written_by_ai', true));
});

it('shows the dismissed tab when asked for it', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);
    $dismissed = InsightFixtures::insight($team, InsightType::StaleLeads, [
        'status' => InsightStatus::Dismissed,
        'dismissed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('insights.index', ['status' => InsightStatus::Dismissed->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('insights.data', 1)
            ->where('insights.data.0.id', (int) $dismissed->getKey()));
});

it('opens the default tab for a stale link rather than refusing it', function (): void {
    [$user, $team] = InsightFixtures::team();

    InsightFixtures::insight($team, InsightType::ContactToday);

    $this->actingAs($user)
        ->get(route('insights.index', ['status' => 'applied']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.status', InsightStatus::New->value));
});

// --- Dismissing ---------------------------------------------------------------------------------

it('dismisses an insight, records who did it, and audits it', function (): void {
    [$user, $team] = InsightFixtures::team();

    $insight = InsightFixtures::insight($team, InsightType::StaleLeads);

    $this->actingAs($user)
        ->post(route('insights.dismiss', ['insight' => $insight->getKey()]))
        ->assertRedirect();

    $insight->refresh();

    expect($insight->status)->toBe(InsightStatus::Dismissed)
        ->and($insight->dismissed_by)->toBe((int) $user->getKey())
        ->and($insight->dismissed_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'insight.dismissed')->firstOrFail();

    // Ids and the type: the trail says an insight was cleared, not what it said.
    expect($audit->properties['type'])->toBe(InsightType::StaleLeads->value)
        ->and($audit->subject_id)->toBe((int) $insight->getKey());
});

it('keeps the first member and timestamp when the same insight is dismissed twice', function (): void {
    [$user, $team] = InsightFixtures::team();

    $other = LeadsWorkspaceFixtures::member($team, TeamRole::Member);
    $insight = InsightFixtures::insight($team, InsightType::StaleLeads);

    $this->actingAs($user)
        ->post(route('insights.dismiss', ['insight' => $insight->getKey()]))
        ->assertRedirect();

    $firstAt = $insight->refresh()->dismissed_at;

    $this->actingAs($other)
        ->post(route('insights.dismiss', ['insight' => $insight->getKey()]))
        ->assertRedirect();

    // "Who cleared this, and when" has one answer.
    expect($insight->refresh()->dismissed_by)->toBe((int) $user->getKey())
        ->and($insight->dismissed_at->toIso8601String())->toBe($firstAt->toIso8601String())
        ->and(AuditLog::query()->where('action', 'insight.dismissed')->count())->toBe(1);
});

it('lets a read-only member read the feed but not clear it', function (): void {
    [, $team] = InsightFixtures::team();

    $reader = LeadsWorkspaceFixtures::member($team, TeamRole::ReadOnly);
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    $this->actingAs($reader)
        ->get(route('insights.index'))
        ->assertOk()
        // Dismissing changes what the whole team sees, so it is not a reader's to do.
        ->assertInertia(fn ($page) => $page->where('can.dismiss', false));

    $this->actingAs($reader)
        ->post(route('insights.dismiss', ['insight' => $insight->getKey()]))
        ->assertForbidden();

    expect($insight->refresh()->status)->toBe(InsightStatus::New);
});
