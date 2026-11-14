<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web content fetch configuration
|--------------------------------------------------------------------------
|
| Bounds the shared SSRF-safe web fetcher (App\Modules\Core\Services\
| HttpWebContentFetcher) used to read a business's or a lead's public web
| text before it is handed — as untrusted data — to an AI prompt (Business
| Intelligence's background check, Enrichment's fact extraction). Every value
| is a hard cap so a slow or huge page can't exhaust a worker or blow the AI
| token budget. See app/Modules/Core/Contracts/WebContentFetcher.php.
|
*/

return [

    // Seconds to wait for the whole response / just the connection.
    'timeout' => (int) env('WEB_FETCH_TIMEOUT', 10),
    'connect_timeout' => (int) env('WEB_FETCH_CONNECT_TIMEOUT', 5),

    // Hard caps so a huge page can't exhaust memory or blow the AI token budget.
    'max_bytes' => (int) env('WEB_FETCH_MAX_BYTES', 500_000),
    'max_chars' => (int) env('WEB_FETCH_MAX_CHARS', 12_000),

    'user_agent' => env('WEB_FETCH_USER_AGENT', 'AlimaProspectsBot/1.0 (+https://alimaprospects.com/bot)'),

    /*
    | Reading a business's own site (App\Modules\Core\Services\SiteReader): the homepage plus the
    | few pages that say what it does and who for. Every value is a hard cap, and whichever runs
    | out first ends the read. `max_seconds` has to leave the background-check job room for its AI
    | call inside the queue's 85-second timeout.
    */
    'site' => [
        'max_pages' => (int) env('WEB_FETCH_SITE_MAX_PAGES', 6),
        'page_chars' => (int) env('WEB_FETCH_SITE_PAGE_CHARS', 8_000),
        'max_total_chars' => (int) env('WEB_FETCH_SITE_MAX_TOTAL_CHARS', 40_000),
        'max_seconds' => (int) env('WEB_FETCH_SITE_MAX_SECONDS', 25),
    ],

];
