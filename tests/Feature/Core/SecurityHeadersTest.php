<?php

declare(strict_types=1);

use App\Modules\Core\Support\Security\ContentSecurityPolicy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/*
 * The response security headers (Phase 20, ADR 0017).
 *
 * Against the **real middleware stack** on real routes, not against the middleware in isolation:
 * the header that goes missing is never the one somebody wrote a unit test for, it is the one on
 * the route that turned out not to be in the group. `SecurityHeaders` is global middleware, and
 * these assertions are what hold it there.
 */

it('sends the base headers on a web response', function (): void {
    $response = $this->get('/');

    $response->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
    expect($response->headers->get('X-Permitted-Cross-Domain-Policies'))->toBe('none');
    expect((string) $response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('sends the base headers on an api response too', function (): void {
    // Global middleware, so an unauthenticated 401 envelope carries them as well. A JSON response
    // cannot be clickjacked, but it can be sniffed into something the browser will execute.
    $response = $this->getJson('/api/v1/user');

    $response->assertUnauthorized();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('sends a nonce-based content security policy on html, and the nonce matches the inline tags', function (): void {
    $response = $this->get('/');

    $policy = (string) $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("base-uri 'self'")
        ->and($policy)->toContain("form-action 'self'")
        // The whole point of a nonce policy: no blanket unsafe-inline on scripts.
        ->and($policy)->not->toContain("script-src 'self' 'unsafe-inline'");

    preg_match("/script-src [^;]*'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $matches);

    expect($matches[1] ?? '')->not->toBe('');

    // And the nonce in the header is the nonce on the page. A policy whose nonce does not match
    // the markup is a blank screen, which is the failure this assertion exists to catch.
    $response->assertSee('nonce="'.$matches[1].'"', escape: false);
});

it('does not send a policy on a json response', function (): void {
    $response = $this->getJson('/api/v1/user');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('can be switched to report-only', function (): void {
    Config::set('security.csp.report_only', true);

    $response = $this->get('/');

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('skips the policy on the vendor operator dashboards', function (): void {
    // `pulse` is in `security.csp.except` because Pulse injects inline Livewire bootstrapping we
    // cannot nonce. The route itself 404s for a non-operator, which is the point — but the header
    // decision is made before the route runs, so this still proves the exemption.
    Route::get('pulse/_csp-probe', fn (): string => '<html><body>probe</body></html>');

    $response = $this->get('pulse/_csp-probe');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        // The other headers still apply: only the policy is excepted.
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('sends HSTS over https and never over plain http', function (): void {
    expect($this->get('/')->headers->has('Strict-Transport-Security'))->toBeFalse();

    $response = $this->get('https://localhost/');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

it('skips the policy on the configured pulse path, not only on the default one', function (): void {
    // `PULSE_PATH` is an env var. A deployment that moves the dashboard to `/_ops` would otherwise
    // lose the exemption and get a broken dashboard, with `security.csp.except` still listing
    // `pulse` and so still claiming the exemption exists.
    Config::set('pulse.path', '_ops');

    Route::get('_ops/_csp-probe', fn (): string => '<html><body>probe</body></html>');

    expect($this->get('_ops/_csp-probe')->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('derives the realtime socket and the sentry ingest origin into connect-src', function (): void {
    Config::set('security.csp.reverb.host', 'ws.alima.example');
    Config::set('security.csp.reverb.port', 443);
    Config::set('security.csp.reverb.scheme', 'https');
    Config::set('observability.sentry.frontend.dsn', 'https://abc123@o999.ingest.sentry.io/4567');

    $sources = (new ContentSecurityPolicy('test-nonce'))->connectSources();

    expect($sources)->toContain("'self'")
        ->toContain('wss://ws.alima.example:443')
        // The origin only. The DSN carries a key and has no business in a header.
        ->toContain('https://o999.ingest.sentry.io')
        ->not->toContain('https://abc123@o999.ingest.sentry.io/4567');
});

it('uses the ws scheme when reverb is configured for plain http', function (): void {
    Config::set('security.csp.reverb.host', 'localhost');
    Config::set('security.csp.reverb.port', 8080);
    Config::set('security.csp.reverb.scheme', 'http');
    Config::set('observability.sentry.frontend.dsn', null);

    expect((new ContentSecurityPolicy('n'))->connectSources())->toBe([
        "'self'",
        'ws://localhost:8080',
    ]);
});

it('takes the socket origin the browser uses, not the one the server publishes to', function (): void {
    // Behind a proxy these differ: the app publishes to an internal host and port while the page
    // connects to a public `wss://…:443`. A policy derived from the server value silently blocks
    // the socket the page actually opens — a progress bar that never moves, and nothing in the
    // logs. `security.csp.reverb` reads the `VITE_REVERB_*` values for that reason.
    Config::set('broadcasting.connections.reverb.options.host', 'reverb-internal.svc');
    Config::set('broadcasting.connections.reverb.options.port', 8080);
    Config::set('broadcasting.connections.reverb.options.scheme', 'http');
    Config::set('security.csp.reverb.host', 'realtime.alima.example');
    Config::set('security.csp.reverb.port', 443);
    Config::set('security.csp.reverb.scheme', 'https');
    Config::set('observability.sentry.frontend.dsn', null);

    expect((new ContentSecurityPolicy('n'))->connectSources())->toBe([
        "'self'",
        'wss://realtime.alima.example:443',
    ]);
});

it('allows extra origins to be configured without touching the builder', function (): void {
    Config::set('security.csp.reverb.host', null);
    Config::set('observability.sentry.frontend.dsn', null);
    Config::set('security.csp.extra_connect_src', 'https://cdn.alima.example, https://other.example');

    expect((new ContentSecurityPolicy('n'))->connectSources())->toBe([
        "'self'",
        'https://cdn.alima.example',
        'https://other.example',
    ]);
});
