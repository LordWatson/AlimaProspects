/*
 * Alima's chart set — hand-built, no charting dependency.
 *
 * Every mark is drawn with the validated `--chart-*` tokens (see `resources/css/app.css` and
 * `docs/brand/brand-tokens.md` §7.7) and follows the `dataviz` guidance: one y-axis, thin marks, a
 * recessive grid, a legend whenever there are two series, direct labels, and a text equivalent for
 * every chart. Bars are plain HTML — a bar is a box of a given width, and `div`s mirror under RTL
 * for free; only the trend line needs SVG.
 *
 * `ReportTableView` is the set's table form, for the reports that carry seven or eight measures per
 * row. A chart of eight series is a chart nobody reads, so the guidance's "a table view exists" is
 * the right answer there rather than a fallback.
 */
export { default as BarListChart } from './BarListChart.vue';
export { default as FunnelChart } from './FunnelChart.vue';
export { default as GradeMixBar } from './GradeMixBar.vue';
export { default as ReportTableView } from './ReportTableView.vue';
export { default as SparkLine } from './SparkLine.vue';
export { default as StatTile } from './StatTile.vue';
export { default as TrendChart } from './TrendChart.vue';
