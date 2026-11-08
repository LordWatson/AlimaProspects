<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enrichment & Data Quality configuration
|--------------------------------------------------------------------------
|
| Settings for the enrichment pipeline (Phase 8): the free-tier email check
| and how many contacts a single lead may keep. No paid enrichment provider
| is used in the MVP (ADR 0008) — facts come from AI extraction of public web
| text, and emails are checked by syntax + disposable-domain + MX/DNS only.
| See docs/modules/enrichment-data-quality.md.
|
*/

return [

    // **How ready a lead is to be contacted (Q4).** A second figure beside the fit score,
    // answering the other question: the score says whether we should want them and is
    // deliberately blind to missing data, which is why an A-grade lead can have no website, no
    // contact and no routable email. Readiness is a measure of what we hold, so it is the one
    // figure allowed to punish a gap.
    //
    // Weights are relative, not percentages: `LeadReadiness` normalises by the total of the ones
    // in play, so adding a key cannot inflate a lead and setting one to **0** switches that
    // factor off entirely. The shape of the defaults is the deliberate part — the three contact
    // factors together outweigh the four company ones, because a complete company record you
    // cannot write to is not a lead you can work.
    'readiness_weights' => [
        'website' => (int) env('ENRICHMENT_READINESS_WEIGHT_WEBSITE', 10),
        'industry' => (int) env('ENRICHMENT_READINESS_WEIGHT_INDUSTRY', 10),
        'company_size' => (int) env('ENRICHMENT_READINESS_WEIGHT_COMPANY_SIZE', 5),
        'location' => (int) env('ENRICHMENT_READINESS_WEIGHT_LOCATION', 10),
        'contact' => (int) env('ENRICHMENT_READINESS_WEIGHT_CONTACT', 25),
        // Routable, never proven deliverable — `EmailVerificationStatus::confidence()` tops out
        // at 60 for the same reason, and there is no SMTP probe in the MVP (ADR 0008).
        'routable_email' => (int) env('ENRICHMENT_READINESS_WEIGHT_ROUTABLE_EMAIL', 25),
        // `info@` is a letterbox; a named mailbox is somebody who can answer.
        'named_contact' => (int) env('ENRICHMENT_READINESS_WEIGHT_NAMED_CONTACT', 15),
    ],

    // Cap the number of contacts persisted per lead, so a runaway extraction can't flood a lead.
    'max_contacts_per_lead' => (int) env('ENRICHMENT_MAX_CONTACTS', 10),

    // Local-parts that mark a role/business mailbox rather than a personal identity. A role address
    // is preferred for outreach; a non-role one is flagged as personal for compliance.
    'role_local_parts' => [
        'info', 'sales', 'contact', 'contactus', 'hello', 'hi', 'team', 'office', 'admin',
        'support', 'help', 'helpdesk', 'enquiries', 'enquiry', 'inquiries', 'inquiry', 'mail',
        'marketing', 'press', 'media', 'pr', 'careers', 'jobs', 'recruitment', 'hr', 'billing',
        'accounts', 'finance', 'orders', 'bookings', 'reservations', 'noreply', 'no-reply', 'donotreply',
    ],

    // Known throwaway / disposable-mail domains. Addresses on these get the lowest confidence and are
    // never treated as a real business contact. Kept deliberately small; extend as needed.
    'disposable_domains' => [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'throwaway.email', 'yopmail.com', 'getnada.com',
        'trashmail.com', 'sharklasers.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com',
        'mailnesia.com', 'mohmal.com', 'spam4.me', 'tmpmail.org', 'moakt.com', 'mintemail.com',
    ],

    // Shared hosts that are NOT a single company's own site: free-mail providers plus social /
    // directory / link-aggregator domains. De-duplication never treats a website domain or a contact
    // email on one of these as an identity signal, so unrelated companies that merely share a
    // social page or a gmail address are not wrongly merged.
    'blocked_dedupe_domains' => [
        // Free / consumer mail.
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com',
        'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        'zoho.com', 'yandex.com', 'qq.com',
        // Social / directory / aggregator / site builders.
        'facebook.com', 'linkedin.com', 'twitter.com', 'x.com', 'instagram.com', 'youtube.com',
        'tiktok.com', 'pinterest.com', 'yelp.com', 'trustpilot.com', 'crunchbase.com',
        'glassdoor.com', 'indeed.com', 'wikipedia.org', 'amazon.com', 'google.com', 'bing.com',
        'yell.com', 'yellowpages.com', 'maps.google.com', 'linktr.ee', 'wixsite.com', 'wix.com',
        'squarespace.com', 'godaddysites.com', 'wordpress.com', 'blogspot.com', 'weebly.com',
    ],

    // --- Website resolution: unlock enrichment for website-less leads --------------------------------
    //
    // Enrichment hangs entirely off a website — no site, no fact extraction, no contacts — yet a
    // Companies House record never carries one and a Google Places listing often doesn't. Before
    // giving up, enrichment asks the web-search provider (Serper, ADR 0010) for the company's likely
    // homepage. A resolved site is NEVER trusted on the search alone: the page is fetched and the
    // company it describes must match the lead's name (distinctive-token overlap at or above
    // `resolve_match_threshold`), or the site is discarded rather than pinned to the wrong company —
    // the direction S10 warned to fail in. Switch the whole step off with `resolve_websites=false`.
    'resolve_websites' => (bool) env('ENRICHMENT_RESOLVE_WEBSITES', true),
    'resolve_match_threshold' => (float) env('ENRICHMENT_RESOLVE_MATCH_THRESHOLD', 0.5),

    // --- Multi-page fetch: find the contacts that live off the homepage -----------------------------
    //
    // A homepage rarely states an email; a contact/about page usually does. Enrichment gathers text
    // from the homepage plus these conventional paths (relative to the site root) and extracts over
    // the combined, capped text in ONE AI call — the fetches are free, the model call is not. Pages
    // are tried in order up to `max_pages_per_lead` (the homepage counts as one), and the combined
    // text is clamped to `max_combined_chars` so the prompt can't bloat.
    'contact_paths' => [
        '/contact', '/contact-us', '/contacts', '/about', '/about-us', '/team',
    ],
    'max_pages_per_lead' => (int) env('ENRICHMENT_MAX_PAGES', 4),
    'max_combined_chars' => (int) env('ENRICHMENT_MAX_COMBINED_CHARS', 18_000),

    // --- Inferred contacts: a name on the page, but no email ----------------------------------------
    //
    // When a page names people but states no routable email, enrichment infers ONE candidate address
    // per person from the company domain using the pattern below (`{first}`, `{last}`, `{f}`, `{l}`
    // tokens), keeps only those whose domain accepts mail (MX), and stores them flagged `is_inferred`
    // with a confidence capped at `inferred_email_confidence`. An inferred address is a GUESS — the
    // MVP has no SMTP probe (ADR 0008), so it cannot be told apart from any other pattern on the same
    // domain — so it counts toward nothing in a lead's readiness and is flagged personal for
    // compliance (whether it may be contacted is still OutreachGuard's call). Off with
    // `infer_emails=false` or a zero `max_inferred_contacts`.
    'infer_emails' => (bool) env('ENRICHMENT_INFER_EMAILS', true),
    'inferred_email_pattern' => (string) env('ENRICHMENT_INFERRED_EMAIL_PATTERN', '{first}.{last}'),
    'max_inferred_contacts' => (int) env('ENRICHMENT_MAX_INFERRED_CONTACTS', 3),
    'inferred_email_confidence' => (int) env('ENRICHMENT_INFERRED_EMAIL_CONFIDENCE', 25),

    /*
    |--------------------------------------------------------------------------
    | Paid enrichment providers (PL2, ADR 0027)
    |--------------------------------------------------------------------------
    |
    | One entry per vendor, keyed by the provider's own `key()`. A vendor with `enabled => false`
    | or no API key removes itself from every waterfall (`EnrichmentProvider::isEnabled()`), so
    | switching one off is a `.env` change rather than a deploy — and an unconfigured vendor is
    | *absent* from the waterfall rather than an exception inside it.
    |
    | **`cost_micros` is a contract term, not a guess.** PL4's margin guard and the "about 2
    | credits" a member is shown before a reveal both read it, which is why it lives here and is
    | declared per provider rather than averaged somewhere. Micros of the billing currency
    | (ADR 0026 §3) — never pence, never millicredits.
    |
    | **Everything here is off until ADR 0027 §2 is completed.** No vendor may be enabled without
    | written confirmation that embedding its data in a product our customers use is permitted; the
    | one adapter that ships is marked experimental and has never made a live call.
    |
    */

    // The breaker for **vendor** HTTP, keyed apart from Lead Sourcing's: a verifier being down and
    // Companies House being down are not the same event, and a shared breaker would make one close
    // the other's door (ADR 0023's per-source rule).
    'circuit_breaker' => [
        'threshold' => (int) env('ENRICHMENT_BREAKER_THRESHOLD', 5),
        'cooldown' => (int) env('ENRICHMENT_BREAKER_COOLDOWN', 60),
    ],

    'providers' => [

        // The reference verifier. **Experimental**: written from ZeroBounce's published v2 docs,
        // tested against our own `Http::fake()` fixtures, and never validated against the live
        // API. It exists so PL3's waterfall is built against a real response shape rather than
        // against four fakes — see the adapter's own note.
        'zerobounce' => [
            'enabled' => (bool) env('ENRICHMENT_ZEROBOUNCE_ENABLED', false),
            'api_key' => env('ENRICHMENT_ZEROBOUNCE_KEY'),
            'endpoint' => env('ENRICHMENT_ZEROBOUNCE_ENDPOINT', 'https://api.zerobounce.net/v2/validate'),
            'timeout' => (int) env('ENRICHMENT_ZEROBOUNCE_TIMEOUT', 10),
            // A verifier bills per lookup, hit or miss. Left at zero until a contract says
            // otherwise: a made-up price would be worse than none, because PL4 would believe it.
            'cost_micros' => (int) env('ENRICHMENT_ZEROBOUNCE_COST_MICROS', 0),
            // Requests per minute we allow ourselves. ZeroBounce's own documented ceiling is
            // vastly higher (80,000 per 10 seconds); this is our brake, not theirs, and the reason
            // it exists is that hitting a vendor's limit is a ban rather than a queue.
            'rate_limit' => (int) env('ENRICHMENT_ZEROBOUNCE_RATE_LIMIT', 600),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Waterfalls
    |--------------------------------------------------------------------------
    |
    | The order to try providers in, per capability. **Order is a commercial decision** — cheapest
    | or highest hit-rate first, depending on the capability — so it lives here rather than in the
    | order somebody happened to register classes in.
    |
    | A key naming a provider nobody registered is ignored at runtime and reported by
    | `EnrichmentProviderRegistry::unknownIn()`, which a test asserts is empty: one typo must not
    | take a whole capability down, and it must not sit there unnoticed either.
    |
    | Three of the four are empty because PL2 ships no adapter for them. That is ADR 0027's
    | discipline applied to our own code: an email **finder**'s response fields could not be
    | confirmed from any vendor's own documentation, and a reference adapter built on a guessed
    | shape is worse than none because it looks finished.
    |
    */

    'waterfall' => [
        'people_find' => [],
        'email_find' => [],
        'email_verify' => array_values(array_filter(explode(',', (string) env('ENRICHMENT_WATERFALL_EMAIL_VERIFY', 'zerobounce')))),
        'phone_find' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | When to stop, and when to go again
    |--------------------------------------------------------------------------
    |
    | `stop_on` is the outcome good enough to stop paying the next provider in a waterfall. For an
    | email that is a verdict at or above the named status; for a phone it is simply finding one.
    |
    | **`accept_all` is deliberately not the default stop.** A catch-all domain cannot disprove an
    | address, which reads as a pass and is not one — a team that stopped there would be paying for
    | confidence it had not bought. Teams that would rather stop early can set it.
    |
    | `reenrich_after_days` is how long a contact's answers are trusted before it is worth paying
    | again. Thirty days is the plan's figure and it is also roughly how long a person stays in a
    | job's worth of certainty.
    |
    */

    'stop_on' => [
        'email_verify' => (string) env('ENRICHMENT_STOP_ON_EMAIL', 'verified'),
    ],

    'reenrich_after_days' => (int) env('ENRICHMENT_REENRICH_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | The waterfall's own limits (PL3)
    |--------------------------------------------------------------------------
    |
    | `max_people_per_run` is the single most important figure here: a people-finder bills for what
    | it returns, so "who works at Acme" with no limit is a bill for forty records to reach the two
    | a member would have called. PL4 narrows it per plan.
    |
    | `max_bulk_leads` bounds what one click can spend. A bulk request over more than this is
    | **refused**, not truncated: a member who selected four hundred leads and got eighty enriched
    | would have no way of knowing which eighty.
    |
    */

    'max_people_per_run' => (int) env('ENRICHMENT_MAX_PEOPLE_PER_RUN', 3),
    'max_bulk_leads' => (int) env('ENRICHMENT_MAX_BULK_LEADS', 50),

    // How many enrichment or reveal requests one **member** may make a minute. The team's
    // allowance bounds a month's spend; this bounds a loop, which is a different problem.
    'rate_limit_per_member' => (int) env('ENRICHMENT_RATE_LIMIT_PER_MEMBER', 30),

];
