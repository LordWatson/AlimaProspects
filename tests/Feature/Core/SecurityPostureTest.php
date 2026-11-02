<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
 * `php artisan security:posture` (Phase 20, ADR 0017).
 *
 * The command reads the *machine's* configuration, which is the half a test cannot see — so what
 * is testable is that it actually fails when a setting is wrong. A posture check that always
 * passes is worse than no check: it is a green tick in a deploy log.
 *
 * Every case runs `--strict`, because the suite's APP_ENV is `testing` and the production rules
 * are the ones worth asserting.
 */

it('passes when the environment is configured correctly', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');
    Config::set('observability.sentry.frontend.dsn', 'https://public@o0.ingest.test/2');
    Config::set('admin.ip_allowlist', ['198.51.100.0/24']);

    $this->artisan('security:posture', ['--strict' => true])->assertSuccessful();
});

it('fails on debug mode', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // The single worst setting in the list: the stack trace, the environment and the database
    // credentials on the error page of anybody who can provoke a 500.
    Config::set('app.debug', true);

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('fails on an insecure session cookie', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // `null` means "follow the request", which behind a misconfigured proxy is a session cookie
    // on the wire.
    Config::set('session.secure', null);

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('fails when admin two-factor has been switched off', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // The switch exists so a test suite does not have to solve a TOTP challenge. In production one
    // super-admin credential reads every tenant's data.
    Config::set('admin.require_two_factor', false);

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('fails when the content security policy has been disabled', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    Config::set('security.csp.enabled', false);

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('fails when Sentry would collect a request body', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // The SDK's own default, and the finding that added this check (Phase 20 security review):
    // `send_default_pii => false` does not cover the body, and ten kilobytes of a prospect record
    // is not something a key-based scrubber can clean up.
    Config::set('sentry.max_request_body_size', 'medium');

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('fails when no backend error reporting is configured', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);

    Config::set('sentry.dsn', null);

    $this->artisan('security:posture', ['--strict' => true])->assertFailed();
});

it('warns rather than fails on a report-only policy and a missing allowlist', function (): void {
    Config::set('app.debug', false);
    Config::set('app.url', 'https://app.alima.example');
    Config::set('session.secure', true);
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // Both are legitimate states — a policy mid-rollout, an egress range nobody knows yet — and a
    // check that cries wolf gets `|| true` appended to it in the deploy script.
    Config::set('security.csp.report_only', true);
    Config::set('admin.ip_allowlist', []);

    $this->artisan('security:posture', ['--strict' => true])->assertSuccessful();
});

it('runs no production checks outside production', function (): void {
    // Without --strict a developer's machine is left alone: APP_DEBUG=true locally is correct.
    Config::set('app.debug', true);
    Config::set('app.url', 'http://alimaprospects.test');
    Config::set('session.secure', null);
    Config::set('sentry.dsn', null);

    $this->artisan('security:posture')->assertSuccessful();
});
