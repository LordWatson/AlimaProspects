<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a token for valid credentials and authenticates a protected /api/v1 route', function (): void {
    createUserWithTeam(['email' => 'api@example.com']);

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'api@example.com',
        'password' => 'password',
        'device_name' => 'Jane iPhone',
    ]);

    $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

    $token = (string) $response->json('token');

    $this->withToken($token)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', 'api@example.com');
});

it('rejects invalid credentials', function (): void {
    createUserWithTeam(['email' => 'api@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => 'api@example.com',
        'password' => 'wrong-password',
        'device_name' => 'phone',
    ])->assertStatus(422);
});

it('blocks API token issuance for a two-factor account', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertStatus(423);
});

it('rejects an unauthenticated request to a protected route', function (): void {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

it('lists and revokes the user\'s tokens', function (): void {
    $user = createUserWithTeam(['email' => 'api@example.com']);
    $plain = $user->createToken('CLI', ['*'])->plainTextToken;

    $this->withToken($plain)
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $tokenId = $user->tokens()->firstOrFail()->getKey();

    $this->withToken($plain)
        ->deleteJson("/api/v1/auth/tokens/{$tokenId}")
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('switches teams over the API', function (): void {
    $user = createUserWithTeam();
    $second = app(CreateTeamAction::class)
        ->handle($user, new CreateTeamData(name: 'API Team'));

    $plain = $user->createToken('CLI', ['*'])->plainTextToken;

    $this->withToken($plain)
        ->postJson("/api/v1/teams/{$second->id}/switch")
        ->assertOk()
        ->assertJsonPath('data.id', $second->id);

    expect($user->fresh()->currentTeamId())->toBe((int) $second->getKey());
});
