/*
 * Admin panel payload types (Phase 19a, ADR 0014).
 *
 * Web-only, like `navigation.ts` and `ui.ts` — which is why they live here rather than in
 * `@/shared/types`. The mobile app is a field companion and has no admin surface; sharing these
 * would promise an API that deliberately does not exist (the panel is web-only by decision, see
 * `Admin/routes/web.php`).
 *
 * The shapes mirror `AdminResource::toArray()` and the generic controllers. They are hand-written
 * rather than generated for the reason Phase 17 gives about the API types: a declaration carries
 * what a serialised payload cannot — which unions are closed, which fields are only present on a
 * detail page — and a generated second set would simply drift from the first.
 */

import type {
    BreakdownSlice,
    FunnelStep,
    Kpi,
    ReportColumn,
    ReportRow,
} from '@/shared/types';

/**
 * Mirrors `Core\Enums\Capability` (Phase 19b, ADR 0015) — a feature a team either has or does not,
 * depending on their plan.
 *
 * In the web-only types rather than `@/shared/types` because the **shared prop** is a web shell
 * concern; the mobile app gates nothing today. Kept as a union rather than a bare string so a
 * mistyped nav tag is a type error instead of a link that quietly never hides.
 */
export type AppCapability =
    | 'signals'
    | 'ai_insights'
    | 'business_intelligence'
    | 'enrichment'
    | 'csv_export'
    | 'api_access';

/** Mirrors `Reporting\Enums\ColumnFormat`, reused by the registry rather than reinvented. */
export type AdminColumnFormat = 'text' | 'count' | 'money' | 'percent' | 'date';

export type AdminColumn = {
    name: string;
    label: string;
    format: AdminColumnFormat;
    /** Whether the column reads better right-aligned, which every numeric one does. */
    numeric: boolean;
    /** For `money` only: the sibling field holding the ISO currency code. */
    currency_field: string | null;
};

export type AdminFieldType =
    | 'text'
    | 'textarea'
    | 'integer'
    | 'boolean'
    | 'select'
    | 'date';

export type AdminField = {
    name: string;
    label: string;
    type: AdminFieldType;
    required: boolean;
    /** value => label, for `select`. Empty for every other type. */
    options: Record<string, string>;
    help: string | null;
};

export type AdminFilter = {
    name: string;
    label: string;
    options: Record<string, string>;
};

/** One descriptor, as the generic pages render it. */
export type AdminResourceMeta = {
    key: string;
    label: string;
    singular: string;
    group: string;
    group_label: string;
    columns: AdminColumn[];
    detail_columns: AdminColumn[];
    fields: AdminField[];
    filters: AdminFilter[];
    searchable: boolean;
    sortable: string[];
    default_sort: string;
    tenant_owned: boolean;
    can_create: boolean;
    can_update: boolean;
    can_delete: boolean;
};

export type AdminNavSection = {
    label: string;
    items: { key: string; label: string }[];
};

/** A cell value. Dates arrive as ISO strings and JSON blobs as encoded strings. */
export type AdminCellValue = string | number | boolean | null;

export type AdminRow = {
    id: string | number;
    /** A one-line description of the record. Never personal data — see `AdminResource::title()`. */
    title: string;
    cells: Record<string, AdminCellValue>;
};

export type AdminIndexFilters = {
    search: string | null;
    filters: Record<string, string>;
    team_id: number | null;
    sort: string | null;
    direction: 'asc' | 'desc';
    per_page: number;
};

export type AdminTeamOption = { id: number; name: string };

/**
 * One live platform announcement, already resolved for this reader's locale with an English
 * fallback (Phase 19b). The title and body are an **operator's words**, not translation keys.
 */
export type AnnouncementBannerData = {
    id: number;
    level: 'info' | 'warning' | 'critical';
    /** The semantic status token the banner renders in. Never Signal Orange. */
    variant: 'info' | 'warning' | 'error';
    title: string;
    body: string;
    /**
     * The locale each line **actually** resolved to, which is not always the one asked for. The
     * banner marks `lang` and `dir` from these: a reader who asked for Arabic and got the English
     * fallback is reading LTR text inside an RTL page.
     */
    title_locale: string;
    body_locale: string;
    dismissible: boolean;
};

/**
 * The impersonation banner, shared on **every** tenant screen so an operator can never be inside
 * somebody's account without the page saying so.
 */
export type ImpersonationBannerData = {
    active: true;
    operator: string | null;
    expires_at: string | null;
    stop_url: string;
};

/* ------------------------------------------------------------------ Phase 19b */

/** One declared platform setting, with its current value and whether somebody chose it. */
export type PlatformSetting = {
    key: string;
    type: 'bool' | 'int' | 'string_list';
    default: unknown;
    label: string;
    help: string;
    /** Whether it is a switch an operator reaches for during an incident. */
    operational: boolean;
    value: unknown;
    /**
     * Whether a row exists in `platform_settings`. Rendered rather than hidden: a value alone does
     * not say whether somebody chose it, and that is the question an incident starts with.
     */
    is_overridden: boolean;
};

/** Which of the four layers is deciding a capability for one tenant. */
export type AdminCapabilityRow = {
    value: AppCapability;
    label: string;
    /** Where the refusal is actually made — documentation the screen shows. */
    enforced_at: string;
    allowed: boolean;
    /** What the plan alone would say — i.e. what clearing an override would do. */
    plan_allows: boolean;
    /**
     * Which of the four layers settled it. ADR 0015 accepts four layers on the condition that the
     * screen names the one answering, so nobody has to guess why a feature is off.
     */
    decided_by: 'suspension' | 'kill_switch' | 'override' | 'plan';
    override: {
        enabled: boolean;
        reason: string;
        expires_at: string | null;
        /** False once it has expired, at which point the plan decides again. */
        active: boolean;
    } | null;
};

export type AdminTeamBilling = {
    team: {
        id: number;
        name: string;
        billing_mode: string;
        suspended: boolean;
    };
    /**
     * The **resolved** answer, which is the point of the screen: a grant, a subscription, a trial
     * and the default plan can each be the reason, and `state` is what says which.
     */
    entitlements: {
        plan: string;
        plan_name: string;
        state: string;
        state_label: string;
        /** Null is **unlimited**, which is a different statement from zero. */
        seats: number | null;
        leads: number | null;
        credits: number | null;
        trial_ends_at: string | null;
        ends_at: string | null;
    };
    grant: {
        id: number;
        plan: string;
        seats: number | null;
        leads: number | null;
        credits: number | null;
        starts_at: string;
        ends_at: string | null;
        note: string;
    } | null;
    options: {
        modes: { value: string; label: string }[];
        plans: { value: string; label: string }[];
    };
};

export type AdminProvisioningOptions = {
    markets: { value: string; label: string }[];
    locales: { value: string; label: string }[];
    modes: { value: string; label: string }[];
    plans: { value: string; label: string }[];
    capabilities: { value: AppCapability; label: string }[];
};

/**
 * The dry run's answer (Phase 19b). `owner_exists` and `owner_team_count` are the two fields the
 * screen leads with when they are true: adding an existing customer to a third team is occasionally
 * exactly right and occasionally a typo that would email a stranger.
 */
export type ProvisionPreview = {
    team_name: string;
    owner_email: string;
    owner_exists: boolean;
    owner_name: string;
    owner_team_count: number;
    billing_mode: string;
    billing_mode_label: string;
    grant_plan: string | null;
    /** Null = the configured default; 0 = no trial. */
    trial_days: number | null;
    will_create_profile: boolean;
    will_create_icp: boolean;
    capabilities_on: string[];
    /** Assembled server-side, so the dry run and the confirmation cannot describe it differently. */
    summary: string;
};

export type AdminAnnouncement = {
    id: number;
    level: string;
    variant: 'info' | 'warning' | 'error';
    title: string;
    plans: string[] | null;
    markets: string[] | null;
    starts_at: string;
    ends_at: string | null;
    is_dismissible: boolean;
    notify: boolean;
    notified_at: string | null;
};

export type AdminAnnouncementOptions = {
    levels: { value: string; label: string; dismissible: boolean }[];
    plans: { value: string; label: string }[];
    markets: { value: string; label: string }[];
};

/* ------------------------------------------------------------------ Phase 19c */

/**
 * The platform's own reports.
 *
 * Reuses `ReportColumn`/`ReportRow` from the shared reporting types, because a platform report and
 * a tenant report are the same table shape rendered by the same component. The **keys** differ,
 * which is why this is its own union and not `ReportKey`: a cross-tenant figure must not be
 * reachable through a tenant's enum (see `Admin\Enums\PlatformReport`).
 */
export type PlatformReportKey =
    | 'growth'
    | 'funnel'
    | 'revenue'
    | 'movement'
    | 'retention'
    | 'pricing'
    | 'cost'
    | 'ai_cost'
    | 'lead_cost'
    | 'margin'
    | 'margin_by_plan'
    | 'margin_by_cohort'
    | 'outreach';

/**
 * `definitions` is the one addition over a tenant report's shape, and it is the point: a platform
 * aggregate that quietly answers a tenant-shaped question has no customer to complain, so every
 * figure's counting rule travels with the figure.
 */
export type PlatformReportTable = {
    key: PlatformReportKey;
    title: string;
    caption: string;
    columns: ReportColumn[];
    rows: ReportRow[];
    definitions: string[];
    empty_message: string;
};

export type PlatformReportPage = {
    kpis: Kpi[];
    funnel: FunnelStep[];
    signups_by_market: BreakdownSlice[];
    /** In day order, not by volume: the shape is the signal. */
    conversion_days: BreakdownSlice[];
    tables: PlatformReportTable[];
    currency: string;
    /** ISO code => minor units of the reporting currency per major unit. A configured constant. */
    fx_rates: Record<string, number>;
    /** The last day any metric was rolled up; null when the nightly command has never run. */
    last_rolled_up: string | null;
    range_from: string | null;
    range_to: string | null;
    range: string;
    has_data: boolean;
    /** True when a margin somewhere on the page is a ceiling rather than a figure. */
    has_unconverted_cost: boolean;
};

/** One failed job, with the thing Horizon's queue view does not know: whose it was. */
export type AdminFailedJob = {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    job: string;
    /** Null when the payload does not say. An em dash, never a guess. */
    team_id: number | null;
    /** The exception's first line only. */
    exception: string;
    failed_at: string | null;
};

export type AdminFailedJobs = {
    available: boolean;
    driver: string | null;
    total: number;
    jobs: AdminFailedJob[];
};

export type AdminQueueDepth = {
    available: boolean;
    driver: string;
    /** Why the figure is missing, when it is. Shown rather than hidden. */
    note: string | null;
    queues: {
        queue: string;
        pending: number;
        oldest_at: string | null;
        oldest_age_seconds: number | null;
        max_attempts_seen: number;
    }[];
};

/**
 * One scheduled command. `last_seen_at` rather than "last run": the record is cache-backed, so a
 * flushed cache reports never-seen rather than the truth.
 */
export type AdminScheduledTask = {
    command: string;
    expression: string;
    description: string | null;
    next_due: string | null;
    last_seen_at: string | null;
    last_outcome: 'ok' | 'failed' | 'skipped' | null;
    last_runtime_ms: number | null;
    last_error: string | null;
};

export type AdminPulseStatus = {
    installed: boolean;
    enabled: boolean;
    path: string | null;
};

/**
 * One provider's health. `instrumented: false` means it has no circuit breaker at all — three do
 * not — so `open` is null rather than a green light nobody wired up.
 */
export type AdminProviderHealth = {
    key: string;
    label: string;
    impact: string;
    instrumented: boolean;
    open: boolean | null;
    failures_recorded: number | null;
    threshold: number | null;
    /** Always true. The breaker is cache-backed and sheds load; it is not a guarantee. */
    advisory: boolean;
    metered: boolean;
    calls: number | null;
    failures: number | null;
    /** Null over no calls — unused, not healthy. */
    failure_rate: number | null;
    cost_minor: number | null;
    mean_latency_ms: number | null;
    /**
     * Per-call figures for a paid enrichment vendor (PL3), read off `enrichment_attempts` rather
     * than the rollup. **Null for every other provider** — not an empty object, which a screen
     * would render as a row of zeroes for a vendor that has no such figures at all.
     */
    vendor: AdminVendorStats | null;
};

/**
 * One enrichment vendor's recent performance.
 *
 * `cost_per_hit_micros` is the figure that matters and the one a per-call price hides: a finder
 * that misses two times in three costs three times its sticker price per address. Null rather than
 * zero when nothing was found, because zero reads as free.
 */
export type AdminVendorStats = {
    attempts: number;
    hits: number;
    misses: number;
    errors: number;
    /** Attempts we actually made — skips excluded, because we never asked. */
    called: number;
    hit_rate: number | null;
    error_rate: number | null;
    cost_micros: number;
    cost_per_hit_micros: number | null;
    mean_latency_ms: number | null;
};

export type AdminAuditEntry = {
    id: number;
    action: string;
    category: string;
    category_label: string;
    at: string | null;
    actor: string | null;
    actor_id: number | null;
    team: string | null;
    team_id: number | null;
    subject_type: string | null;
    subject_id: number | null;
    ip_address: string | null;
    request_id: string | null;
    /** Ids and counts as the writing Action stored them — never a copy of the record. */
    properties: Record<string, unknown> | null;
};

export type AdminPagination = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

/**
 * What one signal source cost and found (S13, ADR 0023) — a row of `signal_runs.source_breakdown`.
 *
 * `rejected` is counted apart from `queries` on purpose: a query the provider refused as a *pattern*
 * never ran, so including it would make cost-per-query wrong. `skipped` is apart from both (S13b
 * review): it is a query that found **nothing at the address it named** — a struck-off company, a
 * feed a publisher has moved — which is a different problem with different advice, and is the one
 * 4xx that must never fail a run. `breaker_open` is null rather than false where this build watches
 * no breaker for the source, which is the same rule the provider health screen follows — a green
 * light nobody wired up is worse than no light.
 */
export type AdminSignalRunSource = {
    source: string;
    label: string;
    queries: number;
    results: number;
    rejected: number;
    skipped?: number;
    cost_micros: number;
    breaker_open?: boolean | null;
    breaker_failures?: number | null;
};

export type AdminSignalRun = {
    id: number;
    team: string | null;
    team_id: number | null;
    watch: string | null;
    status: string;
    queries: number;
    results: number;
    created: number;
    cost_micros: number;
    /** Where this run's money went, per source. Empty for every run from before S13. */
    sources: AdminSignalRunSource[];
    /** Null when the run never started or never finished — which is the finding on a stuck run. */
    duration_ms: number | null;
    error: string | null;
    at: string | null;
};

export type AdminComplianceEvent = {
    id: number;
    action: string;
    at: string | null;
    team: string | null;
    team_id: number | null;
    /** Null for the scheduled sweep, which runs with nobody behind it. */
    actor: string | null;
    properties: Record<string, unknown> | null;
};

export type AdminAssessment = {
    id: number;
    team: string | null;
    team_id: number | null;
    market: string;
    status: string;
    version: number;
    completed_at: string | null;
    review_due_at: string | null;
    withdrawn_at: string | null;
    in_force: boolean;
    expired: boolean;
    review_due_in_days: number | null;
};

export type AdminRetentionPolicy = {
    id: number;
    team: string | null;
    team_id: number | null;
    is_enabled: boolean;
    uncontacted_lead_days: number;
    contacted_lead_days: number;
    last_swept_at: string | null;
    last_deleted_count: number;
    last_anonymised_count: number;
};

export type AdminDeliverability = {
    channels: {
        channel: string;
        label: string;
        sent: number;
        attempts: number;
        bounced: number;
        opted_out: number;
        bounce_rate: number | null;
        opt_out_rate: number | null;
    }[];
    totals: {
        sent: number;
        attempts: number;
        bounced: number;
        complaints: number;
        bounce_rate: number | null;
        opt_out_rate: number | null;
    };
    last_rolled_up: string | null;
};

/** A global suppression. `preview` is a mask — the table stores only a hash of the value. */
export type AdminSuppression = {
    id: number;
    type: string;
    type_label: string;
    preview: string;
    reason: string;
    reason_label: string;
    note: string | null;
    added_by: string | null;
    suppressed_at: string;
    /** Always false for a global entry. Rendered so the missing button is visibly a rule. */
    removable: boolean;
};

export type AdminSuppressionSummary = {
    global: number;
    team: number;
    global_by_reason: Record<string, number>;
};

export type AdminWindowFilters = {
    range: string;
    from: string | null;
    to: string | null;
};

/**
 * One platform preset, as the library screen reads it (S15 task 4).
 *
 * `clones` and `clones_behind` are the two figures that decide whether a preset is worth
 * maintaining: how many teams run a copy, and how many of those have not taken the latest change.
 * A preset with ninety clones all one version behind is a change nobody wanted.
 */
export type AdminSignalPreset = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    category: string;
    icon: string;
    /** Whether the library still offers it. Withdrawing one leaves every clone running. */
    is_active: boolean;
    version: number;
    templates: number;
    /** The templates themselves, so the editor can round-trip a preset. */
    query_templates: string[];
    min_confidence: number | null;
    min_relevance: number | null;
    markets: string[] | null;
    source_keys: string[] | null;
    clones: number;
    clones_behind: number;
    created_at: string | null;
    updated_at: string | null;
};

/**
 * A team's offer of their own signal type, as the review queue reads it (S15 task 4).
 *
 * **There is deliberately no team here.** An operator choosing between two submissions should be
 * choosing on the evidence, and the evidence is `performance` — the type's own funnel. The row
 * carries the author server-side, because the team has to be told the answer; the payload does not.
 */
export type AdminSignalSubmission = {
    id: number;
    name: string | null;
    description: string | null;
    key: string;
    category: string | null;
    templates: string[];
    markets: string[] | null;
    source_keys: string[] | null;
    /** The author's own covering note. Prose, not a translation key. */
    note: string | null;
    performance: { found: number; kept: number; converted: number };
    /** False when the team deleted the type while the offer waited — nothing left to promote. */
    is_available: boolean;
    created_at: string | null;
};
