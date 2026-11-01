// Browser error reporting, shared by the web app and the mobile app (Phase 20, ADR 0017).
//
// In `shared/` rather than twice, for the reason Phase 18 put the API client and the brand tokens
// here: a scrubbing rule that exists in two files is a scrubbing rule that is wrong in one of them.
// The two callers differ only in where the configuration comes from — the web app is handed it in
// the Inertia props (`HandleInertiaRequests::observability()`), and the mobile app, which has no
// Inertia, reads its own build-time `VITE_SENTRY_DSN`.
//
// Three decisions worth knowing before changing anything here.
//
// **The SDK is imported dynamically.** No DSN, no import: an installation that is not using Sentry
// does not pay ~90 kB for its bundle, and on a phone that is install size on a device, not a cache
// miss.
//
// **Nothing is reported until it has been scrubbed**, by the same key rule the backend applies
// (`config/observability.php` → `Core\Observability\SentryOptions`). The browser is the half with
// a prospect's name on the screen and their email address in a form field, so this is the side
// where it matters most. The list is mirrored rather than fetched, because a scrubber that needs a
// round trip to know what to redact is a scrubber that fails open.
//
// **Session replay is off** and not merely unconfigured. It records the screen of somebody working
// a list of named prospects — third-party personal data this product is a processor for — and a
// debugging convenience is not a lawful basis (docs/compliance.md). The sample rates are plumbed
// through so turning it on is a decision somebody makes deliberately, with the privacy notice open.

import type { App } from 'vue';

export type SentryConfig = {
    dsn: string;
    environment?: string;
    release?: string | null;
    tracesSampleRate?: number;
    replaysSessionSampleRate?: number;
    replaysOnErrorSampleRate?: number;
};

/**
 * Mirror of `observability.sentry.scrub.contains`. Substring, case-insensitive.
 */
const SENSITIVE_KEYS = [
    'password',
    'secret',
    'token',
    'authorization',
    'cookie',
    'api_key',
    'apikey',
    'dsn',
    'credential',
    'card',
    'cvc',
    'iban',
    'recovery_code',
    'two_factor',
    'email',
    'phone',
    'address',
];

/** Mirror of `observability.sentry.scrub.allow` — measurements, not credentials. */
const ALLOWED_KEYS = [
    'input_tokens',
    'output_tokens',
    'cache_read_input_tokens',
    'max_tokens',
    'total_tokens',
    'token_count',
];

const REDACTION = '[redacted]';

/** Default integrations removed at init — see `initializeSentry` for why each one. */
const EXCLUDED_INTEGRATIONS = ['BrowserSession', 'Console'];

/** How deep `scrub` will walk before it stops, so a cyclic payload cannot hang the page. */
const MAX_DEPTH = 8;

export function isSensitiveKey(key: string): boolean {
    const needle = key.toLowerCase();

    if (ALLOWED_KEYS.includes(needle)) {
        return false;
    }

    return SENSITIVE_KEYS.some((sensitive) => needle.includes(sensitive));
}

/**
 * Replace the value of every sensitive key, at any depth. Arrays keep their shape; anything that
 * is not a plain object or an array is returned as it is.
 */
export function scrub(value: unknown, depth = 0): unknown {
    if (depth >= MAX_DEPTH) {
        return value;
    }

    if (Array.isArray(value)) {
        return value.map((item) => scrub(item, depth + 1));
    }

    if (value === null || typeof value !== 'object') {
        return value;
    }

    const source = value as Record<string, unknown>;
    const result: Record<string, unknown> = {};

    for (const key of Object.keys(source)) {
        result[key] = isSensitiveKey(key)
            ? REDACTION
            : scrub(source[key], depth + 1);
    }

    return result;
}

/**
 * A URL with its query string dropped.
 *
 * Breadcrumbs record every `fetch` the page makes, and a query string is where a search term —
 * which in this product is somebody's company or contact name — ends up. The path is what makes a
 * breadcrumb useful; the query is what makes it a disclosure.
 */
function stripQuery(url: unknown): unknown {
    if (typeof url !== 'string') {
        return url;
    }

    const cut = url.indexOf('?');

    return cut === -1 ? url : `${url.slice(0, cut)}?${REDACTION}`;
}

/**
 * The parts of a Sentry event this module touches.
 *
 * Structurally compatible with the SDK's `ErrorEvent` and `TransactionEvent`, so no cast is needed
 * at the call site — and deliberately *narrower*, so this file does not have to be revisited every
 * time the SDK adds a field.
 */
type ScrubbableEvent = {
    request?: {
        data?: unknown;
        cookies?: unknown;
        query_string?: unknown;
        url?: unknown;
    };
    extra?: Record<string, unknown>;
    contexts?: Record<string, unknown>;
    breadcrumbs?: Array<{ data?: Record<string, unknown> }>;
    user?: Record<string, unknown> | null;
};

/**
 * Scrub everything on an event that can carry a value we do not want to send. Mutates, because
 * `beforeSend` hands us the event the SDK is about to serialise.
 *
 * Exported so it can be tested without a DSN, a network or an SDK — the whole point of a scrubber
 * is being able to prove what it drops.
 */
export function scrubEvent(event: ScrubbableEvent): void {
    if (event.request) {
        // `data` and `cookies` are **dropped**, not scrubbed by key, for the same reason the
        // backend drops them: a request body in this product is the prospect record somebody was
        // saving, whose field names (`name`, `company_name`, `notes`) are not credential-shaped
        // and so are on no deny-list. The backend half of this was a real finding in the Phase 20
        // review; the browser SDK does not normally populate either, and the two halves agreeing
        // is the point.
        delete event.request.data;
        delete event.request.cookies;
        event.request.query_string = REDACTION;
        event.request.url = stripQuery(event.request.url);
    }

    if (event.extra) {
        event.extra = scrub(event.extra) as Record<string, unknown>;
    }

    if (event.contexts) {
        event.contexts = scrub(event.contexts) as Record<string, unknown>;
    }

    for (const breadcrumb of event.breadcrumbs ?? []) {
        if (breadcrumb.data && 'url' in breadcrumb.data) {
            breadcrumb.data.url = stripQuery(breadcrumb.data.url);
        }

        breadcrumb.data = scrub(breadcrumb.data) as
            | Record<string, unknown>
            | undefined;
    }

    // The actor is an id and nothing else — the same rule the backend enforces. Whatever the SDK
    // or an integration put here, only the id survives.
    //
    // `ip_address: null` is set **always**, not only when there is a user: Sentry infers the
    // sender's address server-side unless the event says not to, and an absent user object is not
    // the same instruction as an explicit null.
    event.user = { id: event.user?.id, ip_address: null };
}

/**
 * Initialise the SDK for a Vue app, or do nothing at all when no DSN is configured.
 *
 * Resolves once the SDK is loaded and initialised, so a caller that wants errors from its own
 * startup can await it. Failures to load are swallowed deliberately: an error tracker that stops
 * the app from booting is worse than no error tracker.
 */
export async function initializeSentry(
    app: App,
    config: SentryConfig | null | undefined,
): Promise<void> {
    if (!config?.dsn) {
        return;
    }

    try {
        const Sentry = await import('@sentry/vue');

        Sentry.init({
            app,
            dsn: config.dsn,
            environment: config.environment,
            release: config.release ?? undefined,

            // Two of the browser SDK's default integrations are dropped, and both for the same
            // reason: they send something no `beforeSend` can clean up.
            //
            // `BrowserSession` reports release health, and a session is the one envelope the SDK
            // sends with an **inferred IP address** — the scrubber below never sees it, because it
            // is not an event. Crash-free-rate is not worth an exception to "no personal data".
            //
            // `Console` turns every `console.*` call into a breadcrumb whose *message* is free
            // text. A key-based scrubber cannot inspect a sentence, so the only honest options are
            // to send it unscrubbed or not to send it. Errors and unhandled rejections still
            // arrive — those come from the global handlers, not from this.
            integrations: (defaults) =>
                defaults.filter(
                    (integration) =>
                        !EXCLUDED_INTEGRATIONS.includes(integration.name),
                ),

            tracesSampleRate: config.tracesSampleRate ?? 0,
            replaysSessionSampleRate: config.replaysSessionSampleRate ?? 0,
            replaysOnErrorSampleRate: config.replaysOnErrorSampleRate ?? 0,

            // Off: it attaches the props of every component in the stack to an error, and in this
            // app a component's props are a prospect's record.
            attachProps: false,

            beforeSend: (event) => {
                scrubEvent(event);

                return event;
            },
            beforeSendTransaction: (event) => {
                scrubEvent(event);

                return event;
            },
        });
    } catch {
        // No reporting is a worse product; a broken boot is not a product at all.
    }
}
