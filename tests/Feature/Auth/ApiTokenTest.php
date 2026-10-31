<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Enums\TokenAbility;
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

it('offers the ability catalogue on the token settings screen', function (): void {
    $user = createUserWithTeam();

    // The picker is built from the enum, so a new ability appears on the screen by existing
    // rather than by somebody remembering to add a checkbox (Phase 17).
    $this->actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/ApiTokens')
            ->has('abilityGroups')
            ->where('abilityGroups.0.group', 'leads')
            ->has('abilityGroups.0.abilities'));
});

it('creates a scoped token from the settings form', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Pipeline reader',
            'abilities' => [TokenAbility::LeadsRead->value],
        ])
        ->assertRedirect(route('api-tokens.index'));

    expect($user->tokens()->firstOrFail()->abilities)->toBe([TokenAbility::LeadsRead->value]);
});

it('defaults a token to full access when no scope is chosen', function (): void {
    $user = createUserWithTeam();

    // The form posts `*` when the limit is left off; a token created without the field at all —
    // an older client, or a curl — means the same thing. A token that silently came out able to do
    // nothing would fail on first use with no clue why.
    $this->actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'My iPhone'])
        ->assertRedirect(route('api-tokens.index'));

    expect($user->tokens()->firstOrFail()->abilities)->toBe([TokenAbility::WILDCARD]);
});

it('refuses an ability the API does not enforce', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Invented scope',
            'abilities' => ['leads:read', 'leads:everything'],
        ])
        ->assertSessionHasErrors('abilities.1');

    expect($user->tokens()->count())->toBe(0);
});

it('refuses a token that meant to be limited and named nothing', function (): void {
    $user = createUserWithTeam();

    // An empty `abilities` array is not the same request as no `abilities` at all. The first is a
    // caller that meant to scope the token and named nothing; handing it full access would be the
    // opposite of what it asked for, so it is a 422.
    $this->actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Scoped to nothing', 'abilities' => []])
        ->assertSessionHasErrors('abilities');

    expect($user->tokens()->count())->toBe(0);
});
