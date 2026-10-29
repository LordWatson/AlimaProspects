<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Business Intelligence configuration
|--------------------------------------------------------------------------
|
| Settings for the AI background check (Phase 6). The `fetch` block bounds the
| minimal web fetch used to gather the business's public text before it is
| handed — as untrusted data — to the `business_intel.background_check` prompt.
| See docs/modules/business-intelligence.md.
|
*/

return [

    'fetch' => [
        // Seconds to wait for the whole response / just the connection.
        'timeout' => (int) env('BI_FETCH_TIMEOUT', 10),
        'connect_timeout' => (int) env('BI_FETCH_CONNECT_TIMEOUT', 5),

        // Hard caps so a huge page can't exhaust memory or blow the AI token budget.
        'max_bytes' => (int) env('BI_FETCH_MAX_BYTES', 500_000),
        'max_chars' => (int) env('BI_FETCH_MAX_CHARS', 12_000),

        'user_agent' => env('BI_FETCH_USER_AGENT', 'AlimaProspectsBot/1.0 (+https://alimaprospects.com/bot)'),
    ],

];
