// Reporting & Analytics shared types — the dashboard read-model built by
// `App\Modules\Reporting\Services\DashboardReportBuilder`.
//
// Every `label`, `caption` and `hint` is an **English translation key**, not a finished string:
// the numbers come from the server, the words are resolved client-side through `useI18n` so the
// dashboard reads in Arabic too (ADR 0007).

export type KpiFormat = 'count' | 'money';

/** One headline tile. `value` is a plain integer — money arrives in minor units. */
export type Kpi = {
    key: string;
    /** Translation key, not a finished string. */
    label: string;
    value: number;
    /** Translation key, not a finished string. */
    caption: string;
    format: KpiFormat;
    /** ISO currency code; set only when `format` is `money`. */
    currency: string | null;
    /** Change against the previous window of equal length; null when not comparable. */
    delta: number | null;
    /** Translation key, not a finished string. */
    delta_label: string | null;
    /** Weekly values, oldest first, for the tile's sparkline. Empty when there is no history. */
    spark: number[];
};

/**
 * One step of the prospecting funnel.
 *
 * `available: false` means "no source of truth yet" — `value` is null in that case, and the step
 * must never be drawn as a zero: "we are not tracking this" and "nobody replied" are different
 * claims. Every step has a source of truth as of Phase 13 (Outreach shipped in Phase 11), so the
 * flag is now a guarantee the screen keeps rather than a state it renders — a future step that
 * arrives before its data can use it without touching the chart.
 */
export type FunnelStep = {
    key: string;
    /** Translation key, not a finished string. */
    label: string;
    /** Translation key, not a finished string. */
    caption: string;
    value: number | null;
    available: boolean;
};

/** How wide one bucket of the trend is — picked from the selected window, not configured. */
export type TrendGranularity = 'day' | 'week' | 'month';

/** One bucket of the sourced-vs-qualified trend, cut in the team's timezone. */
export type TrendPoint = {
    /** Short axis label, e.g. "6 Oct" or "Oct 2026". */
    label: string;
    /** ISO date that opens the bucket. */
    starts_on: string;
    sourced: number;
    /** Of those sourced in that bucket, how many graded A or B. Always <= `sourced`. */
    qualified: number;
};

/** A labelled count in a one-dimensional breakdown — a stage, a source, an event type. */
export type BreakdownSlice = {
    key: string;
    /** Translation key, not a finished string. */
    label: string;
    value: number;
    /** Translation key, not a finished string. */
    hint: string | null;
};

/** The windows the date filter offers, plus the date pair a member can type. */
export type ReportRange =
    | 'last_7_days'
    | 'last_30_days'
    | 'last_90_days'
    | 'this_month'
    | 'last_month'
    | 'this_quarter'
    | 'this_year'
    | 'all_time'
    | 'custom';

/** Every report the dashboard shows and can export. */
export type ReportKey =
    | 'kpis'
    | 'funnel'
    | 'trend'
    | 'grades'
    | 'stages'
    | 'sources'
    | 'signal_types'
    | 'icp_performance'
    | 'team_activity'
    | 'ai_usage'
    | 'outreach';

/** The dashboard's filter state, as the control binds to it and the URL carries it. */
export type ReportFilterState = {
    range: ReportRange;
    /** `YYYY-MM-DD` in the team's timezone. */
    from: string;
    /** `YYYY-MM-DD` in the team's timezone, inclusive. */
    to: string;
};

/** The option lists behind the filter and export controls, built from the server's enums. */
export type ReportFilterOptions = {
    ranges: { value: ReportRange; label: string }[];
    reports: { value: ReportKey; label: string }[];
};

export type ColumnFormat = 'text' | 'count' | 'money' | 'percent' | 'date';

/**
 * One column of a report table.
 *
 * `label` is always a translation key. `translated` says whether the **cells** are too: a channel
 * or a role is one of Alima's own words, whereas a member's name or a prompt key is data and has
 * to be printed exactly as stored.
 */
export type ReportColumn = {
    key: string;
    /** Translation key, not a finished string. */
    label: string;
    format: ColumnFormat;
    translated: boolean;
    /** ISO currency code; set only when `format` is `money`. */
    currency: string | null;
};

/** One row of a report table, keyed by column key. A null cell is an em dash, never a zero. */
export type ReportRow = {
    key: string;
    cells: Record<string, string | number | null>;
};

/** Any report, as a table — the shape both the screen and the CSV export read. */
export type ReportTable = {
    key: ReportKey;
    /** Translation key, not a finished string. */
    title: string;
    /** Translation key, not a finished string. */
    caption: string;
    columns: ReportColumn[];
    rows: ReportRow[];
    /** Translation key shown instead of an empty grid. */
    empty_message: string;
};

export type DashboardReport = {
    kpis: Kpi[];
    funnel: FunnelStep[];
    trend: TrendPoint[];
    /** A–D fit mix; always all four letters, zeros included. */
    grades: BreakdownSlice[];
    /** Pipeline stages in board order. */
    stages: BreakdownSlice[];
    /** Lead sources, busiest first; empty sources omitted. */
    sources: BreakdownSlice[];
    /** Signal event types, busiest first; empty types omitted. */
    signal_types: BreakdownSlice[];
    /** The tabular reports, in tab order. */
    tables: ReportTable[];
    filters: { range: ReportRange; from: string | null; to: string | null };
    /** The window's first day, ISO; null for all time. */
    range_from: string | null;
    /** The window's last day (inclusive), ISO; null for all time. */
    range_to: string | null;
    trend_granularity: TrendGranularity;
    trend_buckets: number;
    /** False when the **selected window** has nothing in it. */
    has_data: boolean;
    /**
     * False only for a team that has never sourced a lead — the flag the first-run explainer turns
     * on. A team with leads that filters to a quiet week has an empty window, not an empty
     * account, and must not be told it has never started.
     */
    has_ever_sourced: boolean;
};
