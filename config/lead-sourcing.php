<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lead Sourcing configuration
|--------------------------------------------------------------------------
|
| Settings for lead discovery (Phase 7 onward). `defaults` bound a run's size;
| each provider block holds its endpoint, credentials and HTTP limits. Only
| Companies House ships in Phase 7 — Google Places and web search (Phase 7b)
| read their keys from .env here too. See docs/modules/lead-sourcing.md and
| ADR 0008. Credentials come from env; never hard-code a key.
|
*/

return [

    'defaults' => [
        // Default and hard-cap number of leads a single run may request.
        'limit' => (int) env('LEAD_SOURCING_DEFAULT_LIMIT', 25),
        'max_limit' => (int) env('LEAD_SOURCING_MAX_LIMIT', 100),
    ],

    // Circuit breaker shared across providers (see Support\CircuitBreaker).
    'circuit_breaker' => [
        'threshold' => (int) env('LEAD_SOURCING_CB_THRESHOLD', 5),
        'cooldown' => (int) env('LEAD_SOURCING_CB_COOLDOWN', 60),
    ],

    'providers' => [

        'companies_house' => [
            // UK Companies House public data API. Auth is HTTP Basic with the API key as the
            // username and an empty password.
            'base_url' => env('COMPANIES_HOUSE_BASE_URL', 'https://api.company-information.service.gov.uk'),
            'api_key' => env('COMPANIES_HOUSE_API_KEY'),

            // Public company record URL (for source attribution on each lead).
            'record_base_url' => env('COMPANIES_HOUSE_RECORD_BASE_URL', 'https://find-and-update.company-information.service.gov.uk'),

            'timeout' => (int) env('COMPANIES_HOUSE_TIMEOUT', 10),
            'connect_timeout' => (int) env('COMPANIES_HOUSE_CONNECT_TIMEOUT', 5),
            // HTTP retry (on connection errors / 5xx) with linear backoff in ms.
            'retry_times' => (int) env('COMPANIES_HOUSE_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('COMPANIES_HOUSE_RETRY_SLEEP_MS', 250),
        ],

        // Phase 7b.
        'google_places' => [
            'api_key' => env('GOOGLE_PLACES_API_KEY'),
        ],

        'web_search' => [
            'provider' => env('WEB_SEARCH_PROVIDER', 'serper'),
            'api_key' => env('WEB_SEARCH_API_KEY'),
        ],

    ],

];
