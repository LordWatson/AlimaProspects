export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * One filter in force on a list screen, as the summary row shows it.
 *
 * `group` and `label` are finished, translated strings — the summary is the one place a filter is
 * named in prose, so the page composes the wording and the component only renders it. `id` is
 * what comes back on removal, so a page can take that one filter off without rebuilding the rest.
 */
export type ActiveFilterChip = {
    id: string;
    /** Which filter this is — "Fit", "Stage", "Who's on it". */
    group: string;
    /** The value, in the member's language or the team's own word. */
    label: string;
};
