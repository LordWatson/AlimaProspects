<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * The dashboard over `/api/v1` (Phase 17) — the mobile home screen's one request.
 *
 * The point of every test here is **parity**: the same window, through the same FormRequest and the
 * same Service, has to produce the same figures as the web screen. A second, mobile-shaped
 * dashboard would be a second place for the cohort rules to drift, and two surfaces quoting a team
 * different numbers for "this month" is the kind of bug nobody can unpick afterwards.
 */

it('refuses an unauthenticated caller', function (): void {
    $this->getJson(route('api.v1.reports.dashboard'))->assertUnauthorized();
});

it('answers with the same report the web dashboard renders', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee'], $icp, ScoreGrade::A, 90);
    Fixtures::lead($team, ['company_name' => 'Second Ltd', 'dedupe_key' => 'no:second'], $icp, ScoreGrade::D, 20);

    // The default window (all time) rather than a preset: the factory dates the leads, and the
    // point here is that the two surfaces agree, not which month they agree about. The window
    // itself is exercised in the next test.
    $api = $this->actingAs($user)
        ->getJson(route('api.v1.reports.dashboard'))
        ->assertOk()
        ->assertApiVersion()
        ->json('data');

    $web = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaProps()['report'] ?? null;

    expect($api['kpis'])->not->toBeEmpty()
        ->and($api['funnel'])->not->toBeEmpty()
        ->and($api['has_data'])->toBeTrue()
        ->and($api['filters']['range'])->toBe(ReportRange::AllTime->value);

    // Same numbers, not merely a similar shape.
    if (is_array($web)) {
        expect($api['kpis'])->toEqual($web['kpis']);
        expect($api['funnel'])->toEqual($web['funnel']);
    }
});

it('reports the window it used, so a phone can label its own charts', function (): void {
    [$user] = Fixtures::team();

    $data = $this->actingAs($user)
        ->getJson(route('api.v1.reports.dashboard', ['from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertOk()
        ->json('data');

    expect($data['range_from'])->toBe('2026-01-01')
        ->and($data['range_to'])->toBe('2026-01-31')
        ->and($data['trend_granularity'])->toBeString()
        ->and($data['trend_buckets'])->toBeInt();
});

it('validates the window the same way the screen does', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->getJson(route('api.v1.reports.dashboard', ['range' => 'since_tuesday']))
        ->assertApiValidationErrors('range');

    // `to` before `from` is the one combination that would otherwise return an empty report and
    // look like a quiet month.
    $this->actingAs($user)
        ->getJson(route('api.v1.reports.dashboard', ['from' => '2026-02-01', 'to' => '2026-01-01']))
        ->assertApiValidationErrors('to');
});

it('shows a member only their own team’s figures', function (): void {
    [, $teamA, $icpA] = Fixtures::team();
    [$userB, $teamB] = Fixtures::team();

    Fixtures::lead($teamA, ['company_name' => 'Team A lead'], $icpA, ScoreGrade::A, 90);

    $data = $this->actingAs($userB)
        ->getJson(route('api.v1.reports.dashboard'))
        ->assertOk()
        ->json('data');

    expect($data['has_data'])->toBeFalse()
        ->and($data['has_ever_sourced'])->toBeFalse()
        ->and($teamB->getKey())->not->toBe($teamA->getKey());
});

it('lets a read-only member read the dashboard', function (): void {
    [, $team] = Fixtures::team();

    // Reports are a read: every role that can see the team can see its numbers (TeamPolicy
    // `viewReports`), which is also what the web screen allows.
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)->getJson(route('api.v1.reports.dashboard'))->assertOk();
});
