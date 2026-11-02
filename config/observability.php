<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Observability (Phase 20, ADR 0017)
|--------------------------------------------------------------------------
|
| Everything about error reporting and metrics that is **ours** rather than
| the PHP SDK's. `config/sentry.php` is the vendor file and stays one: every
| key in it is handed straight to Sentry's option resolver, which throws on a
| key it does not know — so a setting of our own cannot live there, and the
| frontend and mobile DSNs in particular would break the boot if it did.
|
| The browser DSN is **served, not built in**. The frontend reads it from the
| Inertia props (`HandleInertiaRequests`), so one `.env` value configures
| staging and production without a rebuild per environment — and the CSP
| derives the ingest origin from the same value, so the policy can never
| allow a different host than the one the SDK posts to. The mobile app has no
| Inertia, so it keeps a build-time `VITE_SENTRY_DSN` in `mobile/.env`; its
| key is documented here so the three DSNs are findable in one place.
|
| A DSN is public by design — it authorises *writing* an event to a project
| and nothing else. The auth token that would read a project is not in this
| application at all.
|
*/

return [

    'sentry' => [

        /*
        | The browser SDK's configuration, shared with every Inertia response. No DSN means the
        | SDK is never loaded at all: the import is dynamic, so an installation that is not using
        | Sentry does not pay for its bundle.
        */
        'frontend' => [
            'dsn' => env('SENTRY_DSN_FRONTEND'),

            // Both default to the backend's, because a release and an environment that differ
            // between the two halves of one deploy make a trace impossible to follow across them.
            'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
            'release' => env('SENTRY_RELEASE'),

            'traces_sample_rate' => (float) env('SENTRY_FRONTEND_TRACES_SAMPLE_RATE', 0.1),

            /*
            | Session replay is **off and stays off** unless somebody decides otherwise with the
            | privacy notice open in front of them. It records the screen of a person working a
            | list of named prospects, which is third-party personal data this product is a
            | processor for (docs/compliance.md) — a debugging convenience is not a lawful basis.
            */
            'replays_session_sample_rate' => (float) env('SENTRY_FRONTEND_REPLAY_SAMPLE_RATE', 0.0),
            'replays_on_error_sample_rate' => (float) env('SENTRY_FRONTEND_REPLAY_ERROR_SAMPLE_RATE', 0.0),
        ],

        /*
        | The mobile app's own project (Phase 18's `/mobile`). Read at **build** time from
        | `mobile/.env` as `VITE_SENTRY_DSN`; this entry documents the key and lets the posture
        | check mention it, nothing more.
        */
        'mobile' => [
            'dsn' => env('SENTRY_DSN_MOBILE'),
        ],

        /*
        | Transactions never worth a trace: a health check every ten seconds and the two operator
        | dashboards, which poll. Matched against the transaction name with `Str::is`, so a `*`
        | works. Dropping them in the sampler rather than after the fact is the point — an unsampled
        | transaction costs nothing, a sampled-then-discarded one has already been measured.
        |
        | Patterns are matched against the transaction name both as Sentry gives it (`GET /up`) and
        | with the HTTP method stripped (`/up`), so an entry here can be written as a path and will
        | still match — see `SentryOptions::isIgnoredTransaction()`.
        */
        'ignore_transactions' => [
            '/up',
            '/pulse*',
            '/horizon*',
            'pulse*',
            'horizon*',
        ],

        /*
        | Keys whose **value** is replaced with `[redacted]` anywhere in an event — request data,
        | extra context, contexts, tags, breadcrumb metadata, the user bag. Matched
        | case-insensitively against the whole key, and substring-matched for the obvious stems, so
        | `stripe_secret` and `two_factor_recovery_codes` are caught without being listed.
        |
        | **What this list is for.** Our own code: a `Log::error('...', ['payload' => $request->all()])`
        | somewhere in eighteen modules becoming a breadcrumb is the realistic way a password or a
        | prospect's email address reaches an error tracker.
        |
        | **What it cannot do**, and the mistake made here first and found by the Phase 20 security
        | review: it does not keep the URL, the query string, the cookies or the request body out.
        | A deny-list cannot — the keys (`url`, `query_string`, `data`) do not look sensitive while
        | the values do. Those are handled **by name** in `SentryOptions::scrubRequest()`, and the
        | body is additionally never collected (`sentry.max_request_body_size`). Nor is
        | `send_default_pii => false` enough on its own: it gates the cookies, the headers and the
        | IP address, and **not** the URL, the query string or the body.
        */
        'scrub' => [
            'contains' => [
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
            ],

            /*
            | Keys the substring rule would catch and should not. `input_tokens` is a token *count*,
            | not a credential, and it is one of the few numbers that makes an AI failure
            | diagnosable — redacting it would cost real debugging for no privacy gain. The list is
            | short on purpose: an exception here is a hole, so each one has to be a measurement.
            */
            'allow' => [
                'input_tokens',
                'output_tokens',
                'cache_read_input_tokens',
                'max_tokens',
                'total_tokens',
                'token_count',
            ],
        ],

        'redaction' => '[redacted]',
    ],

];
