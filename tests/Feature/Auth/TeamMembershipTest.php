<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('syncs both the pivot role and the spatie team-scoped role when adding a member', function (): void {
    $membership = app(TeamMembership::class);

    $user = User::factory()->create();
    $team = Team::factory()->create();

    $membership->add($user, $team, TeamRole::Admin);

    expect($user->roleOn($team))->toBe(TeamRole::Admin);

    expect(DB::table('model_has_roles')
        ->where('model_id', $user->getKey())
        ->where('team_id', $team->getKey())
        ->exists())->toBeTrue();
});

it('replaces the role on both stores when updated', function (): void {
    $membership = app(TeamMembership::class);
    $registrar = app(PermissionRegistrar::class);

    $user = User::factory()->create();
    $team = Team::factory()->create();

    $membership->add($user, $team, TeamRole::Member);
    $membership->updateRole($user, $team, TeamRole::ReadOnly);

    expect($user->roleOn($team))->toBe(TeamRole::ReadOnly);

    $registrar->setPermissionsTeamId($team->getKey());
    expect($user->fresh()->hasRole(TeamRole::ReadOnly->value))->toBeTrue()
        ->and($user->fresh()->hasRole(TeamRole::Member->value))->toBeFalse();
});

it('removes the member from both the pivot and spatie on removal', function (): void {
    $membership = app(TeamMembership::class);

    $user = User::factory()->create();
    $team = Team::factory()->create();

    $membership->add($user, $team, TeamRole::Member);
    $membership->remove($user, $team);

    expect($user->fresh()->belongsToTeam($team))->toBeFalse();

    expect(DB::table('model_has_roles')
        ->where('model_id', $user->getKey())
        ->where('team_id', $team->getKey())
        ->exists())->toBeFalse();
});

it('scopes spatie roles per team so the same user can hold different roles on different teams', function (): void {
    $membership = app(TeamMembership::class);
    $registrar = app(PermissionRegistrar::class);

    $user = User::factory()->create();
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $membership->add($user, $teamA, TeamRole::Owner);
    $membership->add($user, $teamB, TeamRole::ReadOnly);

    $registrar->setPermissionsTeamId($teamA->getKey());
    expect($user->fresh()->hasRole(TeamRole::Owner->value))->toBeTrue();

    $registrar->setPermissionsTeamId($teamB->getKey());
    expect($user->fresh()->hasRole(TeamRole::ReadOnly->value))->toBeTrue()
        ->and($user->fresh()->hasRole(TeamRole::Owner->value))->toBeFalse();
});
