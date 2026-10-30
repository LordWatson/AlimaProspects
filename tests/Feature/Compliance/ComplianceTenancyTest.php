<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Cross-tenant isolation for every model and screen in the module (ADR 0006).
 *
 * Both of this module's tables are one row per team, which makes a tenancy slip here unusually
 * consequential: one team seeing another's assessment would be reading the other business's legal
 * record, and one team's retention policy applied to another's leads would delete somebody else's
 * data on the wrong schedule.
 */
it('scopes the assessment to the active team', function (): void {
    [, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    Fixtures::activeLia($teamA);
    Fixtures::activeLia($teamB);

    $visible = app(TeamContext::class)->run($teamA, fn (): array => LiaRecord::query()->pluck('team_id')->all());

    expect($visible)->toBe([(int) $teamA->getKey()]);
});

it('scopes the retention policy to the active team', function (): void {
    [, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    Fixtures::retention($teamA);
    Fixtures::retention($teamB);

    $visible = app(TeamContext::class)->run($teamB, fn (): array => RetentionPolicy::query()->pluck('team_id')->all());

    expect($visible)->toBe([(int) $teamB->getKey()]);
});

it('shows each team its own compliance centre', function (): void {
    [$userA, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    Fixtures::activeLia($teamA);
    Fixtures::retention($teamA, uncontactedDays: 45);
    Fixtures::retention($teamB, uncontactedDays: 365);

    $this->actingAs($userA)
        ->get(route('compliance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('retention.uncontacted_lead_days', 45)
            ->where('team_market', 'uk'));
});

it('shows each team its own assessment, even for the same market', function (): void {
    [$userA, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    app(TeamContext::class)->run($teamA, fn () => LiaRecord::factory()->create([
        'team_id' => $teamA->getKey(),
        'market' => Market::Uk,
        'purpose' => 'TEAM-A-ONLY-PURPOSE',
    ]));

    app(TeamContext::class)->run($teamB, fn () => LiaRecord::factory()->create([
        'team_id' => $teamB->getKey(),
        'market' => Market::Uk,
        'purpose' => 'TEAM-B-ONLY-PURPOSE',
    ]));

    $this->actingAs($userA)
        ->get(route('compliance.lia.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('assessment.purpose', 'TEAM-A-ONLY-PURPOSE'));
});

it('writes a saved assessment to the acting team, never another', function (): void {
    [$userA, $teamA] = Fixtures::team();
    [, $teamB] = Fixtures::team();

    $this->actingAs($userA)
        ->put(route('compliance.lia.update'), liaPayload())
        ->assertRedirect();

    expect(LiaRecord::withoutGlobalScopes()->where('team_id', $teamA->getKey())->count())->toBe(1)
        ->and(LiaRecord::withoutGlobalScopes()->where('team_id', $teamB->getKey())->count())->toBe(0);
});

it('requires authentication for every team-facing screen', function (): void {
    foreach ([
        route('compliance.index'),
        route('compliance.lia.edit'),
        route('compliance.dsar.index'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->post(route('compliance.export.store'))->assertRedirect(route('login'));
});
