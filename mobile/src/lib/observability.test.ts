import { describe, expect, it } from 'vitest';
import {
    isSensitiveKey,
    scrub,
    scrubEvent,
} from '@shared/observability/sentry';

/*
 * The browser error reporter's scrubber (Phase 20, ADR 0017).
 *
 * The module under test is `@shared/observability/sentry.ts` — shared with the web app by alias
 * rather than copied (Phase 18). It is tested from here because this is the project in the
 * repository with a JavaScript test runner; the Laravel app's equivalent rules are covered by
 * `tests/Feature/Core/SentryReportingTest.php`, and the two are deliberately the same list.
 *
 * The scrubber is pure and takes no DSN, no network and no SDK, which is the point: the only way
 * to be sure what an error reporter does not send is to be able to run it.
 */

describe('isSensitiveKey', () => {
    it('matches a credential or a personal field by substring, case-insensitively', () => {
        const sensitive = [
            'password',
            'Password',
            'password_confirmation',
            'stripe_secret',
            'api_key',
            'Authorization',
            'two_factor_recovery_codes',
            'contact_email',
            'phone_number',
            'ip_address',
        ];

        expect(sensitive.filter((key) => !isSensitiveKey(key))).toEqual([]);
    });

    it('allows the measurements the substring rule would catch wrongly', () => {
        // `input_tokens` is a token *count*. Redacting it would cost real debugging for no
        // privacy gain, which is why the allowlist exists and why it is short.
        const allowed = ['input_tokens', 'output_tokens', 'max_tokens'];

        expect(allowed.filter((key) => isSensitiveKey(key))).toEqual([]);
    });

    it('leaves ordinary keys alone', () => {
        const ordinary = ['lead_id', 'stage', 'prompt_key', 'team_id'];

        expect(ordinary.filter((key) => isSensitiveKey(key))).toEqual([]);
    });
});

describe('scrub', () => {
    it('redacts at any depth and keeps the shape', () => {
        expect(
            scrub({
                keep: 'visible',
                password: 'hunter2',
                nested: { api_key: 'sk-live', deeper: [{ email: 'a@b.test' }] },
            }),
        ).toEqual({
            keep: 'visible',
            password: '[redacted]',
            nested: {
                api_key: '[redacted]',
                deeper: [{ email: '[redacted]' }],
            },
        });
    });

    it('returns non-objects untouched', () => {
        expect(scrub('plain')).toBe('plain');
        expect(scrub(42)).toBe(42);
        expect(scrub(null)).toBe(null);
    });

    it('stops at a depth bound rather than following a cycle forever', () => {
        const cyclic: Record<string, unknown> = { password: 'x' };
        cyclic.self = cyclic;

        // The assertion is only that it returns. A page that hangs while reporting an error is
        // worse than the error.
        expect(() => scrub(cyclic)).not.toThrow();
    });
});

describe('scrubEvent', () => {
    it('drops the query string and reduces the user to an id', () => {
        const event = {
            request: {
                url: 'https://app.alima.example/leads?q=Acme%20Holdings',
                query_string: 'q=Acme Holdings',
                data: { name: 'Dana Prospect', stage: 'qualified' },
                cookies: { session: 'abc123' },
            },
            user: {
                id: 7,
                email: 'member@example.test',
                username: 'member',
                ip_address: '198.51.100.9',
            },
            breadcrumbs: [
                {
                    data: {
                        url: 'https://app.alima.example/api/v1/leads?search=someone',
                        contact_email: 'prospect@example.test',
                        status_code: 200,
                    },
                },
            ],
        };

        scrubEvent(event);

        // A search term in this product is somebody's company or contact name.
        expect(event.request.url).toBe(
            'https://app.alima.example/leads?[redacted]',
        );
        expect(event.request.query_string).toBe('[redacted]');

        // Dropped, not key-scrubbed: a request body here is the prospect record somebody was
        // saving, and `name`/`company_name`/`notes` are on no deny-list. Same as the backend.
        expect(event.request.data).toBeUndefined();
        expect(event.request.cookies).toBeUndefined();

        // An id and nothing else — the same rule the backend enforces.
        expect(event.user).toEqual({ id: 7, ip_address: null });

        expect(event.breadcrumbs[0].data).toEqual({
            url: 'https://app.alima.example/api/v1/leads?[redacted]',
            contact_email: '[redacted]',
            status_code: 200,
        });
    });

    it('tells Sentry not to infer an address even when there is no user', () => {
        const event: { user?: Record<string, unknown> | null } = {};

        scrubEvent(event);

        // An absent user object is not the same instruction as an explicit null: Sentry infers
        // the sender's IP server-side unless the event says otherwise.
        expect(event.user).toEqual({ id: undefined, ip_address: null });
    });
});
