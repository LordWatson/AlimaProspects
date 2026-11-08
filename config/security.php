<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Response security headers (Phase 20, ADR 0017)
|--------------------------------------------------------------------------
|
| Everything App\Modules\Core\Http\Middleware\SecurityHeaders puts on a
| response, plus the production posture `php artisan security:posture`
| asserts. Config rather than constants because a header is a deployment
| decision — the CSP of a staging box behind basic auth and of production
| behind a CDN are not the same string — and because a reviewed diff to a
| config file is how the rest of this app records that kind of decision.
|
| Two notes worth having before changing anything here.
|
| The **CSP is nonce-based and is not emitted while the Vite dev server is
| running**. A nonce only works if every inline script and style carries it,
| and in `npm run dev` Vue injects component styles from JavaScript with no
| nonce to carry — so a policy that held locally would hold by being broken.
| It is emitted for every built asset path, which is every environment that
| is not somebody's laptop mid-edit, and `report_only` is the way to roll a
| change out without breaking a page.
|
| And `connect-src` is **derived, not listed**: the realtime socket and the
| Sentry ingest endpoint are already configured elsewhere (`REVERB_*`,
| `SENTRY_DSN_FRONTEND`), and a second copy of a host here is a second place
| to forget. See ContentSecurityPolicy::connectSources().
|
*/

return [

    'headers' => [

        /*
        | HSTS. Production and HTTPS only — sent over plain HTTP it is ignored, and sent from a
        | local `.test` domain it pins a developer's browser to HTTPS for a host that has no
        | certificate, which is a morning lost. `preload` is deliberately off: it is a one-way door
        | (removal takes months) and belongs to the Phase 21 deployment decision, not to a default.
        */
        'hsts' => [
            'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
            'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
            'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
        ],

        /*
        | `X-Frame-Options: DENY` duplicates `frame-ancestors 'none'` below for browsers that
        | predate CSP3. Keeping both costs 30 bytes and is the difference between a clickjacking
        | control and an aspiration on an old enterprise browser.
        */
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        /*
        | Nothing in this product uses a camera, a microphone, a payment handler or the viewer's
        | location, so every one of those is denied rather than left to a future default. The list
        | is written as a denial of named features, not `*=()`, because an empty allowlist for an
        | unknown future feature is the browser's job to decide, not ours.
        */
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', implode(', ', [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'display-capture=()',
            'encrypted-media=()',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'usb=()',
            'xr-spatial-tracking=()',
        ])),

        /*
        | Cross-origin isolation headers. `same-origin` on the opener policy and `same-site` on the
        | resource policy are what stop another site keeping a handle on a window we opened or
        | reading a response it should not have. `credentialless` embedder policy is *not* set:
        | nothing here needs `SharedArrayBuffer`, and it would break the Bunny font stylesheet.
        */
        'cross_origin_opener_policy' => env('SECURITY_COOP', 'same-origin'),
        'cross_origin_resource_policy' => env('SECURITY_CORP', 'same-site'),

        'permitted_cross_domain_policies' => env('SECURITY_PERMITTED_CROSS_DOMAIN_POLICIES', 'none'),
    ],

    'csp' => [

        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        /*
        | Send `Content-Security-Policy-Report-Only` instead of the enforcing header. The way to
        | roll out a tightened policy: deploy it report-only, read the violations in Sentry, then
        | flip this off. It is **off** by default — a report-only default would mean the app has
        | shipped without a policy while every screen looks like it has one.
        */
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        /*
        | Where a violation is reported. Sentry's CSP endpoint if you have one; empty means the
        | browser reports nowhere, which is the default because an unconfigured URL would otherwise
        | send every visitor's violations to whatever host was in the example.
        */
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),

        /*
        | Paths that get no policy. The vendor dashboards inject their own inline Livewire and
        | Alpine bootstrapping, which we neither control nor can nonce — and a policy that breaks
        | the tool an operator opens at 2am is worse than no policy on a route already behind the
        | platform-admin door, enforced 2FA and the optional IP allowlist. A list rather than an
        | `if` in the middleware so the exemption is visible.
        */
        'except' => [
            'pulse',
            'pulse/*',
            'horizon',
            'horizon/*',
        ],

        /*
        | Origins the app legitimately talks to, beyond `'self'` and the two derived from the
        | Reverb and Sentry configuration. Comma-separated, e.g. a CDN hostname once Phase 21
        | puts one in front of `public/`.
        */
        'extra_connect_src' => env('SECURITY_CSP_EXTRA_CONNECT_SRC'),
        'extra_script_src' => env('SECURITY_CSP_EXTRA_SCRIPT_SRC'),
        'extra_img_src' => env('SECURITY_CSP_EXTRA_IMG_SRC'),

        /*
        | Hosts allowed to be framed. **Empty keeps `frame-src 'none'`**, which is the shipped
        | answer; the two cannot be combined, because `'none'` wins over any host beside it. Set it
        | to `https://challenges.cloudflare.com` when Turnstile keys are configured (PL5), since a
        | captcha is an iframe.
        */
        'extra_frame_src' => env('SECURITY_CSP_EXTRA_FRAME_SRC'),

        /*
        | The realtime socket the **browser** opens, for `connect-src`.
        |
        | `VITE_REVERB_*` and not `REVERB_*`, and the difference is the whole reason this entry
        | exists (found by the Phase 20 review). The server-side `REVERB_HOST`/`PORT` is where
        | *Laravel* publishes events to; `resources/js/echo.ts` connects to the `VITE_` copy. In
        | `.env.example` the second interpolates from the first, so they agree — and behind a proxy
        | they do not: the app publishes to an internal host and port while the browser connects to
        | `wss://realtime.example.com:443`. Deriving the policy from the server value would then
        | silently block the socket the page actually opens, which is a progress bar that never
        | moves and nothing in the logs.
        |
        | Falls back to the server values so a single-host deployment needs no extra configuration.
        */
        'reverb' => [
            'host' => env('VITE_REVERB_HOST', env('REVERB_HOST')),
            'port' => env('VITE_REVERB_PORT', env('REVERB_PORT')),
            'scheme' => env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
        ],

        /*
        | The stylesheet + font host the brand fonts come from (`app.blade.php`, brand-tokens.md
        | §3). Here rather than hard-coded in the builder so that self-hosting the fonts — which
        | is the better answer eventually — is a config change and a blade change, not a hunt.
        */
        'font_host' => env('SECURITY_CSP_FONT_HOST', 'https://fonts.bunny.net'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Production posture
    |--------------------------------------------------------------------------
    |
    | What `php artisan security:posture` insists on when APP_ENV is production. It exists because
    | every one of these is a setting that is *correct* in `.env.example` and silently wrong on a
    | box somebody configured in a hurry — and because several of them (debug mode, the admin 2FA
    | switch) are the kind of thing that is switched off for an afternoon and left off. Phase 21
    | runs it as a deploy step.
    |
    */
    'posture' => [
        'require_sentry_dsn' => (bool) env('SECURITY_POSTURE_REQUIRE_SENTRY', true),
    ],

];
