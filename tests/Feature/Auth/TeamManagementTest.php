<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a user create a new team and switch into it', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Acme UAE', 'market' => 'uae'])
        ->assertRedirect(route('team.settings'));

    $team = Team::query()->where('name', 'Acme UAE')->firstOrFail();

    expect($user->fresh()->currentTeamId())->toBe((int) $team->getKey())
        ->and($user->roleOn($team))->toBe(TeamRole::Owner);
});

it('switches the active team for a member', function (): void {
    $user = createUserWithTeam();
    $original = $user->currentTeamId();

    $second = app(CreateTeamAction::class)
        ->handle($user, new CreateTeamData(name: 'Second'));

    $this->actingAs($user)
        ->post(route('teams.switch', $second))
        ->assertRedirect();

    expect($user->fresh()->currentTeamId())->toBe((int) $second->getKey())
        ->not->toBe($original);
});

it('forbids switching to a team the user does not belong to', function (): void {
    $user = createUserWithTeam();
    $foreign = Team::factory()->create();

    $this->actingAs($user)
        ->post(route('teams.switch', $foreign))
        ->assertForbidden();

    expect($user->fresh()->currentTeamId())->not->toBe((int) $foreign->getKey());
});

it('lets an owner update team settings', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->patch(route('team.update'), [
            'name' => 'Renamed',
            'timezone' => 'Asia/Dubai',
            'locale' => 'ar',
            'market' => 'uae',
        ])
        ->assertRedirect();

    $team = $user->currentTeam()->firstOrFail();

    expect($team->name)->toBe('Renamed')
        ->and($team->locale)->toBe('ar')
        ->and($team->market)->toBe('uae');
});

it('denies team settings updates to a read-only member', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::ReadOnly);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    $this->actingAs($member->refresh())
        ->patch(route('team.update'), [
            'name' => 'Hacked',
            'timezone' => 'UTC',
            'locale' => 'en',
            'market' => 'uk',
        ])
        ->assertForbidden();

    expect($team->fresh()->name)->not->toBe('Hacked');
});

it('refuses to demote the last owner', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('team.members.update', $owner), ['role' => TeamRole::Member->value]);

    // Still an owner — the guard blocked the demotion.
    expect($owner->fresh()->roleOn($team))->toBe(TeamRole::Owner);
});

it('changes a member role and removes a member', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();

    $member = User::factory()->create();
    app(TeamMembership::class)->add($member, $team, TeamRole::Member);

    $this->actingAs($owner)
        ->patch(route('team.members.update', $member), ['role' => TeamRole::Admin->value])
        ->assertRedirect();

    expect($member->fresh()->roleOn($team))->toBe(TeamRole::Admin);

    $this->actingAs($owner)
        ->delete(route('team.members.destroy', $member))
        ->assertRedirect();

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});
