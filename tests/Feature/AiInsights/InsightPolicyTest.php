<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\InsightFixtures;
use Tests\Support\LeadsWorkspaceFixtures;

uses(RefreshDatabase::class);

/**
 * The policy on its own, asked directly rather than through a route.
 *
 * The tenancy tests already prove that a cross-tenant request 404s, and they prove it by way of the
 * global scope. This file is the **double-check** ADR 0006 asks for: if somebody later changes how
 * `ResolvesInsights` reads a row — eagerly, by id, from a cache — the policy is the only thing left
 * standing between a member and another team's insight, and it has to be known to hold on its own.
 */
it('lets any member of the team read an insight, read-only included', function (TeamRole $role): void {
    [, $team] = InsightFixtures::team();

    $member = LeadsWorkspaceFixtures::member($team, $role);
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    expect(Gate::forUser($member)->allows('view', $insight))->toBeTrue();
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member, TeamRole::ReadOnly]);

it('lets Owners, Admins and Members clear one away', function (TeamRole $role): void {
    [, $team] = InsightFixtures::team();

    $member = LeadsWorkspaceFixtures::member($team, $role);
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    expect(Gate::forUser($member)->allows('dismiss', $insight))->toBeTrue();
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member]);

it('does not let a read-only member clear one away', function (): void {
    [, $team] = InsightFixtures::team();

    $reader = LeadsWorkspaceFixtures::member($team, TeamRole::ReadOnly);
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    // Dismissing changes what the whole team sees: an insight belongs to the team, and there is no
    // per-member copy of it.
    expect(Gate::forUser($reader)->denies('dismiss', $insight))->toBeTrue();
});

it('refuses a member of another team, handed the insight directly', function (TeamRole $role): void {
    [, $team] = InsightFixtures::team();
    [, $otherTeam] = InsightFixtures::team();

    $outsider = LeadsWorkspaceFixtures::member($otherTeam, $role);
    $insight = InsightFixtures::insight($team, InsightType::StaleLeads);

    expect(Gate::forUser($outsider)->denies('view', $insight))->toBeTrue()
        ->and(Gate::forUser($outsider)->denies('dismiss', $insight))->toBeTrue();
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member, TeamRole::ReadOnly]);

it('refuses somebody in no team at all', function (): void {
    [, $team] = InsightFixtures::team();

    $stranger = User::factory()->create();
    $insight = InsightFixtures::insight($team, InsightType::ContactToday);

    expect(Gate::forUser($stranger)->denies('view', $insight))->toBeTrue()
        ->and(Gate::forUser($stranger)->denies('dismiss', $insight))->toBeTrue();
});
