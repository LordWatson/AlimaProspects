<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! Features::enabled(Features::registration())) {
        $this->markTestSkipped('Registration is disabled.');
    }
});

it('creates a personal team with the Owner role when a user registers', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

    expect($user->current_team_id)->not->toBeNull();
    expect($user->teams()->count())->toBe(1);

    $team = $user->teams()->firstOrFail();

    expect($team->name)->toBe("Jane's Team")
        ->and($user->roleOn($team))->toBe(TeamRole::Owner)
        ->and($user->currentTeamId())->toBe((int) $team->getKey());

    // The spatie team-scoped Owner role is assigned too.
    expect(DB::table('model_has_roles')
        ->where('model_id', $user->getKey())
        ->where('team_id', $team->getKey())
        ->exists())->toBeTrue();
});

it('writes an audit entry for the team created at registration', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Sam Lee',
        'email' => 'sam@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'sam@example.com')->firstOrFail();

    expect(DB::table('audit_logs')
        ->where('action', 'team.created')
        ->where('user_id', $user->getKey())
        ->exists())->toBeTrue();
});
