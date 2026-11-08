<?php

declare(strict_types=1);

use App\Modules\LeadSourcing\Enums\EvidenceKind;

return [

    /*
    |--------------------------------------------------------------------------
    | Source reliability
    |--------------------------------------------------------------------------
    |
    | How much an item of evidence is worth *before* age is taken off it, 0–100, keyed by the
    | evidence row's `source` — the stable storage key of whatever produced it (a `LeadSource`
    | value, a `SignalSourceKind` value, or a key a future provider brings with it).
    |
    | The ordering is the only opinionated part, and it is a claim about authority rather than
    | about usefulness: a statutory register has filed, signed, dated facts; a news article is
    | somebody's account of an event; and the model's own recollection is a lead worth looking at
    | and nothing a team should repeat to a prospect.
    |
    | **A source with no entry here scores `default`, not zero** — a new provider is under-weighted
    | until somebody takes a view, which is the safe direction, and `evidence:source-quality`
    | reports it. `EvidenceReliabilityTest` asserts every shipped source has an entry, so adding
    | one without a weight fails the suite rather than quietly scoring 40 for ever.
    |
    | Captured **onto the row** at the moment the evidence is recorded (`lead_evidence.reliability`),
    | never read back through this file: re-weighting a source must not silently restate what the
    | feed already explained (ADR 0022's rule for `signals.score_breakdown`).
    |
    */

    'reliability' => [
        'default' => (int) env('EVIDENCE_RELIABILITY_DEFAULT', 40),

        // Statutory registers: filed, dated and signed for.
        'companies_house' => (int) env('EVIDENCE_RELIABILITY_COMPANIES_HOUSE', 95),

        // The team's own knowledge. They met them; we did not.
        'manual' => (int) env('EVIDENCE_RELIABILITY_MANUAL', 70),
        'import' => (int) env('EVIDENCE_RELIABILITY_IMPORT', 70),

        // Business directories: a real listing, maintained by whoever claimed it.
        'google_places' => (int) env('EVIDENCE_RELIABILITY_GOOGLE_PLACES', 75),

        // Published journalism, read through Serper's news index.
        'news' => (int) env('EVIDENCE_RELIABILITY_NEWS', 60),

        // A feed the team chose to trust — better than the open web, worse than a register.
        'feed' => (int) env('EVIDENCE_RELIABILITY_FEED', 55),

        // The open web, and a page that merely mentions a company.
        'web_search' => (int) env('EVIDENCE_RELIABILITY_WEB_SEARCH', 45),

        // An event the customer pushed to us. We did not observe it and cannot check it, and ADR
        // 0023 is explicit that an inbound event is never believed about its own importance.
        'webhook' => (int) env('EVIDENCE_RELIABILITY_WEBHOOK', 40),

        // The model's own recollection of the market (ADR 0008's `ai` provider). Worth a look,
        // never worth quoting.
        'ai' => (int) env('EVIDENCE_RELIABILITY_AI', 35),

        // A lead converted from a signal keeps the article's own source key, so `signal` is only
        // reached by a row whose origin was lost — the provenance equivalent of "somewhere".
        'signal' => (int) env('EVIDENCE_RELIABILITY_SIGNAL', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recency
    |--------------------------------------------------------------------------
    |
    | Evidence decays by half-life, per kind, measured from `observed_at` — when the thing
    | happened, not when we found it. A kind's half-life is a statement about how fast that sort of
    | fact stops being a reason to pick up the phone: a funding round three months old is stale
    | news, while "incorporated in 2019" is as true today as it was last year.
    |
    | Decay is applied as `0.5 ^ (age_days / half_life)` and floored at `min_decay`, so an old item
    | thins out but never vanishes — zero would make a lead's history disappear from its own
    | confidence, and a register record from 2015 is still why we believe the company exists.
    |
    */

    'recency_half_life_days' => [
        EvidenceKind::Signal->value => (int) env('EVIDENCE_HALF_LIFE_SIGNAL', 30),
        EvidenceKind::WebResearch->value => (int) env('EVIDENCE_HALF_LIFE_WEB_RESEARCH', 90),
        EvidenceKind::AiInference->value => (int) env('EVIDENCE_HALF_LIFE_AI_INFERENCE', 90),
        EvidenceKind::Firmographic->value => (int) env('EVIDENCE_HALF_LIFE_FIRMOGRAPHIC', 365),
        // A statutory filing is a fact of record. It ages, but slowly.
        EvidenceKind::RegisterRecord->value => (int) env('EVIDENCE_HALF_LIFE_REGISTER_RECORD', 1095),
    ],

    'min_decay' => (float) env('EVIDENCE_MIN_DECAY', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Corroboration
    |--------------------------------------------------------------------------
    |
    | Two independent sources saying the same thing is worth more than one saying it twice, so the
    | bonus counts **distinct sources** behind a lead's evidence, never rows. A provider that
    | returns forty pages about one company therefore earns nothing extra, which is the whole point
    | of counting it this way.
    |
    */

    'corroboration_bonus' => (int) env('EVIDENCE_CORROBORATION_BONUS', 10),
    'corroboration_max' => (int) env('EVIDENCE_CORROBORATION_MAX', 20),

    /*
    |--------------------------------------------------------------------------
    | Confidence threshold
    |--------------------------------------------------------------------------
    |
    | Below this, a lead is hidden from the list by default (`leads.suppressed_low_confidence`) and
    | shown behind "Hidden: N low-confidence leads". A team can move its own line between
    | `min_threshold` and `max_threshold` on the Team settings screen (`teams.min_lead_confidence`);
    | a null there means this default.
    |
    | A lead with **no** confidence at all is never suppressed: null is "not judged yet", not
    | "judged poor", and hiding a lead the queue has not reached yet would make a new discovery run
    | look like it found nothing (ADR 0022's rule, one level out).
    |
    */

    'default_min_confidence' => (int) env('EVIDENCE_MIN_CONFIDENCE', 50),
    'min_threshold' => 30,
    'max_threshold' => 90,

    /*
    |--------------------------------------------------------------------------
    | Confidence bands
    |--------------------------------------------------------------------------
    |
    | Where the pill changes words. Shown as High / Medium / Low and never as a colour on its own:
    | a band is a statement about *our evidence*, not a verdict on the company.
    |
    */

    'bands' => [
        'high' => (int) env('EVIDENCE_BAND_HIGH', 75),
        'medium' => (int) env('EVIDENCE_BAND_MEDIUM', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | "Why now" summary
    |--------------------------------------------------------------------------
    |
    | The one or two sentences at the top of a lead. Generated from the strongest
    | `max_evidence_items` items and **nothing else** — the prompt is given the evidence and told it
    | may use no other fact — and regenerated only when `leads.why_now_evidence_hash` changes, so a
    | lead that is re-scored nightly costs one call rather than three hundred.
    |
    | The model and its tier live on the prompt row (`lead.why_now` in the registry), not here:
    | a prompt is versioned data in this codebase (ADR 0005) and a second place to set its model is
    | a second answer to which model wrote a given summary.
    |
    */

    'why_now' => [
        'max_evidence_items' => (int) env('EVIDENCE_WHY_NOW_MAX_ITEMS', 5),
        'prompt_key' => 'lead.why_now',
        // A summary is a convenience over evidence that is always shown beside it, so a team at
        // its AI ceiling loses the sentence and keeps the facts. Switched off wholesale here.
        'enabled' => (bool) env('EVIDENCE_WHY_NOW_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source quality report
    |--------------------------------------------------------------------------
    |
    | `evidence:source-quality` aggregates members' `wrong` verdicts per source over this window
    | and logs the result for review. It **does not change `reliability`**: a source's weight is a
    | position the business has taken, and a handful of flags is a reason to look rather than a
    | mandate to re-weight (ADR 0022 — a system that learns proposes, it does not apply).
    |
    */

    'source_quality' => [
        'window_days' => (int) env('EVIDENCE_SOURCE_QUALITY_WINDOW_DAYS', 30),
        // Below this many verdicts a rate is noise, so the source is reported but not flagged.
        'min_verdicts' => (int) env('EVIDENCE_SOURCE_QUALITY_MIN_VERDICTS', 10),
        'wrong_rate_alert' => (float) env('EVIDENCE_SOURCE_QUALITY_ALERT_RATE', 0.25),
    ],

];
