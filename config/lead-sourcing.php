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

    // AI query shaping (DiscoveryQueryShaper): turn the team's own business profile into a search
    // for its CUSTOMERS before any provider is called. Disabling it falls back to the deterministic
    // DiscoveryQuery::fromIcp(), which searches the ICP's industry values verbatim — fine when those
    // values genuinely describe buyers, and the reason a design studio used to be shown design
    // studios when they didn't. One model call per source per run; never called from the job, so a
    // retry can't re-bill it.
    /*
    |--------------------------------------------------------------------------
    | Relevance gate (ADR 0035)
    |--------------------------------------------------------------------------
    |
    | One cheap-tier, batched AI judgement of each candidate against the team's own business
    | before it becomes a lead. It refuses only a `not_fit` or `competitor` verdict at or above
    | `min_confidence`; everything else is kept. `batch_size` x `max_calls` is the most candidates
    | one run will pay to judge — the rest pass unjudged. Switch it off and discovery behaves as
    | it did before: the word-matching exclusion filter only.
    |
    */

    'relevance_gate' => [
        'enabled' => (bool) env('LEAD_SOURCING_RELEVANCE_GATE', true),
        'min_confidence' => (int) env('LEAD_SOURCING_RELEVANCE_GATE_MIN_CONFIDENCE', 70),
        'batch_size' => (int) env('LEAD_SOURCING_RELEVANCE_GATE_BATCH', 25),
        'max_calls' => (int) env('LEAD_SOURCING_RELEVANCE_GATE_MAX_CALLS', 2),
        // How many refused companies a run keeps as a sample for the member to check.
        'rejection_sample' => 20,
    ],

    'query_shaping' => [
        'enabled' => (bool) env('LEAD_SOURCING_QUERY_SHAPING', true),
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

        'google_places' => [
            // Places API (New) Text Search. Auth via the X-Goog-Api-Key header.
            'base_url' => env('GOOGLE_PLACES_BASE_URL', 'https://places.googleapis.com'),
            'api_key' => env('GOOGLE_PLACES_API_KEY'),
            'maps_url' => env('GOOGLE_PLACES_MAPS_URL', 'https://www.google.com/maps/place/?q=place_id:'),

            'timeout' => (int) env('GOOGLE_PLACES_TIMEOUT', 10),
            'connect_timeout' => (int) env('GOOGLE_PLACES_CONNECT_TIMEOUT', 5),
            'retry_times' => (int) env('GOOGLE_PLACES_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('GOOGLE_PLACES_RETRY_SLEEP_MS', 250),

            // Indicative billed cost of one Text Search request, in micro-units (~$0.032). Verify
            // against live pricing; surfaced on discovery_runs.cost_micros.
            'cost_micros_per_request' => (int) env('GOOGLE_PLACES_COST_MICROS', 32_000),
        ],

        'web_search' => [
            // Driver selected here; only `serper` is implemented in the MVP (ADR 0010).
            'provider' => env('WEB_SEARCH_PROVIDER', 'serper'),
            'api_key' => env('WEB_SEARCH_API_KEY'),
            'base_url' => env('WEB_SEARCH_BASE_URL', 'https://google.serper.dev'),

            'timeout' => (int) env('WEB_SEARCH_TIMEOUT', 10),
            'connect_timeout' => (int) env('WEB_SEARCH_CONNECT_TIMEOUT', 5),
            'retry_times' => (int) env('WEB_SEARCH_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('WEB_SEARCH_RETRY_SLEEP_MS', 250),

            // Indicative billed cost of one search query, in micro-units (~$0.003).
            'cost_micros_per_request' => (int) env('WEB_SEARCH_COST_MICROS', 3_000),

            // How many of the shaper's query angles one run may send. Each is a billed request, so
            // this is the ceiling on what a web-search run costs.
            'max_queries' => (int) env('WEB_SEARCH_MAX_QUERIES', 3),

            // Aggregator / social / directory domains that are not a company's own site. Results on
            // these are skipped so distinct companies don't all collapse to one `domain:` lead.
            'ignore_domains' => [
                'linkedin.com', 'facebook.com', 'twitter.com', 'x.com', 'instagram.com',
                'youtube.com', 'tiktok.com', 'pinterest.com', 'yelp.com', 'trustpilot.com',
                'crunchbase.com', 'glassdoor.com', 'indeed.com', 'wikipedia.org', 'amazon.com',
                'google.com', 'bing.com', 'yell.com', 'yellowpages.com', 'maps.google.com',
            ],
        ],

        'ai' => [
            // The most companies one answer may contain. A ceiling on **output tokens**, not on
            // requests: the run's own limit still applies and the job slices to it. No API key of
            // its own — the AI Platform owns the Anthropic credentials (config/ai.php).
            'max_results' => (int) env('AI_LEAD_SOURCE_MAX_RESULTS', 20),

            // How many search results the model is given to pick companies out of (ADR 0035).
            // More than `max_results`, because a good share of any result page is articles and
            // lists it is told to skip. An input-token cost.
            'max_search_results' => (int) env('AI_LEAD_SOURCE_MAX_SEARCH_RESULTS', 30),
        ],

    ],

];
