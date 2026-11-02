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
    created_at: string | null;
};

/** The feed's tab + filter state, mirroring `SignalFilters::toFormState()`. */
export type SignalFilterState = {
    status: SignalStatus;
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

/** A standing instruction to look for events in a market. */
export type SignalWatch = {
    id: number;
    /** The team's own words — never translated. */
    name: string;
    market: string;
    /** Translation key, not a finished string. */
    market_label: string;
    location: string;
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
    location: string;
    /** The team's own signal types to look for (S7, ADR 0018). */
    signal_definition_ids: number[];
    /** One domain per line in the form; sent as a list. */
    ignore_domains: string[];
    cadence: SignalWatchCadence;
    is_active: boolean;
    icp_id: number | null;
};

/**
 * What a new watch starts as.
 *
 * No types: the editor pre-ticks every active type the team has, and the one-click button creates
 * them by cloning the library first (S7) — neither of which the server can state here.
 */
export type SignalWatchDefaults = {
    location: string;
    cadence: SignalWatchCadence;
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
