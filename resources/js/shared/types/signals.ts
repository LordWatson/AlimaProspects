// Signals (trigger-event prospecting, UAE v1) shared types — ADR 0011, phases S4–S6.

export type SignalEventType = 'acquisition' | 'funding_round' | 'relocation';

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
    event_type: SignalEventType;
    /** Translation key, not a finished string. */
    event_type_label: string;
    /** Translation key, not a finished string. */
    event_type_description: string;
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
    event_types: SignalEventType[];
    /** `Y-m-d`, as a date input speaks it. */
    from: string | null;
    to: string | null;
    min_relevance: number | null;
};

/** An event type with the one-line explainer shown beside its checkbox. */
export type SignalEventTypeOption = {
    value: SignalEventType;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    description: string;
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
    event_types: SignalEventTypeOption[];
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
    event_types: SignalEventType[];
    /** One domain per line in the form; sent as a list. */
    ignore_domains: string[];
    cadence: SignalWatchCadence;
    is_active: boolean;
    icp_id: number | null;
};

/** What the one-click "first watch" button will create. */
export type SignalWatchDefaults = {
    location: string;
    event_types: SignalEventType[];
    cadence: SignalWatchCadence;
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
