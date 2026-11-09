<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks (PL7)
    |--------------------------------------------------------------------------
    |
    | The retry schedule itself is not here: it is `DeliverWebhookJob::$backoff`, because it has to
    | be the queue's own backoff rather than something a scheduler polls, and a second mechanism
    | that could disagree with the first is worse than no setting.
    |
    */

    'webhooks' => [

        // Per request. Short on purpose: a receiver that takes longer than this to acknowledge a
        // POST is doing work it should be queueing, and the retry schedule is generous.
        'timeout' => (int) env('WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => (int) env('WEBHOOK_CONNECT_TIMEOUT', 5),

        // Consecutive **deliveries** that failed, not attempts. One delivery is already six
        // attempts over thirteen hours, so twenty of them is days of a dead URL — which is the
        // point: switching an endpoint off stops a customer's automation.
        'max_consecutive_failures' => (int) env('WEBHOOK_MAX_FAILURES', 20),

        // How long a settled delivery is kept. The log is a diagnostic, and every row holds a copy
        // of a lead's payload — this is the fastest-growing table in the product.
        'retain_days' => (int) env('WEBHOOK_RETAIN_DAYS', 30),

        'user_agent' => (string) env('WEBHOOK_USER_AGENT', 'AlimaProspects/1.0 (+webhooks)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM OAuth (PL7)
    |--------------------------------------------------------------------------
    |
    | Per-provider credentials belong in `services.php` with every other vendor's, and arrive with
    | the connector that needs them (PL8/PL9). What is here is the one setting the framework itself
    | has.
    |
    */

    'oauth' => [
        // Seconds a `state` is good for. Ten minutes is long enough for somebody to read a consent
        // screen and short enough that a captured URL is worthless by the time it is used — and it
        // is a ceiling rather than the protection, which is that the entry is deleted as it is read.
        'state_ttl' => (int) env('INTEGRATIONS_OAUTH_STATE_TTL', 600),
    ],

];
