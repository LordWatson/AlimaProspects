<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Signals configuration (ADR 0011)
|--------------------------------------------------------------------------
|
| Trigger-event prospecting via Serper News. The Serper account is reused
| from Lead Sourcing (ADR 0010) by referencing the same env keys, but Signals
| keeps its own HTTP tuning, cost and circuit-breaker settings so the two
| features can be tuned independently. The query templates are DATA, not code
| — edit them here (and per the S0 spike) without touching the query builder.
| v1 is UAE-only and snippet-only. See docs/modules/signals.md. Verify Serper
| rates against live pricing; never hard-code a key.
|
*/

return [

    'serper' => [
        // Driver (only `serper` is implemented, ADR 0010). Reuses the Lead Sourcing Serper account
        // by referencing the SAME env keys — one Serper key for the whole app.
        'provider' => env('WEB_SEARCH_PROVIDER', 'serper'),
        'api_key' => env('WEB_SEARCH_API_KEY'),
        'base_url' => env('WEB_SEARCH_BASE_URL', 'https://google.serper.dev'),

        // Own HTTP limits (independent of lead-sourcing tuning).
        'timeout' => (int) env('SIGNALS_SERPER_TIMEOUT', 10),
        'connect_timeout' => (int) env('SIGNALS_SERPER_CONNECT_TIMEOUT', 5),
        // HTTP retry on connection errors / 5xx / 429 with linear backoff in ms.
        'retry_times' => (int) env('SIGNALS_SERPER_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('SIGNALS_SERPER_RETRY_SLEEP_MS', 250),
    ],

    // Circuit breaker for the news client (its own, separate from lead-sourcing's).
    'circuit_breaker' => [
        'threshold' => (int) env('SIGNALS_CB_THRESHOLD', 5),
        'cooldown' => (int) env('SIGNALS_CB_COOLDOWN', 60),
    ],

    // `num` sent to /news. Serper caps /news at ~10 items/query regardless (S0 spike), so coverage
    // comes from template breadth, not a bigger num.
    'results_per_query' => (int) env('SIGNALS_RESULTS_PER_QUERY', 20),

    // Serper `tbs` recency window: past week.
    'recency' => env('SIGNALS_RECENCY', 'qdr:w'),

    // Interface language for results (v1: English only).
    'hl' => env('SIGNALS_HL', 'en'),

    // Indicative billed cost of one /news query, in micro-units (~$0.001). Surfaced on
    // signal_runs.cost_micros. Verify against live Serper pricing.
    'cost_micros_per_request' => (int) env('SIGNALS_COST_MICROS', 1_000),

    // How many "Run now" triggers one member may make per minute across all their watches (S5).
    // Each watch is separately throttled to once per 15 minutes in `TriggerSignalWatchAction`;
    // this is the per-member cap, because a run spends real money.
    'run_rate_limit' => (int) env('SIGNALS_RUN_RATE_LIMIT', 10),

    // How many watches one team may have (S6). A watch is a standing instruction to spend money,
    // so the ceiling is on *creating* them, not just on running them — the per-watch run throttle
    // doesn't help against a team with two hundred watches.
    'max_watches_per_team' => (int) env('SIGNALS_MAX_WATCHES_PER_TEAM', 10),

    // AI thresholds applied in S3 (declared here so the whole feature's knobs live together): a kept
    // signal needs is_event && confidence >= min_confidence && relevance >= min_relevance.
    'min_confidence' => (int) env('SIGNALS_MIN_CONFIDENCE', 60),
    'min_relevance' => (int) env('SIGNALS_MIN_RELEVANCE', 40),

    // Per-market search settings. `gl` is the Serper country code; `default_location` seeds a watch
    // when the ICP has no Geography value.
    'markets' => [
        'uae' => [
            'gl' => 'ae',
            'default_location' => 'Dubai',
        ],
    ],

    // Source domains to drop from /news results: property portals (the client's competitors) and
    // generic aggregators/roundup pages that aren't a genuine single event (S0 spike). A per-team
    // ignore list is merged in at S6.
    'ignore_domains' => [
        // UAE property portals / agency sites (competitors, not events).
        'bayut.com', 'propertyfinder.ae', 'dubizzle.com', 'propertyfinder.com',
        // Aggregators / social / roundup / tracker pages.
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com', 'instagram.com',
        'youtube.com', 'tiktok.com', 'reddit.com', 'medium.com',
        'crunchbase.com', 'pitchbook.com', 'tracxn.com',
        'wikipedia.org', 'vogue.com', 'techloy.com',
    ],

    /*
     * Query templates per market and event type (S0 spike — loosened to plain keywords; no
     * exact-phrase quoting and no `-based`, which returned 0 results on /news + qdr:w). `{location}`
     * is substituted from the watch's location. Keep breadth (several per type) rather than one
     * clever query.
     */
    'templates' => [
        'uae' => [
            'acquisition' => [
                '{location} company acquisition',
                'UAE acquisition deal',
                'UAE startup acquired',
            ],
            'funding_round' => [
                '{location} startup raises funding',
                'UAE Series B funding',
                '{location} startup Series A funding round',
            ],
            'relocation' => [
                '{location} founder relocates',
                'family office {location} opens',
            ],
        ],
    ],

    // Retention sweep (implemented in S6): hard-delete dismissed signals after N days and
    // unactioned (still New) signals after N days. Converted signals stay as long as their lead.
    'retention' => [
        'dismissed_days' => (int) env('SIGNALS_RETENTION_DISMISSED_DAYS', 90),
        'unactioned_days' => (int) env('SIGNALS_RETENTION_UNACTIONED_DAYS', 180),
    ],

];
