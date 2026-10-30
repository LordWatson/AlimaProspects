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
 * `available: false` means "no source of truth yet" — Outreach (Phase 11) owns contacts and
 * replies. `value` is null in that case, and the step must never be drawn as a zero: "we are not
 * tracking this" and "nobody replied" are different claims.
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

/** One week of the sourced-vs-qualified trend, cut in the team's timezone. */
export type TrendPoint = {
    /** Short axis label, e.g. "6 Oct". */
    label: string;
    /** ISO date of the Monday that opens the week. */
    starts_on: string;
    sourced: number;
    /** Of those sourced that week, how many graded A or B. Always <= `sourced`. */
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
    /** False for a team that has not sourced a lead yet — show the explainer, not empty charts. */
    has_data: boolean;
    trend_weeks: number;
};
