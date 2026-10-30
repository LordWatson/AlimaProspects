# Reporting & Analytics

> Status: **MVP (dashboard built; Phase 13 proper still open)** · Last updated: 2026-10-30

## Purpose

Shows a team how it is doing. It owns **no source-of-truth tables** — it reads leads, scores, the
pipeline, signals and AI usage from the modules that own them and assembles a read-model.

Built so far: the **dashboard** — headline KPIs, the prospecting funnel, a twelve-week sourced-vs-
qualified trend, the A–D fit mix, and breakdowns by pipeline stage, lead source and signal event
type. Still to come in **Phase 13**: date-range filters, CSV export of any report, source/ICP
performance and team-activity reports, AI usage in depth, and optional rollup tables.

## Code map

- **Backend:** `app/Modules/Reporting/`
    - `Services/DashboardReportBuilder` — orchestration; the single entry point. `TREND_WEEKS = 12`.
    - `Actions/` — one aggregation each: `BuildHeadlineKpisAction`, `BuildLeadFunnelAction`,
      `BuildLeadTrendAction`, `BuildGradeMixAction`, `BuildPipelineBreakdownAction`,
      `BuildSourceBreakdownAction`, `BuildSignalBreakdownAction`.
    - `Data/` — `DashboardReport`, `Kpi`, `FunnelStep`, `TrendPoint`, `BreakdownSlice`
      (snake_case properties: this shape is read straight off the Inertia page props).
    - `Enums/KpiFormat` — `count` | `money`.
    - `Support/WeeklyBuckets` — the trend's week cutting and its conditional-aggregation SQL.
- **Routes:** `app/Modules/Reporting/routes/web.php` — `GET dashboard` → `dashboard`
  (the name Fortify's redirects, the sidebar and several tests resolve by; it moved here from
  `routes/web.php`, where it had been a static `Route::inertia` since Phase 2).
- **Frontend:** `resources/js/pages/Dashboard.vue`; charts in `resources/js/components/charts/`
  (`StatTile`, `SparkLine`, `FunnelChart`, `TrendChart`, `GradeMixBar`, `BarListChart`), shown with
  sample data on the Design System page.
- **Shared:** `resources/js/types/reporting.ts`.
- **Tests:** `tests/Feature/Reporting/DashboardTest.php`, `tests/Unit/Reporting/WeeklyBucketsTest.php`.
- **Config / env vars:** none of its own. Reads `scoring.grades` indirectly via `ScoreGrade`.

## Data model

None. Every number is read live from other modules' tables:

| Chart              | Reads                                               |
| ------------------ | --------------------------------------------------- |
| KPIs               | `leads`, `lead_scores`, `signals`, `ai_requests`    |
| Funnel             | `leads`, `lead_scores` (+ Outreach, once it exists) |
| Trend              | `leads`, `lead_scores`                              |
| Fit mix            | `lead_scores` joined to the **active** `icps` row   |
| Pipeline / source  | `pipeline_stages`, `leads`                          |
| Signal event types | `signals`                                           |

Phase 13 may add an optional `report_rollups` table if live aggregation stops being fast enough.

## Key flows

**Rendering the dashboard.** `DashboardController` resolves the viewer's current team, authorises
`viewReports` on it, and calls `DashboardReportBuilder::forTeam()`. The builder runs the trend
first and hands it to the KPI Action, so the sparklines cost no extra query. Roughly a dozen
queries in total, all team-scoped twice over: inside `TeamContext` (the `BelongsToTeam` global
scope) **and** with an explicit `where team_id`.

**Bucketing the trend.** `WeeklyBuckets::lastWeeks()` cuts Monday-to-Monday weeks in the **team's**
timezone, then compares them as UTC instants (timestamps are stored UTC, displayed in the team
timezone). Filling the buckets uses conditional aggregation — one `sum(case when … end)` column per
week — rather than `group by` over a date expression, because `week()`/`strftime()` differ between
MySQL and SQLite. One query per series, index-friendly on `created_at`.

**The funnel is a cohort, not four unrelated counts.** "Strong fits" counts leads **created in that
week** that went on to grade A or B, so a bar says "of the 40 we found, 11 were worth calling" and
the second series stays bounded by the first.

## External dependencies

None. Reporting makes no network calls.

## Permissions

`TeamPolicy::viewReports()` — **any member of the team, Read-only included**. Reporting is a view of
work the team already owns; hiding the numbers from the person asked to read them would make the
role useless. A non-member is a 404 before the policy runs (the global scope hides the team's rows),
then a 403 from the policy.

## Known limitations / TODO

- **Outreach is not built (Phase 11)**, so the funnel's `contacted` and `replied` steps come back
  `available: false` with a null value and the screen draws an em dash, a padlock and "Arrives with
  Outreach". A `0` would read as "nobody replied", which is a different claim from "we are not
  tracking this yet" — do not let a later change quietly turn these into zeroes.
- **No date range.** Every figure is all-time except the trend (12 weeks), the leads delta (30 days
  vs the 30 before) and AI spend (this calendar month). Phase 13 adds the filter.
- **No CSV export.** Phase 13.
- **AI spend sums `cost_micros` across providers and labels it with the team's market currency.**
  If a provider ever bills in a different currency the total would mix units; Billing (Phase 16) is
  where that needs settling.
- **Live aggregation, no rollups.** Fine at MVP volumes; `report_rollups` is the escape hatch.
- **`WeeklyBuckets` caps the window at 52 weeks** — the length of its literal alias list, which
  exists because `selectRaw()` only accepts a `literal-string`. Longer windows need a different
  bucketing strategy, not a longer list.
- **The dashboard is web-only.** `DashboardReportBuilder` is deliberately free of the controller so
  the mobile dashboard (Phase 18) and a future `/api/v1/reports` can reuse it unchanged.

## Changelog

| Date       | Phase     | Change                                                                                                                                                                                                                                                                                                                       | Files                                                                                               |
| ---------- | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| 2026-10-30 | 13 (part) | Module created. Dashboard read-model (KPIs, funnel, 12-week trend, fit mix, pipeline/source/signal breakdowns) behind `DashboardReportBuilder` + 7 Actions; `dashboard` route moved out of `routes/web.php`; `TeamPolicy::viewReports`; hand-built chart components; `WeeklyBuckets` timezone-aware conditional aggregation. | `app/Modules/Reporting/**`, `resources/js/components/charts/**`, `resources/js/pages/Dashboard.vue` |
