// Signals (trigger-event prospecting) shared types — ADR 0011, phases S4–S7.

/**
 * A signal definition's stable key — `funding_round`, `hiring_surge`, whatever a team called theirs.
 *
 * A plain string since S7 (ADR 0018), where it used to be a union of three literals: a signal type
 * is a row a team owns, so the set is not knowable at build time. Aliased rather than written as
 * `string` everywhere so the intent still reads at every call site.
 */
export type SignalEventKey = string;

/** How the signal-type library groups itself. A closed set — the UI renders a section per case. */
export type SignalCategory =
    | 'liquidity'
    | 'growth'
    | 'movement'
    | 'distress'
    | 'hiring'
    | 'regulatory'
    | 'custom';

/**
 * The icon a signal definition wears.
 *
 * An allowlist rather than a free string (ADR 0018 §8), because the client maps it to an imported
 * component — `components/signals/SignalTypeIcon.vue` holds the one exhaustive map, and `vue-tsc`
 * fails the build if a case is added here and forgotten there.
 */
export type SignalIcon =
    | 'satellite'
    | 'handshake'
    | 'trending-up'
    | 'plane'
    | 'building-2'
    | 'users'
    | 'file-text'
    | 'triangle-alert'
    | 'landmark'
    | 'trophy';

/**
 * One signal type: what Alima looks for, and the rules it looks by.
 *
 * Two of these matter when rendering. **`is_preset`** says this row belongs to Alima's library
 * rather than to the team, so it is read-only until cloned. **`translatable`** says whose words the
 * `name` and `description` are: ours (pass them through `t()`) or the team's own, in whichever
 * language they wrote them — translating those would be the app overwriting what somebody typed.
 */
export type SignalDefinition = {
    id: number;
    key: SignalEventKey;
    /** The team's own words unless `translatable` is true. */
    name: string;
    description: string;
    category: SignalCategory;
    /** Translation key, not a finished string. */
    category_label: string;
    icon: SignalIcon;
    is_active: boolean;
    /** Alima's library, not the team's — read-only until cloned. */
    is_preset: boolean;
    /** Whether `name` and `description` are ours to translate. */
    translatable: boolean;
    /** Plain-keyword search templates; `{location}` is substituted per watch. */
    query_templates: string[];
    /** Lower-case fragments that map an ICP buying signal onto this type. */
    match_keywords: string[];
    /** What counts as this kind of event, for the classifier. */
    includes: string[];
    /** What does not — the half that does the work. */
    excludes: string[];
    /** The team's own floor, or null to use the product's. */
    min_confidence: number | null;
    min_relevance: number | null;
    /** The floor that actually applies, after the null fallback. */
    effective_min_confidence: number;
    effective_min_relevance: number;
    suggested_approach_hint: string | null;
    /** Market codes this type is worth running in, or null for any. */
    markets: string[] | null;
    /** Which library preset this was cloned from, and at which version. */
    preset_key: SignalEventKey | null;
    preset_version: number | null;
    /**
     * The vertical bundle the library browses this under, or null for a type nobody shipped.
     *
     * Resolved from the catalogue by key on the server, never stored on the row — a pack is how the
     * platform organises its own presets, not a property of a team's type (S9).
     */
    pack: SignalPresetPack | null;
    /** Whether the live spike found this preset thin: a real event that is simply rare (S9). */
    is_thin: boolean;
    version: number;
    /** How many watches use it — what removing it would cost. Present when the server counted. */
    watches_count?: number;
    /** How many signals it has found — the only honest measure of whether it earns its keep. */
    signals_count?: number;
    created_at?: string | null;
};

export type SignalStatus = 'new' | 'dismissed' | 'converted';

export type SignalDismissReason =
    | 'not_relevant'
    | 'already_known'
    | 'not_an_event';

export type SignalWatchCadence = 'daily' | 'weekly';

export type SignalRunStatus = 'queued' | 'running' | 'completed' | 'failed';

/**
 * One wealth/liquidity event, as the feed card reads it.
 *
 * Note what is **not** here, and never will be: an email or a phone number. A signal stores a
 * person's name and public role only (ADR 0011, data minimisation), so the next step is LinkedIn
 * or a referral — see `suggested_approach`.
 *
 * `*_label` fields are translation keys, not finished strings: the feed is EN/AR (ADR 0007).
 */
export type Signal = {
    id: number;
    /** The signal type's key — copied onto the row, so it survives the type being deleted. */
    event_key: SignalEventKey;
    /** The type's name, or a humanised key where the definition has been deleted. */
    event_label: string;
    event_description: string | null;
    /** Whether `event_label` is Alima's wording (pass through `t()`) or the team's own. */
    event_label_translatable: boolean;
    /** The definition that classified it, or null once that type has been removed. */
    signal_definition_id: number | null;
    status: SignalStatus;
    /** Translation key, not a finished string. */
    status_label: string;
    dismiss_reason: SignalDismissReason | null;
    /** Translation key, not a finished string. */
    dismiss_reason_label: string | null;
    headline: string;
    summary: string;
    subject_person_name: string | null;
    subject_person_role: string | null;
    subject_company_name: string | null;
    counterparty_name: string | null;
    /** Integer minor units, formatted on the client (CLAUDE.md money rule). */
    amount_minor: number | null;
    /** ISO 4217, e.g. `AED`. */
    currency: string | null;
    location: string | null;
    /**
     * Where the subject is, as ISO-3166-1 alpha-2, resolved deterministically at extraction.
     *
     * Null when the article named nowhere Alima recognises — which does **not** mean unrestricted:
     * conversion falls back to the watch and then to the strictest of its regions (ADR 0019).
     */
    jurisdiction: string | null;
    /** The compliance regime that follows from it, or null where none is recorded. */
    resolved_market: string | null;
    /** 0–100: how sure the AI is this is a real, correctly-classified event. */
    confidence: number;
    /** 0–100: how likely this person is a high-net-worth prospect for this team. */
    relevance: number;
    suggested_approach: string | null;
    source_url: string;
    source_name: string | null;
    published_at: string | null;
    actioned_at: string | null;
    /**
     * Whole days until the retention sweep deletes this signal, or null when it won't (a converted
     * signal is kept as long as its lead).
     */
    deletes_in_days: number | null;
    /** The watch that found it, when the server loaded it. */
    watch?: { id: number; name: string } | null;
    /**
     * Where a converted signal went. Null on an unconverted signal — and null on a converted one
     * whose lead has since been deleted, which the card reads as "the lead is gone".
     */
    lead?: { id: number; company_name: string } | null;
    /**
     * The lead this event is **about** (S10), as opposed to the one it was converted into.
     *
     * Present whenever the matcher recognised the company, which is what makes the card offer
     * "add this to Acme Ltd" instead of "convert". The confidence travels with it because the
     * member is owed it: the way account monitoring goes wrong is an event pinned to the wrong
     * company, so the weaker match says so and "convert anyway" stays a visible choice.
     */
    matched_lead: {
        id: number;
        company_name: string | null;
        confidence: SignalMatchConfidence | null;
        /** Translation keys, not finished strings. */
        confidence_label: string | null;
        confidence_explanation: string | null;
        needs_confirmation: boolean;
    } | null;
    /**
     * The other outlets that reported this same event (S11).
     *
     * Null when the card stands alone, which covers both an unclustered signal and a cluster of
     * one — "reported by 1 source" would be noise on most of the feed. The members carry a link, an
     * outlet and a date and nothing else: the summary, the subject and the scores belong to the
     * event, which is this card.
     */
    cluster: SignalClusterSummary | null;
    created_at: string | null;
};

/** One event's coverage, as the card's "reported by N sources" disclosure reads it (S11). */
export type SignalClusterSummary = {
    id: number;
    /** How many articles reported it, including this one. Always 2 or more when present. */
    sources_count: number;
    first_published_at: string | null;
    last_published_at: string | null;
    sources: {
        id: number;
        source_name: string | null;
        source_url: string;
        published_at: string | null;
    }[];
};

/** The feed's tab + filter state, mirroring `SignalFilters::toFormState()`. */
export type SignalFilterState = {
    status: SignalStatus;
    /** Which half of the feed: everything, or only events about the team's own accounts (S10). */
    scope: SignalScope;
    /** Signal definition keys. Any the team no longer has is dropped by the server. */
    event_keys: SignalEventKey[];
    /** `Y-m-d`, as a date input speaks it. */
    from: string | null;
    to: string | null;
    min_relevance: number | null;
};

export type SignalDismissReasonOption = {
    value: SignalDismissReason;
    /** Translation key, not a finished string. */
    label: string;
};

export type SignalCadenceOption = {
    value: SignalWatchCadence;
    /** Translation key, not a finished string. */
    label: string;
};

/** One run of a watch: what it cost and what it found. */
export type SignalRun = {
    id: number;
    status: SignalRunStatus;
    /** Translation key, not a finished string. */
    status_label: string;
    queries_count: number;
    /** What the plan wanted before the per-run ceiling, or null for a run from before S8. */
    queries_requested: number | null;
    /** How many searches the ceiling cut. Zero means none. */
    queries_trimmed: number;
    results_count: number;
    signals_created: number;
    /** Integer micro-units. */
    cost_micros: number;
    /** Already user-safe — the exception's detail went to the log, not here. */
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};

/** A region a watch may search: a Serper country/language pair, not a compliance market. */
export type SignalRegionOption = {
    value: string;
    /** Translation key, not a finished string. */
    label: string;
    /**
     * What choosing this region means for how the resulting leads may be approached.
     *
     * On the payload because choosing where to look is also choosing which rules apply, and
     * nothing else on the watch form says so (ADR 0019).
     */
    approach_note: string;
    /** The places a watch in this region starts with. */
    default_locations?: string[];
};

/** A standing instruction to look for events in a market. */
export type SignalWatch = {
    id: number;
    /** The team's own words — never translated. */
    name: string;
    /**
     * The team's declared compliance regime for this watch — a backstop for a signal whose own
     * jurisdiction cannot be resolved, **not** the thing that picks where to look (S8, ADR 0019).
     */
    market: string;
    /** Translation key, not a finished string. */
    market_label: string;
    /** The places this watch covers. */
    locations: string[];
    /** Where it searches, with the compliance note each one implies. */
    regions: SignalRegionOption[];
    /** The types this watch looks for. Absent when the server didn't load them. */
    definitions?: SignalDefinition[];
    /** Sources this team never wants to hear from — their competitors (S6). */
    ignore_domains: string[];
    cadence: SignalWatchCadence;
    /** Translation key, not a finished string. */
    cadence_label: string;
    is_active: boolean;
    last_run_at: string | null;
    /**
     * When the hourly scheduler will next consider it. Null for a watch that has never run (it is
     * due now) or one that is paused (it isn't due at all).
     */
    next_due_at: string | null;
    icp?: { id: number; name: string } | null;
    /**
     * The accounts this watch follows **instead of** places (S10, ADR 0021).
     *
     * Null is a topical watch, which is what every watch was before S10. The name comes down with
     * it so a card can say which list without the client joining two props together.
     */
    signal_watchlist_id: number | null;
    watchlist_name: string | null;
    signals_count?: number;
    runs?: SignalRun[];
    created_at: string | null;
};

/** The form state the convert modal binds to, prefilled from a signal. */
export type ConvertSignalForm = {
    company_name: string;
    website: string;
    person_name: string;
    person_role: string;
    locality: string;
    country: string;
    pipeline_stage_id: number | null;
    note: string;
};

/** The form state the watch editor binds to. */
export type SignalWatchForm = {
    name: string;
    /** One place per line in the form; sent as a list. */
    locations: string[];
    /** Region codes to search. Only the ones the server offers are accepted. */
    regions: string[];
    /** The team's own signal types to look for (S7, ADR 0018). */
    signal_definition_ids: number[];
    /** One domain per line in the form; sent as a list. */
    ignore_domains: string[];
    cadence: SignalWatchCadence;
    is_active: boolean;
    icp_id: number | null;
    /** The watchlist this watch follows, or null for a watch that searches places (S10). */
    signal_watchlist_id: number | null;
};

/**
 * What a new watch starts as.
 *
 * No types: the editor pre-ticks every active type the team has, and the one-click button creates
 * them by cloning the library first (S7) — neither of which the server can state here.
 */
export type SignalWatchDefaults = {
    /** The places a new watch starts with, from the team's own market. */
    locations: string[];
    /** The region a new watch starts in, from the team's own market. */
    regions: string[];
    cadence: SignalWatchCadence;
    /** The team's compliance regime, which a member cannot choose on this form. */
    market: string;
    /** Translation key, not a finished string. */
    market_label: string;
};

/** The form state the signal-type editor binds to, mirroring `SignalDefinitionData::rules()`. */
export type SignalDefinitionForm = {
    name: string;
    description: string;
    category: SignalCategory;
    icon: SignalIcon;
    /** One template per line in the form; sent as a list. */
    query_templates: string[];
    match_keywords: string[];
    includes: string[];
    excludes: string[];
    min_confidence: number | null;
    min_relevance: number | null;
    suggested_approach_hint: string | null;
    is_active: boolean;
};

/**
 * A vertical bundle of library presets (S9).
 *
 * `general` is where the market starter sets live — a change of ownership is not a vertical's
 * concern, it is everybody's.
 */
export type SignalPresetPack =
    | 'general'
    | 'luxury_real_estate'
    | 'recruitment'
    | 'b2b_saas'
    | 'wealth_management'
    | 'construction'
    | 'ma_advisory';

/** A pack with the line that says who it is for — a filter nobody recognises is one nobody presses. */
export type SignalPackOption = {
    value: SignalPresetPack;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    audience: string;
};

/**
 * What one template of this definition would cost the team a month (S9).
 *
 * Sent **per template** so the form can multiply as somebody types, rather than as a total that
 * would need a round trip to change. The server owns the arithmetic (ADR 0019 §4): regions × places
 * × templates per run, times how often the team's watches run.
 */
export type SignalCostFacts = {
    templates: number;
    queries_per_run: number;
    runs_per_month: number;
    queries_per_month: number;
    cost_micros_per_month: number;
    watch_count: number;
    /** True when the team has no watch yet, so this is "if you add it to one daily watch". */
    hypothetical: boolean;
    micros_per_search: number;
};

/**
 * What the composer drafted, flashed back from `POST /signals/types/compose` (S9).
 *
 * `viable: false` is a real answer with a reason: "find me rich people in Dubai" is not an event a
 * news search can find, and a composer that drafted plausible templates for it would cost a team
 * money every day forever.
 */
export type SignalDraft = {
    viable: boolean;
    reason: string | null;
    /** What the composer understood the brief to mean, in its own words. */
    summary: string | null;
    definition: SignalDefinitionForm | null;
};

/** What a preview did with one article, and why (S9). */
export type SignalPreviewVerdict =
    | 'ignored_domain'
    | 'not_an_event'
    | 'wrong_location'
    | 'below_confidence'
    | 'below_relevance'
    | 'kept'
    /** The AI never got to it — out of credit, or it answered unusably twice. Not a finding. */
    | 'unclassified';

export type SignalPreviewItem = {
    headline: string;
    url: string;
    source: string | null;
    published_at: string | null;
    verdict: SignalPreviewVerdict;
    /** Translation key, not a finished string. */
    verdict_label: string;
    /** Translation key, not a finished string — what to change about this one. */
    verdict_advice: string;
    /** Whether the templates found an event at all, whatever happened to it afterwards. */
    found_an_event: boolean;
    confidence: number | null;
    relevance: number | null;
    summary: string | null;
    subject_company_name: string | null;
    subject_person_name: string | null;
    location: string | null;
    /** The query that found it, so a member can see which template is pulling its weight. */
    query: string;
};

/**
 * What a preview found, flashed back from `POST /signals/types/preview` (S9).
 *
 * The counts and the `diagnosis` come from the server because "no news at all", "news but no
 * events" and "events your thresholds rejected" are three different problems with three different
 * fixes — and a client re-deriving which one it is would be a second implementation of the only
 * judgement this screen makes.
 */
export type SignalPreviewResult = {
    queries: string[];
    queries_requested: number;
    was_trimmed: boolean;
    result_count: number;
    event_count: number;
    kept_count: number;
    cost_micros: number;
    /** Translation key, not a finished string. */
    diagnosis: string;
    items: SignalPreviewItem[];
};

/**
 * Which half of the feed is on screen (S10).
 *
 * A **scope**, not a status, and the two are orthogonal: "new events about my accounts" and
 * "converted events about my accounts" are both questions, so folding this into `SignalStatus`
 * would have produced six cases that were really two questions crossed.
 */
export type SignalScope = 'all' | 'accounts';

/** A scope with the sentence its empty state shows. */
export type SignalScopeOption = {
    value: SignalScope;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    empty_message: string;
};

/** Where a watchlist's names come from. `segment` is declared server-side and not offered. */
export type WatchlistSourceKind = 'manual' | 'pipeline' | 'segment';

export type WatchlistEntityType = 'company' | 'person';

/**
 * How sure Alima is that an event is about a lead the team already has (S10).
 *
 * There is no "low": a weak guess is **not a match**, because the way account monitoring goes
 * wrong is an event pinned to the wrong company.
 */
export type SignalMatchConfidence = 'exact' | 'likely';

/** One account on a watchlist. */
export type SignalWatchlistEntry = {
    id: number;
    entity_type: WatchlistEntityType;
    /** Translation key, not a finished string. */
    entity_type_label: string;
    /** The member's own spelling — the comparison key is never sent. */
    name: string;
    aliases: string[];
    domain: string | null;
    is_active: boolean;
    lead_id: number | null;
    lead?: { id: number; company_name: string } | null;
};

/** A set of accounts a team wants watched. */
export type SignalWatchlist = {
    id: number;
    name: string;
    source_kind: WatchlistSourceKind;
    /** Translation keys, not finished strings. */
    source_kind_label: string;
    source_kind_description: string;
    is_active: boolean;
    is_synced: boolean;
    /** Whether a member may add and remove names: a synced list's entries are an output. */
    can_edit_entries: boolean;
    entries_count: number;
    stages: number[];
    owners: number[];
    synced_at: string | null;
    watches_count?: number;
    entries?: SignalWatchlistEntry[];
    created_at: string | null;
};

/** One choosable watchlist, as the watch editor's picker reads it (S10). */
export type SignalWatchlistOption = {
    id: number;
    name: string;
    source_kind: WatchlistSourceKind;
    entries_count: number;
};

/** The form state the watchlist editor binds to, mirroring `SignalWatchlistData::rules()`. */
export type SignalWatchlistForm = {
    name: string;
    source_kind: WatchlistSourceKind;
    is_active: boolean;
    stages: number[];
    owners: number[];
};

/** A category with its section explainer, for grouping the library. */
export type SignalCategoryOption = {
    value: SignalCategory;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    description: string;
};

/** An icon with its label, for the picker. */
export type SignalIconOption = {
    value: SignalIcon;
    /** Translation key, not a finished string. */
    label: string;
};

/** The floors and ceilings the type editor needs to explain itself. */
export type SignalDefinitionDefaults = {
    /** The product's confidence floor, used when a type sets none. */
    min_confidence: number;
    min_relevance: number;
    /** How many types this team may have. */
    max_definitions: number;
};

/** The payload broadcast on `teams.{id}` as `signals.detected`. */
export type SignalsDetectedPayload = {
    run_id: number;
    count: number;
};

/** The payload broadcast on `teams.{id}` as `signal-run.progressed`. */
export type SignalRunProgressPayload = {
    /** The watch this run belongs to — how a card finds progress for a run it hasn't seen yet. */
    watch_id: number;
    run_id: number;
    status: SignalRunStatus;
    /** 0–100. */
    percent: number;
    /** The stage key: `searching`, `analysing`, `saving`, `done`. */
    step: string;
    signals_created: number;
};

/** The payload broadcast on `teams.{id}` as `signals.converted`. */
export type SignalConvertedPayload = {
    signal_id: number;
    lead_id: number;
    /** True when the signal was linked to a lead the team already had, rather than making one. */
    attached: boolean;
};
