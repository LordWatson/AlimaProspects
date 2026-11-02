/*
 * Alima's list furniture — the shell every tenant list screen is built from.
 *
 * Before this existed, each screen hand-rolled its own `<table>`, its own filter panel and its own
 * idea of what a column header looks like. Seven screens meant seven answers to "is the header
 * sticky", "can I order by this column", "what happens while the server is thinking" and "how do
 * I see what is being filtered out" — and the answers were mostly *no*, *no*, *nothing* and *you
 * don't*. This is one answer, once:
 *
 *  - `ListToolbar` — search, ordering, folded-away filters, the record count, and the summary of
 *    what is in force (via `ActiveFilters`), which is the piece that stops a filtered list from
 *    quietly lying about what it holds.
 *  - `ListTable` / `ListHead` / `ListRow` / `ListCell` / `ListRowLink` — a table whose headers
 *    stay put, whose columns can be ordered from the header, whose rows are clickable across
 *    their whole width while still holding exactly one link, and whose density is the member's
 *    choice rather than ours.
 *  - `ListSearch`, `ListSort`, `FilterGroup`, `FilterToggle`, `DensityToggle` — the controls.
 *
 * Ordering, filtering and paging all stay **server-side**: the URL is the truth about what is on
 * screen, so a list can be shared, bookmarked and walked back through with the browser's own
 * buttons. Nothing in here holds list state.
 */
export { default as ActiveFilters } from './ActiveFilters.vue';
export { default as DensityToggle } from './DensityToggle.vue';
export { default as FilterGroup } from './FilterGroup.vue';
export { default as FilterToggle } from './FilterToggle.vue';
export { default as ListCell } from './ListCell.vue';
export { default as ListHead } from './ListHead.vue';
export { default as ListRow } from './ListRow.vue';
export { default as ListRowLink } from './ListRowLink.vue';
export { default as ListSearch } from './ListSearch.vue';
export { default as ListSort } from './ListSort.vue';
export { default as ListTable } from './ListTable.vue';
export { default as ListToolbar } from './ListToolbar.vue';
