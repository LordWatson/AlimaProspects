<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The `/api/v1` limiter (Phase 20, task 2 — the rate-limit half of the hardening pass).
 *
 * Login, the two-factor challenge and the credential→token exchange are already covered where they
 * live (`AuthenticationTest`, `ApiTokenTest`). What was not covered is the shape of the API
 * limiter itself, which is **two** limits that both have to hold — and the second one is the whole
 * point: a per-token limit alone is a per-token limit anyone can multiply by minting another token
 * from the settings screen.
 *
 * The limits are driven to their ceiling by incrementing the limiter's own key rather than by
 * making sixty real requests. The key is `md5($limiterName.$limit->key)`, which is how Laravel's
 * `ThrottleRequests` builds it — the same technique `AuthenticationTest` uses for the login
 * limiter, and the difference between a test that runs in milliseconds and one nobody keeps.
 */

uses(RefreshDatabase::class);

it('limits a token to its own allowance', function (): void {
    $user = createUserWithTeam();
    $token = $user->createToken('phone', [TokenAbility::LeadsRead->value])->plainTextToken;

    // Keyed off a hash of the presented bearer value, so the credential itself never reaches the
    // cache key (see FortifyServiceProvider).
    RateLimiter::increment(md5('api'.'token:'.hash('xxh128', $token)), amount: 60);

    $this->withToken($token)
        ->getJson('/api/v1/leads')
        ->assertStatus(429)
        ->assertApiError('too_many_requests')
        ->assertHeader('Retry-After');
});

it('limits a user across every token they hold', function (): void {
    $user = createUserWithTeam();

    // A fresh token, with its own untouched per-token allowance...
    $token = $user->createToken('integration', [TokenAbility::LeadsRead->value])->plainTextToken;

    // ...and the account already at the ceiling of three tokens' worth of traffic.
    RateLimiter::increment(md5('api'.'user:'.$user->getKey()), amount: 180);

    // Refused anyway. This is the limit that cannot be multiplied by asking for another token.
    $this->withToken($token)
        ->getJson('/api/v1/leads')
        ->assertStatus(429);
});

it('limits an unauthenticated caller by address', function (): void {
    RateLimiter::increment(md5('api'.'198.51.100.7'), amount: 60);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->postJson('/api/v1/auth/token', [
            'email' => 'nobody@example.test',
            'password' => 'password',
            'device_name' => 'phone',
        ])
        ->assertStatus(429);
});

it('leaves a second token unaffected by the first token spending its allowance', function (): void {
    $user = createUserWithTeam();

    $exhausted = $user->createToken('noisy script', [TokenAbility::LeadsRead->value])->plainTextToken;
    $fresh = $user->createToken('phone', [TokenAbility::LeadsRead->value])->plainTextToken;

    RateLimiter::increment(md5('api'.'token:'.hash('xxh128', $exhausted)), amount: 60);

    // The reason the per-token limit exists at all: a script's retry loop must not take the app
    // down for the person holding the phone.
    $this->withToken($fresh)
        ->getJson('/api/v1/leads')
        ->assertOk();
});

it('exposes Retry-After to a cross-origin caller', function (): void {
    // The mobile app reads it to decide how long to wait (`shared/api/client.ts`). Without the
    // CORS exposure a 429 comes back with nothing to wait on, which is a retry storm.
    expect(config('cors.exposed_headers'))->toContain('Retry-After');
});
