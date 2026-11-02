<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\CreateTeamAction;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

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

it('holds token issuance for a two-factor account until the second factor arrives', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    // A 423 and not a 422: nothing sent was invalid, and re-sending it unchanged will not help.
    // The code is what tells the client to open its challenge screen, and `methods` what to
    // offer on it.
    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
    ])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'auth.two_factor_required')
        ->assertJsonPath('error.details.methods', ['code', 'recovery_code']);
});

it('issues a token for a two-factor account when the authenticator code is right', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '123456',
    ])->assertCreated();

    $this->withToken((string) $response->json('token'))
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->getKey());
});

it('refuses a wrong authenticator code as a field error, not a locked account', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    // 422 on `code` rather than another 423: the client already asked, and what came back was
    // wrong — which belongs under the input the person typed it into.
    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '000000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['code']]]);
});

it('accepts a recovery code once and replaces it', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'recovery_code' => 'recovery-code-1',
    ])->assertCreated();

    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');

    // Spent. A recovery code that still worked a second time would be a password written down
    // on a piece of paper that never expires.
    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'recovery_code' => 'recovery-code-1',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['recovery_code']]]);
});

it('treats an empty code as none sent, so the client gets the challenge back', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '',
        'recovery_code' => '',
    ])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'auth.two_factor_required');
});

it('checks the password before it admits the account has two-factor at all', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    // A wrong password answers exactly as it does for an account without 2FA, so the 423 is
    // never a way to probe which accounts have it switched on.
    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'wrong-password',
        'device_name' => 'phone',
        'code' => '123456',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
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

/*
 * The edges of the two-factor exchange, from the Phase 18 review. Each of these is a behaviour the
 * flow *has* and nothing pinned — which is the same thing as a behaviour that can change by
 * accident.
 */

it('ignores a stray code on an account that has no second factor', function (): void {
    createUserWithTeam(['email' => 'plain@example.com']);

    // A client that posts a leftover code must not be refused for it: the account needs no second
    // factor, so there is nothing for the code to be wrong about.
    $this->postJson('/api/v1/auth/token', [
        'email' => 'plain@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '000000',
    ])->assertCreated();
});

it('does not challenge an account whose two-factor setup was never confirmed', function (): void {
    // A secret exists but was never confirmed — the member started the setup and walked away.
    // Challenging them would lock them out of the app over a flow they never finished.
    User::factory()->create([
        'email' => 'half@example.com',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => null,
    ]);

    $this->postJson('/api/v1/auth/token', [
        'email' => 'half@example.com',
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertCreated();
});

it('prefers the code when both are sent, and does not spend the recovery code', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '000000',
        'recovery_code' => 'recovery-code-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['code']]]);

    // The expensive credential survives the cheap one being wrong. They are not interchangeable:
    // a code is reusable within its window, a recovery code is gone for ever.
    expect($user->fresh()->recoveryCodes())->toContain('recovery-code-1');
});

it('mints nothing on a challenge or a wrong code', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertStatus(423);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => '000000',
    ])->assertStatus(422);

    expect($user->tokens()->count())->toBe(0);
});

it('carries the requested scopes and device name through the second post', function (): void {
    $user = User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'Nadia iPhone',
        'abilities' => [TokenAbility::LeadsRead->value],
        'code' => '123456',
    ])->assertCreated();

    $token = $user->tokens()->sole();

    expect($token->name)->toBe('Nadia iPhone');
    expect($token->abilities)->toBe([TokenAbility::LeadsRead->value]);
});

it('locks the account out after repeated wrong codes, whatever the IP', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    // The route limiter is keyed on email + IP, which is the wrong shape for a six-digit code: an
    // attacker holding the password rotates IPs and buys five more guesses each time. So failures
    // are counted against the **account**, and a fresh IP every attempt makes no difference.
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->postJson('/api/v1/auth/token', [
                'email' => '2fa@example.com',
                'password' => 'password',
                'device_name' => 'phone',
                'code' => '000000',
            ])->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->postJson('/api/v1/auth/token', [
            'email' => '2fa@example.com',
            'password' => 'password',
            'device_name' => 'phone',
            'code' => '000000',
        ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertHeader('Retry-After');

    // And the lockout holds even for the *right* code, which is the point: a correct guess on the
    // eleventh try is still a guess.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])
        ->postJson('/api/v1/auth/token', [
            'email' => '2fa@example.com',
            'password' => 'password',
            'device_name' => 'phone',
            'code' => '123456',
        ])->assertStatus(429);
});

it('does not count the challenge itself as a failed attempt', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    fakeTwoFactorCode('123456');

    // Twenty rounds of "the client did not know this account had 2FA on" must not lock anyone out
    // — nothing was guessed.
    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
            ->postJson('/api/v1/auth/token', [
                'email' => '2fa@example.com',
                'password' => 'password',
                'device_name' => 'phone',
            ])->assertStatus(423);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
        ->postJson('/api/v1/auth/token', [
            'email' => '2fa@example.com',
            'password' => 'password',
            'device_name' => 'phone',
            'code' => '123456',
        ])->assertCreated();
});

it('refuses a code or recovery code longer than the rule allows', function (): void {
    User::factory()->withTwoFactor()->create(['email' => '2fa@example.com']);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => str_repeat('1', 65),
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['code']]]);

    $this->postJson('/api/v1/auth/token', [
        'email' => '2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'recovery_code' => str_repeat('a', 129),
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['recovery_code']]]);
});

it('accepts a real TOTP code and refuses the same one twice', function (): void {
    // The other tests fake the provider, which is right for asserting that our flow consults it —
    // but it also bypasses Fortify's replay cache. This one uses the real provider end to end, so
    // "the same provider, so the same window and the same replay protection" is a tested claim
    // rather than a comment.
    $google = app(Google2FA::class);
    $secret = $google->generateSecretKey();

    $user = User::factory()->create([
        'email' => 'real2fa@example.com',
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $code = $google->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/token', [
        'email' => 'real2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => $code,
    ])->assertCreated();

    $this->postJson('/api/v1/auth/token', [
        'email' => 'real2fa@example.com',
        'password' => 'password',
        'device_name' => 'phone',
        'code' => $code,
    ])->assertStatus(422);

    expect($user->tokens()->count())->toBe(1);
});
