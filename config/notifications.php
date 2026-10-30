<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Aggregation
    |--------------------------------------------------------------------------
    |
    | How long the "new strong matches" announcement waits before it goes out,
    | and therefore the window it counts over. A discovery run can grade fifty
    | leads in a minute; this is what turns that into one notification instead
    | of fifty (see AnnounceTopLeadsJob).
    |
    */

    'top_leads_window_minutes' => (int) env('NOTIFICATIONS_TOP_LEADS_WINDOW', 15),

    /*
    |--------------------------------------------------------------------------
    | Push (Firebase Cloud Messaging, HTTP v1)
    |--------------------------------------------------------------------------
    |
    | HTTP v1 authenticates with a **service account**, not the legacy
    | `FCM_SERVER_KEY` — Google retired the legacy endpoint in June 2024.
    | Download the service-account JSON from the Firebase console and point
    | `FCM_CREDENTIALS` at it (keep it out of the repo).
    |
    | With no credentials configured, push is simply not sent: a deployment
    | without the mobile app has no reason to hold Firebase keys, and the
    | in-app and email channels are unaffected.
    |
    */

    'push' => [
        'credentials' => env('FCM_CREDENTIALS'),
        // Optional override; otherwise taken from the service-account file.
        'project_id' => env('FCM_PROJECT_ID'),

        'timeout' => (int) env('FCM_TIMEOUT', 10),
        'connect_timeout' => (int) env('FCM_CONNECT_TIMEOUT', 5),
    ],

    'circuit_breaker' => [
        'threshold' => (int) env('NOTIFICATIONS_CB_THRESHOLD', 5),
        'cooldown' => (int) env('NOTIFICATIONS_CB_COOLDOWN', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `notifications:prune` deletes **read** notifications older than
    | `read_days` — an unread one is kept whatever its age, because a member
    | who has not looked has not been told. `device_days` sweeps push tokens
    | nothing has been sent to since, which are sends that silently go nowhere.
    |
    */

    'retention' => [
        'read_days' => (int) env('NOTIFICATIONS_RETENTION_READ_DAYS', 90),
        'device_days' => (int) env('NOTIFICATIONS_RETENTION_DEVICE_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | The bell
    |--------------------------------------------------------------------------
    |
    | How many notifications the shared Inertia prop carries. A cap rather than
    | a page: the panel is a glance at the recent, and the member goes to the
    | full list for anything older.
    |
    */

    'bell_limit' => (int) env('NOTIFICATIONS_BELL_LIMIT', 15),

];
