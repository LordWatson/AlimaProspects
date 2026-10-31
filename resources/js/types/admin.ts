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
