<?php

declare(strict_types=1);

/*
 * The mobile app is the first caller that is not same-origin (Phase 18): a Capacitor WebView
 * serves its bundle from `capacitor://localhost` or `https://localhost`, and `npm run dev` in
 * `/mobile` from the Vite dev server. Cross-origin, a browser hides every response header that
 * was not explicitly exposed — so the shared client's two reads, the version pin and a 429's
 * `Retry-After`, come back empty rather than wrong. Which is the kind of fault that reaches a
 * phone and nothing else.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('answers the preflight a WebView sends before a write', function (): void {
    $this->call('OPTIONS', '/api/v1/leads', server: [
        'HTTP_ORIGIN' => 'https://localhost',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,x-api-version',
    ])
        ->assertNoContent()
        // `*` rather than the origin echoed back, because credentials are off: the token is what
        // authorises the call, so there is nothing an origin allow-list would protect.
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('exposes the two headers the shared client reads', function (): void {
    $user = createUserWithTeam();
    $token = $user->createToken('phone', ['*'])->plainTextToken;

    $exposed = $this->withToken($token)
        ->getJson('/api/v1/user', ['Origin' => 'https://localhost'])
        ->assertOk()
        ->headers->get('Access-Control-Expose-Headers');

    expect(strtolower((string) $exposed))
        ->toContain('x-api-version')
        ->toContain('retry-after');
});

it('never allows credentials cross-origin', function (): void {
    // The token authorises the call, so there is nothing a cookie would add — and allowing them
    // cross-origin would open a CSRF surface for a caller that has no use for it. Asserted rather
    // than assumed, because `allowed_origins: ['*']` is only safe while this stays false.
    $headers = $this->call('OPTIONS', '/api/v1/leads', server: [
        'HTTP_ORIGIN' => 'https://not-ours.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->headers;

    expect($headers->get('Access-Control-Allow-Credentials'))->toBeNull();
});

it('adds no CORS headers outside api/*', function (): void {
    // The browsable docs and the one-click unsubscribe route live outside `api/*` and have no
    // business being reachable cross-origin. `sanctum/csrf-cookie` was dropped from the paths for
    // the same reason: nothing uses it from another origin.
    $headers = $this->get('/up', ['Origin' => 'https://not-ours.example'])->headers;

    expect($headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
