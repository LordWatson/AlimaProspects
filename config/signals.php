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

    // Fallback interface language for results. **Per region since S8** (ADR 0019 §5) — this is
    // only the default a region inherits when it declares no `hl` of its own, and nothing reads it
    // directly any more: `SearchRegion::hl()` is the one that decides.
    'hl' => env('SIGNALS_HL', 'en'),

    // Indicative billed cost of one /news query, in micro-units (~$0.001). Surfaced on
    // signal_runs.cost_micros. Verify against live Serper pricing.
    'cost_micros_per_request' => (int) env('SIGNALS_COST_MICROS', 1_000),

    // How many "Run now" triggers one member may make per minute across all their watches (S5).
    // Each watch is separately throttled to once per 15 minutes in `TriggerSignalWatchAction`;
    // this is the per-member cap, because a run spends real money.
    'run_rate_limit' => (int) env('SIGNALS_RUN_RATE_LIMIT', 10),

    // How many signal types one team may have (S7, ADR 0018). Same reasoning as the watch
    // ceiling, one level up: a type is multiplied by every watch that uses it and - from S8 - by
    // every location those watches cover, so each one is a standing instruction to spend money on
    // searches. Platform presets don't count against it; only the team's own rows do. The real
    // plan-driven limit arrives in S16; this is the floor that keeps S7 from shipping unbounded.
    'max_definitions_per_team' => (int) env('SIGNALS_MAX_DEFINITIONS_PER_TEAM', 25),

    // How many watches one team may have (S6). A watch is a standing instruction to spend money,
    // so the ceiling is on *creating* them, not just on running them — the per-watch run throttle
    // doesn't help against a team with two hundred watches.
    'max_watches_per_team' => (int) env('SIGNALS_MAX_WATCHES_PER_TEAM', 10),

    // The most paid searches one run of one watch may make (S7). v1 could not exceed 8: three
    // event types with three, three and two templates. A team can now hold 25 types of up to 10
    // templates each, so the same arithmetic reaches ~250 searches per run per watch — a 30x cost
    // exposure that S7 opened and that nothing else closes, since the per-watch 15-minute throttle
    // and the per-member rate limit both bound how *often* a run happens, not how big one is.
    //
    // The run trims to this ceiling in a deterministic order and **records that it trimmed**, which
    // the watch card shows: a silent trim is a lie about coverage. S8 keeps this ceiling and makes
    // it bite harder, since a watch there multiplies templates by locations.
    'max_queries_per_run' => (int) env('SIGNALS_MAX_QUERIES_PER_RUN', 40),

    /*
     * Watchlists (S10): the accounts a team wants watched by name.
     *
     * **`max_entries` is a cost ceiling, not a form limit.** Every account on a list is a paid
     * search, and a mirrored pipeline grows on its own — so the sync stops adding at this number
     * rather than following a team's pipeline to four figures. `window` is the other half: a run
     * searches this many accounts and the next one continues from where it stopped
     * (`signal_watches.entity_cursor`), so 250 accounts are covered about every six days at forty a
     * day. Trimming to the first forty every day would mean the rest were never searched at all.
     *
     * `window` is deliberately the same order as `max_queries_per_run`: the window decides how many
     * accounts are asked about and that ceiling decides how many searches may result, and a window
     * far larger than the ceiling would just be trimmed back silently.
     */
    'watchlist' => [
        'max_entries' => (int) env('SIGNALS_WATCHLIST_MAX_ENTRIES', 250),
        'window' => (int) env('SIGNALS_WATCHLIST_WINDOW', 40),
        'max_lists_per_team' => (int) env('SIGNALS_WATCHLIST_MAX_LISTS', 5),
    ],

    /*
     * The builder's two AI paths (S9): drafting a definition, and previewing one before it is saved.
     *
     * **Preview is a user-triggered spend loop**, which is the only reason these keys exist. A
     * member pressing "show me what this finds" pays for Serper searches and an AI pass, and a
     * button that spends money on every press needs a ceiling that does not depend on the member
     * being reasonable. Three independent caps, because each one fails differently:
     *
     * - `max_queries` bounds **one** preview, so a definition with ten templates cannot become ten
     *   searches a press. A preview is a sample — the answer to "do these templates return
     *   anything" does not get truer in five cities.
     * - `rate_limit` bounds one **member**, per hour, so iterating on wording stays affordable and
     *   a held-down key does not.
     * - `daily_budget_micros` bounds the **team**, so twelve members each inside their own limit
     *   still cannot spend a month's allowance in an afternoon. Measured over the
     *   `signal_previews` ledger, which is why that table exists: a cost with no row is a cost
     *   nobody can cap.
     *
     * `results_per_query` is deliberately smaller than a run's: a preview shows a handful of
     * headlines so somebody can judge the templates, not a full page nobody will read.
     */
    'preview' => [
        'max_queries' => (int) env('SIGNALS_PREVIEW_MAX_QUERIES', 4),
        'results_per_query' => (int) env('SIGNALS_PREVIEW_RESULTS_PER_QUERY', 10),
        'rate_limit' => (int) env('SIGNALS_PREVIEW_RATE_LIMIT', 10),
        'daily_budget_micros' => (int) env('SIGNALS_PREVIEW_DAILY_BUDGET_MICROS', 500_000),
    ],

    /*
     * The AI composer (S9): a sentence in, a draft definition out.
     *
     * Rate-limited per member for the same reason as the preview — it is an AI call behind a button
     * — and metered through `AiCreditGuard` like every other prompt, so a team's AI allowance is
     * the real ceiling. `max_brief` is the character bound on the member's own description before
     * it reaches the prompt, which is ADR 0017's rule rather than a form limit: the input is
     * untrusted and the budget is spent in characters.
     *
     * **It has no daily team budget of its own, unlike the preview, and the S9 security review was
     * right that this is an asymmetry rather than an oversight.** The AI allowance that bounds it
     * is *monthly*, and on a metered plan the hard stop is the allowance times the overage
     * multiplier — so several members looping the composer could consume a month of a team's AI
     * ceiling in an afternoon and starve insights, drafting and scoring for the rest of it. The
     * rate limit is 10 rather than 20 for that reason, which narrows it without closing it; a
     * per-team daily cap over a composer ledger is S16's cost-governance work, where the other
     * per-team ceilings live.
     */
    'composer' => [
        'rate_limit' => (int) env('SIGNALS_COMPOSER_RATE_LIMIT', 10),
        'max_brief' => (int) env('SIGNALS_COMPOSER_MAX_BRIEF', 1_000),
    ],

    // AI thresholds applied in S3 (declared here so the whole feature's knobs live together): a kept
    // signal needs is_event && confidence >= min_confidence && relevance >= min_relevance.
    'min_confidence' => (int) env('SIGNALS_MIN_CONFIDENCE', 60),
    'min_relevance' => (int) env('SIGNALS_MIN_RELEVANCE', 40),

    /*
     * Hosts that wrap somebody else's article behind a token of their own (S11).
     *
     * Observed in real Serper data: `link = https://google.com/goto?url=<opaque token>` with
     * `source = "Construction Week Online"`. The token is Google's own encoding, so the publisher
     * URL **cannot be recovered locally** — there is nothing to unwrap. That made `url_hash` stop
     * being the article's identity: a token that varies between requests re-admits the same article
     * on every run, at the price of a fresh Serper query and a fresh AI batch each time.
     *
     * For an entry here, `SignalItemNormaliser` uses the **content** identity
     * (`sha1(source name + normalised headline)`) as the dedupe hash instead. An entry is a bare
     * host or a host and a path prefix; the path form is the careful one, because `google.com`
     * also serves plenty that is not a redirect.
     */
    'redirect_hosts' => [
        'google.com/goto',
        'google.com/url',
        'news.google.com',
    ],

    /*
     * Clustering: one event, one card (S11).
     *
     * A Dubai acquisition reported by Gulf News, Khaleej Times, Zawya, Arabian Business and three
     * aggregators was five to eight signals, each costing an AI classification and each needing its
     * own dismiss. The feed's perceived quality was dominated by it.
     *
     * **`cluster_before_ai` is the cost win and the flag is the way back.** With it on, the run
     * groups candidate articles by headline overlap *before* the AI batch and classifies one
     * representative per group, which cuts AI spend roughly in proportion to how well a story
     * travels. With it off the run classifies every article as it did in v1, so a regression is a
     * config flip rather than a rollback. Grouping is deterministic and uses no AI.
     *
     * `title_similarity` is the **Dice** coefficient over the significant words of the two
     * headlines — `2 × shared / (words in A + words in B)`, so roughly "this fraction of the words
     * in common" — and `min_shared_tokens` is the floor underneath it, because a ratio is most
     * confident exactly where it has least evidence.
     *
     * Both are deliberately strict, and the reason is worth knowing: this grouping decides which
     * article the **model** reads, so two different events in one group means one of them is written
     * down as the other. Under-clustering costs an AI call and a duplicate card. The cards are
     * consolidated again after classification, by a subject-based key that cannot mis-merge like
     * that — so the savings depend on these two numbers and the correctness does not.
     */
    'cluster_before_ai' => (bool) env('SIGNALS_CLUSTER_BEFORE_AI', true),

    'clustering' => [
        'title_similarity' => (float) env('SIGNALS_CLUSTER_TITLE_SIMILARITY', 0.6),
        'min_shared_tokens' => (int) env('SIGNALS_CLUSTER_MIN_SHARED_TOKENS', 3),
    ],

    /*
     * Ranking: how much is this event worth to **this** team (S12)?
     *
     * `SignalScorer` is a weighted average of components that are each **stored** beside the
     * total, because the plan's own risk note is the thing to be afraid of here: *a scorer nobody
     * understands is worse than date order*. Every card can show its own breakdown, so a weight
     * can be argued about with evidence rather than taste.
     *
     * Two rules the arithmetic follows, both borrowed from `Scoring\DeterministicScorer` so the
     * two scores in this product behave the same way:
     *
     * - **Weights are normalised, not summed.** The heat is `contributed / assessed`, so changing
     *   one weight cannot inflate every signal, and adding a component later does not re-scale the
     *   whole feed.
     * - **Missing data is not a failure.** A component with nothing to judge — no amount, no
     *   history for the outlet, no ICP — is *unknown*, and its weight leaves **both** sides of the
     *   ratio. Scoring a signal low for being thinly reported would make the heat measure how much
     *   Alima knows rather than how much the event is worth.
     *
     * A definition may override any weight (`signal_definitions.score_weights`); these are the
     * defaults every untuned type uses.
     */
    'scoring' => [
        'weights' => [
            // What the AI thought, which is where v1's whole judgement lived. Still the largest
            // single voice, deliberately: everything else here is a prior about the *event*, and
            // relevance is the only component that read the article.
            'relevance' => (int) env('SIGNALS_WEIGHT_RELEVANCE', 30),
            'confidence' => (int) env('SIGNALS_WEIGHT_CONFIDENCE', 15),
            // The feed already ordered by `published_at` for exactly this reason; this makes it
            // explicit and lets it be traded off against the rest rather than dominating.
            'recency' => (int) env('SIGNALS_WEIGHT_RECENCY', 20),
            'amount' => (int) env('SIGNALS_WEIGHT_AMOUNT', 10),
            'source_authority' => (int) env('SIGNALS_WEIGHT_SOURCE_AUTHORITY', 10),
            'seniority' => (int) env('SIGNALS_WEIGHT_SENIORITY', 5),
            // A story six outlets carried is likelier to be real than one a single aggregator ran.
            'cluster_breadth' => (int) env('SIGNALS_WEIGHT_CLUSTER_BREADTH', 5),
            // Reuses `Scoring`'s criteria match rather than inventing a second fit score.
            'icp_fit' => (int) env('SIGNALS_WEIGHT_ICP_FIT', 15),
        ],

        // An event's value halves every this-many days. Seven is a week: a funding round from
        // last Tuesday is still worth a call and one from last quarter is history, which is the
        // same judgement `signals.recency` (`qdr:w`) already makes about what to search for.
        'recency_half_life_days' => (int) env('SIGNALS_RECENCY_HALF_LIFE_DAYS', 7),

        // Below this many amounts in a team's own history, the amount component abstains rather
        // than ranking against a sample too small to mean anything. Banding against the team's own
        // distribution is the point — a £2m exit matters to one team and not another — and four
        // data points are not a distribution.
        'amount_history_minimum' => (int) env('SIGNALS_AMOUNT_HISTORY_MINIMUM', 5),

        // Below this many outcomes for a `(source, type)` pair, source authority abstains. Same
        // reasoning: a source the team has seen twice has not earned a reputation either way.
        'source_history_minimum' => (int) env('SIGNALS_SOURCE_HISTORY_MINIMUM', 5),

        // How many outlets count as "widely reported" for the breadth component. Beyond this the
        // component is simply full: the difference between six outlets and sixteen says more about
        // syndication than about the event.
        'breadth_saturation' => (int) env('SIGNALS_BREADTH_SATURATION', 6),
    ],

    /*
     * Tuning suggestions (S12): proposed, never applied.
     *
     * The advisor reads `signal_source_stats`, `signal_rejections` and the dismiss reasons and
     * writes `signal_tuning_suggestions` rows a member accepts or declines. **Nothing
     * auto-applies** — a feature that silently retunes itself is a feature nobody can debug, and
     * the dismiss reason has been documented since S4 as being kept "for tuning, not for display".
     *
     * The thresholds are what stop it being a nag: `min_observations` is how much evidence a
     * proposal needs before it is worth a member's attention, and `min_rate` is how one-sided that
     * evidence has to be.
     */
    'tuning' => [
        'min_observations' => (int) env('SIGNALS_TUNING_MIN_OBSERVATIONS', 8),
        'min_rate' => (float) env('SIGNALS_TUNING_MIN_RATE', 0.7),
        // Never show a member more than this many at once. A panel of thirty suggestions is a
        // backlog, and a backlog is ignored.
        'max_open' => (int) env('SIGNALS_TUNING_MAX_OPEN', 5),
    ],

    /*
     * Full-article fetch for the top of the feed (S12, and the oldest item in the v1 backlog).
     *
     * Snippet-only extraction is why amounts and names are best-effort. For a signal the scorer
     * already rates highly it is worth reading the article properly — through the **shared**
     * `Core\Contracts\WebContentFetcher` (the SSRF-safe seam Enrichment and Business Intelligence
     * already use; do not add an HTTP client) and re-extracting the amount, the names and the role
     * from the full text.
     *
     * **Off by default**, and four things bound it when it is on: the heat threshold (only the top
     * of the feed is worth the money), a per-team monthly ceiling in micro-units, the circuit
     * breaker, and `robots.txt`. It stores the extracted **fields** and a timestamp and **never the
     * article body** — retention and DSAR both get harder the moment we keep prose.
     */
    'article_fetch' => [
        'enabled' => (bool) env('SIGNALS_ARTICLE_FETCH_ENABLED', false),
        // Only the signals a member is actually going to read.
        'min_heat' => (int) env('SIGNALS_ARTICLE_FETCH_MIN_HEAT', 70),
        // Articles per run, so one busy news day cannot become a hundred page fetches.
        'max_per_run' => (int) env('SIGNALS_ARTICLE_FETCH_MAX_PER_RUN', 5),
        // Decision #4 of this plan: yes, a cost cap per team per month, default off (zero means
        // uncapped **only** because the feature itself is off by default; turning the feature on
        // without setting this is the mistake, and `security:posture` is where that check belongs).
        'monthly_cap_micros' => (int) env('SIGNALS_ARTICLE_FETCH_MONTHLY_CAP_MICROS', 0),
        // Indicative cost of one fetch plus its re-extraction, in micro-units. Metered onto the
        // run's `cost_micros` like every other spend this module makes.
        'cost_micros_per_fetch' => (int) env('SIGNALS_ARTICLE_FETCH_COST_MICROS', 2_000),
        // How long a host's `robots.txt` is trusted for. A day: long enough that one news day is
        // one fetch per publisher, short enough that a publisher who changes their mind is
        // honoured within one.
        'robots_ttl' => (int) env('SIGNALS_ARTICLE_FETCH_ROBOTS_TTL', 86_400),
        // How long a host whose `robots.txt` could **not be read** stays denied. Minutes, not a
        // day: RFC 9309 says to treat a server error as disallow-all, and a publisher having a bad
        // hour should not cost us a day's reading once they recover.
        'robots_error_ttl' => (int) env('SIGNALS_ARTICLE_FETCH_ROBOTS_ERROR_TTL', 300),
    ],

    /*
     * Remembering what was rejected (S11), which closes the S3 trade-off (b).
     *
     * Non-event and below-threshold items were forgotten, so every run re-paid to classify the same
     * weekly round-ups. A rejection is remembered by `(team, dedupe hash, the set of signal types
     * that were offered)` and skipped inside the window — the type set matters, because a team that
     * adds a type has asked a different question about the same article and deserves a fresh answer.
     *
     * Short retention on purpose: it holds hashes, a verdict and counts, no article text and no
     * name, which is what makes it cheap to keep and uninteresting to a subject access request.
     */
    'rejections' => [
        'enabled' => (bool) env('SIGNALS_REMEMBER_REJECTIONS', true),
        'retention_days' => (int) env('SIGNALS_REJECTION_RETENTION_DAYS', 30),
    ],

    /*
     * Per-market search settings.
     *
     * **Superseded by `regions` below (S8, ADR 0019)** and kept only because
     * `SignalWatchData::defaultWatch()` still seeds a watch's first place from
     * `markets.<market>.default_location`. `gl` is no longer read from here at all — a watch picks
     * `SearchRegion`s and each one carries its own `gl`, because "where do we look" and "which rules
     * apply" turned out to be different questions that one column was answering.
     */
    'markets' => [
        'uae' => [
            'gl' => 'ae',
            'default_location' => 'Dubai',
        ],
        'uk' => [
            'gl' => 'gb',
            'default_location' => 'London',
        ],
    ],

    /*
     * Where a watch may look (S8, ADR 0019). Keyed by `SearchRegion` value, which is also the
     * Serper `gl` code.
     *
     * **`offered` is a compliance switch, not a feature flag.** The enum knows about seven regions
     * because `gl`/`hl` carry no legal content, but choosing a region also chooses how the leads it
     * produces may be approached — and the app holds a position on exactly two jurisdictions
     * (ADR 0007: UK legitimate interest, UAE PDPL prior consent). A region with no position
     * contributes nothing to a lead's outreach restriction, so offering one would mean shipping
     * leads nobody has decided the rules for. Turning one on is a one-line change here **and** a
     * question for counsel — see `docs/compliance.md` §5.
     *
     * `hl` is the Serper interface language for results from the region. `ae` stays `en` until
     * somebody writes Arabic query templates: a machine-translated search query finds nothing and
     * looks like it works (ADR 0019 §5).
     */
    'regions' => [
        'ae' => [
            'offered' => (bool) env('SIGNALS_REGION_AE', true),
            'hl' => env('SIGNALS_REGION_AE_HL', 'en'),
            'default_locations' => ['Dubai', 'Abu Dhabi'],
        ],
        'gb' => [
            'offered' => (bool) env('SIGNALS_REGION_GB', true),
            'hl' => 'en',
            'default_locations' => ['London'],
        ],
        // ⚖️ No compliance position recorded. Ready in code, not offered to a team.
        'sa' => ['offered' => (bool) env('SIGNALS_REGION_SA', false), 'hl' => 'en', 'default_locations' => ['Riyadh']],
        'us' => ['offered' => (bool) env('SIGNALS_REGION_US', false), 'hl' => 'en', 'default_locations' => ['New York']],
        'in' => ['offered' => (bool) env('SIGNALS_REGION_IN', false), 'hl' => 'en', 'default_locations' => ['Mumbai']],
        'sg' => ['offered' => (bool) env('SIGNALS_REGION_SG', false), 'hl' => 'en', 'default_locations' => ['Singapore']],
        'global' => ['offered' => (bool) env('SIGNALS_REGION_GLOBAL', false), 'hl' => 'en', 'default_locations' => []],
    ],

    // How many places one watch may cover, and how many regions (S8). The product of templates and
    // locations is what a run pays for, so these two and `max_queries_per_run` are one budget seen
    // from three sides.
    'max_locations_per_watch' => (int) env('SIGNALS_MAX_LOCATIONS_PER_WATCH', 10),
    'max_regions_per_watch' => (int) env('SIGNALS_MAX_REGIONS_PER_WATCH', 5),

    /*
     * Free-text place → ISO-3166-1 alpha-2, for `SignalJurisdictionResolver` (S8, ADR 0019).
     *
     * This map is the input to a **legal** rule: it decides which outreach restriction a converted
     * lead carries, so it is deterministic data rather than anything inferred, and a place it does
     * not know resolves to **null** — which fails toward the restriction rather than away from it.
     *
     * Matching is case-insensitive and whole-token, so "Dubai" matches "Dubai, UAE" and
     * "Dubai-based" without "Mumbai" matching "umbai". Longer keys are tried first, so
     * "New South Wales" cannot be decided by "Wales".
     *
     * Adding a place is a config line. Keep the aliases a newspaper would actually print.
     */
    'jurisdictions' => [
        'AE' => [
            'united arab emirates', 'uae', 'u.a.e.', 'emirates',
            'dubai', 'dxb', 'abu dhabi', 'sharjah', 'ajman', 'fujairah',
            'ras al khaimah', 'umm al quwain', 'difc', 'adgm', 'dmcc', 'jebel ali',
        ],
        'GB' => [
            'united kingdom', 'uk', 'u.k.', 'great britain', 'britain',
            'england', 'scotland', 'wales', 'northern ireland',
            'london', 'manchester', 'birmingham', 'leeds', 'glasgow', 'edinburgh',
            'bristol', 'liverpool', 'cardiff', 'belfast', 'cambridge', 'oxford',
            'the city of london', 'canary wharf',
        ],
        'SA' => ['saudi arabia', 'saudi', 'ksa', 'riyadh', 'jeddah', 'dammam', 'neom'],
        'US' => [
            'united states', 'usa', 'u.s.', 'u.s.a.', 'america',
            'new york', 'nyc', 'san francisco', 'california', 'texas', 'boston', 'chicago',
        ],
        'IN' => ['india', 'mumbai', 'bengaluru', 'bangalore', 'delhi', 'new delhi', 'hyderabad', 'chennai', 'pune'],
        'SG' => ['singapore'],
        'QA' => ['qatar', 'doha'],
        'KW' => ['kuwait'],
        'BH' => ['bahrain', 'manama'],
        'OM' => ['oman', 'muscat'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal sources (S13, ADR 0023)
    |--------------------------------------------------------------------------
    |
    | Per-source settings, keyed by `SignalSourceKind`. A source's *code* is not
    | configurable — a key with no provider behind it is a bug, which is what
    | `SignalSourceKind::isAvailable()` exists to say — but its price, its own
    | skip list and its result count are.
    |
    | `cost_micros_per_request` falls back to the module-wide
    | `signals.cost_micros_per_request` so the news figure keeps its existing
    | meaning and its existing env key. A free source configures 0.
    |
    | `ignore_domains` here is merged on top of the global list **for that
    | source only**, because the two lists answer different questions: the
    | global one names publishers nobody wants, and a source's own names a
    | shape of page only that source returns.
    |
    */
    'sources' => [

        'news' => [
            'cost_micros_per_request' => (int) env('SIGNALS_NEWS_COST_MICROS', 1_000),
            // Nothing of its own: the global list was written for /news, and everything on it
            // applies here.
            'ignore_domains' => [],
        ],

        'web_search' => [
            'cost_micros_per_request' => (int) env('SIGNALS_WEB_SEARCH_COST_MICROS', 1_000),

            /*
             * What the global list misses on /search (S13a spike).
             *
             * The spike's dominant noise was social, and the global list already drops Facebook,
             * Instagram, LinkedIn, Reddit, X, YouTube, TikTok and Medium. What it does not drop is
             * these two shapes, which only this endpoint returns:
             *
             * 1. **Job aggregators.** `/search` answers almost any "company hiring X" query with
             *    board index pages ("11 Operations manager jobs in London"). A vacancy listing is
             *    not a trigger event — see the dropped job-posting source in docs/modules/signals.md.
             * 2. **Press-release wires and Q&A/forum sites**, which republish and discuss rather
             *    than report, so an event arrives here as a duplicate of the article the news
             *    source already found, attributed to the wire.
             *
             * Informational and service pages (a council's "how planning decisions are made", a
             * portal's "register a project" form) are deliberately **not** listed: they live on the
             * same hosts as the genuine register entries we want, so the relevance floor is what
             * has to reject them, not a host list.
             */
            'ignore_domains' => [
                // Job boards and aggregators. Subdomains need no entry of their own — the matching
                // is host-or-parent, so `indeed.com` covers `uk.indeed.com` — but a separate
                // registrable domain does, which is why the `.co.uk` siblings are listed.
                'indeed.com', 'indeed.co.uk', 'glassdoor.com', 'glassdoor.co.uk', 'totaljobs.com',
                'reed.co.uk', 'cv-library.co.uk', 'monster.com', 'monster.co.uk', 'ziprecruiter.com',
                'builtin.com', 'ashbyhq.com', 'lever.co', 'greenhouse.io', 'workable.com',
                'myworkdayjobs.com', 'bayt.com', 'gulftalent.com', 'naukrigulf.com',
                'charityjob.co.uk', 'jobserve.com', 'adzuna.co.uk',
                // Press-release wires: a republication of something the news source already has.
                'prurgent.com', 'prnewswire.com', 'einpresswire.com', 'openpr.com', 'pressat.co.uk',
                // Q&A, forums and scrapers.
                'quora.com', 'pinterest.com', 'slideshare.net', 'scribd.com', 'issuu.com',
            ],
        ],

        /*
         * Declared, not offered (S13b). Present so the shape of the config is the shape of the
         * enum and the next phase adds values rather than a section.
         */
        'companies_house' => [
            // Free: the credential is reused from Lead Sourcing (ADR 0008) and the API is not metered.
            'cost_micros_per_request' => 0,
            'ignore_domains' => [],
        ],

        'feed' => [
            'cost_micros_per_request' => 0,
            'ignore_domains' => [],
        ],

        'webhook' => [
            // A pushed event costs nothing to receive. What it costs is the AI that classifies it,
            // which is metered where every other AI call is.
            'cost_micros_per_request' => 0,
            'ignore_domains' => [],
        ],
    ],

    // Source domains to drop from /news results: property portals (the client's competitors) and
    // generic aggregators/roundup pages that aren't a genuine single event (S0 spike). A per-team
    // ignore list is merged in at S6, and a per-**source** list at S13 (`signals.sources.*`).
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
     *
     * **SEED DATA ONLY since S7.** `SignalQueryBuilder` no longer reads this: a signal type is a
     * row a team owns, and its templates live on that row (ADR 0018). These are the templates the
     * three platform presets were created with, kept here as the record of where they came from and
     * because `SignalPresetCatalogue` is checked against them. Editing them changes **nothing** for
     * any existing team — change a preset in that catalogue and re-run `SignalPresetSeeder`, or
     * edit the team's own type on /signals/types.
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
