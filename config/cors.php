<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Published in Phase 18, because the mobile app is the first caller that is
| not same-origin. A Capacitor WebView serves the bundle from
| `capacitor://localhost` (iOS) or `https://localhost` (Android) and calls
| `/api/v1` cross-origin; `npm run dev` in `/mobile` does the same from the
| Vite dev server.
|
| Two things the framework defaults got wrong for that caller, both silent:
|
| 1. `exposed_headers` was empty, so a cross-origin response's `X-Api-Version`
|    and `Retry-After` were invisible to `fetch` — not missing, *unreadable*.
|    The shared client reads both (`shared/api/client.ts`), so a 429 came back
|    with nothing to wait on and the version pin could not be checked.
| 2. The browsable docs and the one-click unsubscribe route live outside
|    `api/*` and have no business being reachable cross-origin, so the paths
|    stay as narrow as they were.
|
| Credentials stay off. The API is a bearer-token surface for anyone who is
| not same-origin; allowing cookies cross-origin would be a CSRF surface
| opened for a caller that has no use for it.
|
*/

return [

    // `api/*` only. Sanctum's `sanctum/csrf-cookie` is in the framework's default list for a
    // cross-origin **SPA** that authenticates with cookies; this app's web client is same-origin
    // Inertia and the mobile app uses a bearer token, so nothing needs that route cross-origin —
    // and a surface nothing uses is a surface not worth exposing.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // A bearer-token API with no cookies: the origin is not what authorises
    // the call, the token is. Narrowing this would not add a control — any
    // native client can send whatever `Origin` it likes — while breaking the
    // WebView origins, which differ per platform and per Capacitor version.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The two the shared client has to be able to read.
    'exposed_headers' => ['X-Api-Version', 'Retry-After'],

    // Cache the preflight for a day. Without it every write from the app pays
    // for an extra round trip, on a phone, on mobile data. Browsers cap this
    // themselves — Chromium at 2 hours, Firefox at 24 — so the number is a
    // ceiling we ask for rather than one we get.
    'max_age' => 86400,

    'supports_credentials' => false,

];
