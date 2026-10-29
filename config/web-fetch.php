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

];
