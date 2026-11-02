import type { App } from 'vue';
import {
    initializeSentry,
    type SentryConfig,
} from '@shared/observability/sentry';

/**
 * Error reporting for the app (Phase 20, ADR 0017).
 *
 * The scrubbing, the SDK options and the privacy decisions are all in `@shared/observability/
 * sentry.ts` — the same file the web app compiles, by alias and never by copy (Phase 18). This
 * module exists only because the *source* of the configuration differs: the web app is handed it
 * in the Inertia props, and a phone has no Inertia, so it is baked in at build time from
 * `mobile/.env` like `VITE_API_URL` already is.
 *
 * A separate Sentry **project** from the web app's, which is the point of a separate DSN: a crash
 * on a three-year-old handset and a 500 in a queue worker are triaged by different people with
 * different questions, and one project would mean neither release list is readable.
 *
 * Note what this is **not**: `@sentry/capacitor`. That one adds native crash reporting, and native
 * crash reporting means a Gradle dependency and a CocoaPod in the committed native projects —
 * which Phase 18 generates rather than hand-edits. Everything in this app is TypeScript in a
 * WebView, so the browser SDK sees every error there is to see. A native crash here would be a
 * crash in Capacitor or in the WebView itself, which is not our bug to triage.
 */
export function initializeErrorReporting(app: App): void {
    const dsn = (import.meta.env.VITE_SENTRY_DSN ?? '').trim();

    if (dsn === '') {
        return;
    }

    const config: SentryConfig = {
        dsn,
        environment:
            (import.meta.env.VITE_SENTRY_ENVIRONMENT ?? '').trim() ||
            (import.meta.env.DEV ? 'local' : 'production'),
        release: (import.meta.env.VITE_SENTRY_RELEASE ?? '').trim() || null,
        // Lower than the web app's by default. A trace costs the handset's battery and the
        // member's mobile data, and the thing worth sampling on a phone is the exception, not the
        // span.
        tracesSampleRate: Number(
            import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0,
        ),
    };

    // Not awaited: the SDK import must not sit in front of the first frame. See `main.ts` for why
    // everything else at boot is awaited and this is not.
    void initializeSentry(app, config);
}
