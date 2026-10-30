# Reporting & Analytics

> Status: **Complete (Phase 13)** · Last updated: 2026-10-30

## Purpose

Shows a team how it is doing. It owns **no source-of-truth tables** — it reads leads, scores, the
pipeline, signals, outreach, the lead timeline and AI usage from the modules that own them and
assembles a read-model.

Eleven reports, one window, one download path. The dashboard shows headline KPIs, the prospecting
funnel, a sourced-vs-qualified trend, the A–D fit mix, breakdowns by pipeline stage / lead source /
signal event type, and four tabular performance reports (ideal-customer performance, outreach
performance, team activity, AI and data usage). Every one of them can be exported as CSV.

## Code map

- **Backend:** `app/Modules/Reporting/`
    - `Services/DashboardReportBuilder` — orchestration; the single entry point. Builds the whole
      report in one pass so the export cannot disagree with the screen.
    - `Services/ReportTables` — a **pure projection**: turns any `ReportKey` into a `ReportTable`,
      whether it is one of the four tables the builder produced or a chart that needs rearranging
      into rows. This is what makes "CSV export of any report" one code path.
    - `Services/ReportCsvExporter` — writes a `ReportTable` as CSV. Numbers stay bare (money in
      major units, rates out of a hundred, the unit in the header) so a spreadsheet can sum them;
      words go through `__()`; a null is an empty cell, never a zero.
    - `Actions/` — one aggregation each: `BuildHeadlineKpisAction`, `BuildLeadFunnelAction`,
      `BuildLeadTrendAction`, `BuildGradeMixAction`, `BuildPipelineBreakdownAction`,
      `BuildSourceBreakdownAction`, `BuildSignalBreakdownAction`, `BuildIcpPerformanceAction`,
      `BuildOutreachPerformanceAction`, `BuildTeamActivityAction`, `BuildAiUsageAction`; plus
      `ExportReportAction` (audit + streamed download).
    - `Data/DashboardReport` carries **two** emptiness flags, and they are different questions:
      `has_data` (this window has something) and `has_ever_sourced` (the account has ever had
      anything). Only the second may turn on the first-run explainer — a team with a thousand leads
      that filters to a quiet week has an empty window, not an empty account.
    - `Data/` — `DashboardReport`, `Kpi`, `FunnelStep`, `TrendPoint`, `BreakdownSlice`,
      `ReportFilters`, `ReportTable`, `ReportColumn`, `ReportRow`
      (snake_case properties: these shapes are read straight off the Inertia page props).
    - `Enums/` — `KpiFormat` (`count` | `money`), `ColumnFormat` (`text` | `count` | `money` |
      `percent` | `date`), `ReportRange` (the filter's presets + `custom`), `ReportKey` (the eleven
      reports), `TrendGranularity` (`day` | `week` | `month`).
    - `Support/DateRange` — a resolved window and what it selects (see **Key flows**).
      `Support/TrendBuckets` — the trend's bucket cutting and its conditional-aggregation SQL.
      `Support/Ratio` — one rule for every rate. `Support/ReportFilterOptions` — the control's
      option lists, derived from the enums.
    - `Http/Requests/DashboardRequest` → `ExportReportRequest extends` it, so the window is
      validated identically for the screen and the download.
- **Routes:** `app/Modules/Reporting/routes/web.php` — `GET dashboard` → `dashboard`
  (the name Fortify's redirects, the sidebar and several tests resolve by; it moved here from
  `routes/web.php`, where it had been a static `Route::inertia` since Phase 2), and
  `GET reports/export` → `reports.export`, throttled by `reports-export`.
- **Frontend:** `resources/js/pages/Dashboard.vue`; charts in `resources/js/components/charts/`
  (`StatTile`, `SparkLine`, `FunnelChart`, `TrendChart`, `GradeMixBar`, `BarListChart`,
  `ReportTableView`) and the filter/export controls in `resources/js/components/reporting/`
  (`ReportRangeFilter`, `ReportExportMenu`), all shown with sample data on the Design System page.
- **Shared:** `resources/js/types/reporting.ts`.
- **Tests:** `tests/Feature/Reporting/{DashboardTest,PerformanceReportsTest,ReportExportTest}.php`,
  `tests/Unit/Reporting/{DateRangeTest,ReportFiltersTest,TrendBucketsTest}.php` plus
  `tests/Feature/Reporting/ReportWindowTest.php`, which is the one that runs against a **non-UTC
  team** — see the UTC note under Key flows; without it the whole suite is green while every Dubai
  team's dashboard is four hours out.
- **Config / env vars:** `config/reporting.php` — `trend_periods` (`REPORTING_TREND_PERIODS`, the
  fallback bucket count for an open-ended window) and `export_rate_limit`
  (`REPORTING_EXPORT_RATE_LIMIT`). The custom-range ceiling is `DateRange::MAX_DAYS`, a constant
  rather than a key: it is a product decision, not an environment one. Reads `scoring.grades`
  indirectly via `ScoreGrade`.

## Data model

None. Every number is read live from other modules' tables:

| Report             | Reads                                                     |
| ------------------ | --------------------------------------------------------- |
| KPIs               | `leads`, `lead_scores`, `signals`, `ai_requests`          |
| Funnel             | `leads`, `lead_scores`, `contact_attempts`                |
| Trend              | `leads`, `lead_scores`                                    |
| Fit mix            | `lead_scores` joined to `leads` and the **active** `icps` |
| Pipeline / source  | `pipeline_stages`, `leads`                                |
| Signal event types | `signals`                                                 |
| ICP performance    | `lead_scores` joined to `leads` + `icps` (all profiles)   |
| Outreach           | `contact_attempts`                                        |
| Team activity      | `lead_activities`, `team_user`                            |
| AI usage           | `ai_requests`                                             |

`report_rollups` remains the escape hatch if live aggregation stops being fast enough; nothing above
`DashboardReportBuilder` would have to change to add it.

## Key flows

**Rendering the dashboard.** `DashboardController` validates the window (`DashboardRequest`),
resolves the viewer's current team, authorises `viewReports` on it, and calls
`DashboardReportBuilder::forTeam($team, $filters)`. The builder resolves the filters into a
`DateRange`, runs the trend first and hands it to the KPI Action so the sparklines cost no extra
query, then the other nine aggregations. Around two dozen queries, all team-scoped twice over:
inside `TeamContext` (the `BelongsToTeam` global scope) **and** with an explicit `where team_id`.

**One window, and what it selects.** `DateRange` is a **half-open** `[from, to)` interval of UTC
instants, cut in the team's timezone and never running past _now_. Half-open because consecutive
windows must tile the timeline without a seam — that is what makes `previous()` (the equal-length
window ending where this one opens) a true like-for-like rather than an off-by-one, and what lets
"this month so far" be compared with the same number of days before it instead of the whole of last
month.

The window picks a **cohort of leads** — those sourced inside it — and every lead-derived figure
reports on that cohort: the funnel, the trend, the leads and strong-fit tiles, the fit mix, the
source and pipeline breakdowns, and ICP performance. So "contacted" means _of the leads we sourced
in this window, how many have we approached_, and the funnel stays monotonic. Reports that are
about activity rather than about leads are scoped by their own event time instead: AI usage and
spend by when the call was made, team activity by `lead_activities.occurred_at`, outreach
performance by `contact_attempts.occurred_at`, signals by when the signal was found. One rule, with
the exceptions written down, because a dashboard whose charts disagree is worse than no dashboard.

Labels follow the window rather than staying put: unfiltered the leads tile is "Leads in your list"
(an inventory figure), and under a window it is "Prospects sourced", because that is what the number
then is. All time has no window before it, so it carries no delta at all.

**Bucketing the trend.** `TrendGranularity::for()` picks the bucket width from the window's length —
up to 35 days daily, up to 364 days weekly, longer than that monthly — which is the 52-bucket alias
ceiling read backwards. `TrendBuckets::forRange()` cuts the buckets in the team's timezone and
**clamps the first and last to the window**, so the bars add up to the same total the funnel and the
KPI tiles report; a half-week hanging off the front of a "last 30 days" filter would quietly
disagree with every other figure on the screen. An open-ended window has no length to divide, so it
falls back to the most recent `reporting.trend_periods` weeks and the chart says so.

Filling the buckets uses conditional aggregation — one `sum(case when … end)` column per bucket —
rather than `group by` over a date expression, because `week()`/`strftime()` differ between MySQL
and SQLite. One query per series, index-friendly on `created_at`.

**Binding the window.** `DateRange::constrain()` converts both bounds to **UTC** before they reach
the query, and `BuildLeadTrendAction` does the same with the trend's lower floor. This is not
decoration: the bounds are Carbon instances in the _team's_ timezone, and Laravel binds a
`DateTimeInterface` by calling `->format()` on it with no conversion at all
(`Connection::prepareBindings`). An unconverted Dubai instant compares wall-clock against UTC
columns and shifts every figure on the dashboard four hours late at both ends — silently, and only
outside UTC. Anything new that filters on a timestamp here must convert too.

**The funnel is a cohort, not four unrelated counts.** Each step counts **leads** from the same
cohort, so a lead emailed three times is one lead contacted; "Replies" counts every outcome
`ContactOutcome::isResponse()` is true for, an opt-out included — it is a reply, and a painful one,
and hiding it would flatter the reply rate by dropping exactly the responses worth learning from.
A bounce is not a reply: nobody read it.

**It is not a strict ladder.** `sourced ≥` every other step and `contacted ≥ replied`, but "strong
fits" and "contacted" are **parallel cuts** of the cohort rather than consecutive stages: a team is
free to call a C-grade company and to skip an A, so contacted can exceed strong fits and the chart
can widen in the middle. That is a fact about how the team worked, not a bug to normalise away —
restricting contacted to the strong-fit subset would make the picture tidier and the number wrong,
and a bulging middle bar is telling the team it is calling prospects its own ICP does not rate.

**Exporting.** `GET /reports/export?report=<key>&range=…` validates the same window, authorises the
same `viewReports` policy (Read-only members included — an export is the report in a different
window, and gating the file behind a stricter role than the chart would stop the person asked to
read the numbers taking them to a meeting), then `ExportReportAction` **builds the whole report and
projects the one table**. That costs a dashboard's worth of aggregates for a single download, and it
is the point: an export that re-queried just its own slice could disagree with the screen it was
launched from. The response is streamed with a UTF-8 BOM (Excel reads the file as the system
codepage otherwise, which is the difference between a readable Arabic report and a screen of
mojibake), every cell is run through `CsvCell::neutralise()` so a spreadsheet cannot execute one as
a formula, and the export is audited (who, which report, which window, how many rows) without
copying the numbers into the trail.

**Every report is a table.** `ReportKey` is a closed set and `ReportTables` must turn each case into
a `ReportTable`, so a chart cannot ship unexportable. Column labels are always translation keys;
whether the **cells** are too is the `translated` flag — a channel or a role is one of Alima's own
words and must be translated, a member's name or a prompt key is data and must be printed exactly as
stored.

## External dependencies

None. Reporting makes no network calls.

## Permissions

`TeamPolicy::viewReports()` — **any member of the team, Read-only included**, for both the dashboard
and the CSV export. Reporting is a view of work the team already owns; hiding the numbers from the
person asked to read them would make the role useless. A non-member is a 404 before the policy runs
(the global scope hides the team's rows), then a 403 from the policy.

## Known limitations / TODO

- **A null rate is not a zero.** `Ratio::of()` returns null when there is nothing to divide by, the
  screen draws an em dash and the CSV leaves the cell empty. A reply rate of zero means nobody
  replied; a reply rate over no attempts means nobody was asked. Do not let a later change collapse
  the two — it is the same rule `FunnelStep::available` exists for.
- **AI spend sums `cost_micros` across providers and labels it with the team's market currency.**
  If a provider ever bills in a different currency the total would mix units; Billing (Phase 16) is
  where that needs settling.
- **Sub-penny AI calls read as 0 in the cost column.** Micros are floored to minor units per row, so
  a single cheap call rounds away even though the column's total does not. Truthful about the call,
  misleading if read as the only number — which is why the token columns sit beside it.
- **Live aggregation, no rollups.** Fine at MVP volumes; `report_rollups` is the escape hatch. An
  export runs the whole dashboard, so it is the first thing that will feel slow.
- **`TrendBuckets` caps the window at 52 buckets** — the length of its literal alias list, which
  exists because `selectRaw()` only accepts a `literal-string`. A monthly window longer than that
  (four years and change) drops its oldest buckets rather than refusing to draw.
- **The custom range is capped at `DateRange::MAX_DAYS`** (two years and a day, so "this year
  against last year" fits) and clamped from the end date rather than rejected, so a stale bookmark
  still shows a dashboard.
- **Team activity reads the lead timeline**, so it reports work done _to a lead_. A member who spent
  the week writing an ICP or configuring watches shows as idle. Widening it means either a
  general-purpose activity log or joining six tables; the timeline is the honest cheap answer for
  now, and the column is named "Companies worked" rather than "Productivity".
- **Team activity lists current members only**, so work done by somebody who has since left the team
  is counted in no row. The total therefore under-reports a period that included a departure.
- **The AI cost column floors each row independently**, so the rows can sum to a few pence less than
  the headline spend tile, which floors the grand total once. Both are truthful about what they
  measure; they are not meant to be reconciled by addition.
- **No report is cached.** Every dashboard view re-aggregates. Caching would need invalidating on
  every lead, score, attempt and AI call, which is more moving parts than the queries cost today.
- **The dashboard is web-only.** `DashboardReportBuilder` is deliberately free of the controller so
  the mobile dashboard (Phase 18) and a future `/api/v1/reports` can reuse it unchanged — including
  the filters, which are built from a plain array (`ReportFilters::fromArray()`) rather than from a
  FormRequest.

## Changelog

| Date       | Phase     | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Files                                                                                                                                                                           |
| ---------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-30 | 13        | Phase complete, then hardened against the module and brand reviews: the window is bound in **UTC** (an unconverted team-timezone instant shifted every figure four hours for a Dubai team), numeric CSV cells are no longer formula-neutralised (a negative delta was being written as text), the weekly bucket ceiling allows for a mid-week start, a future-dated window falls back to all time, the joins pin `leads`/`icps` to the team, an empty **window** no longer shows the first-run explainer (`has_ever_sourced`), the trend's geometry mirrors properly under RTL, and the report switcher dropped a half-built ARIA tab pattern. Date-range filter (`ReportRange`/`ReportFilters`/`DateRange`, one window scoping the whole screen, cohort rule documented); adaptive trend granularity (`TrendBuckets` + `TrendGranularity` replace `WeeklyBuckets`); the funnel's contacted/replied halves wired to `contact_attempts` (+ `ContactOutcome::isResponse()`, `Lead::contactAttempts()`); four new tabular reports (ICP performance, outreach performance, team activity, AI usage); `ReportTable`/`ReportTables`/`ReportCsvExporter` + `GET reports/export` with audit and a per-member rate limiter; `CsvCell` extracted to Core and shared with the leads exporter; `ReportTableView` + filter/export controls. | `app/Modules/Reporting/**`, `app/Modules/Core/Support/CsvCell.php`, `config/reporting.php`, `resources/js/components/{charts,reporting}/**`, `resources/js/pages/Dashboard.vue` |
| 2026-10-30 | 13 (part) | Module created. Dashboard read-model (KPIs, funnel, 12-week trend, fit mix, pipeline/source/signal breakdowns) behind `DashboardReportBuilder` + 7 Actions; `dashboard` route moved out of `routes/web.php`; `TeamPolicy::viewReports`; hand-built chart components; `WeeklyBuckets` timezone-aware conditional aggregation.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | `app/Modules/Reporting/**`, `resources/js/components/charts/**`, `resources/js/pages/Dashboard.vue`                                                                             |
