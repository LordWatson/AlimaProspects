# ADR 0016 — Platform reporting: its own questions, over a nightly rollup

- **Status:** Accepted
- **Date:** 2026-11-01
- **Relates to:** [ADR 0012](0012-billing-and-plan-enforcement.md) (`usage_meters` as a derived record), [ADR 0014](0014-admin-panel-bespoke-inertia.md) (the bespoke panel), [ADR 0015](0015-plan-capabilities-and-platform-settings.md) (platform settings)

## Context

Phase 19c had to answer two questions the business cannot run without — **"is this business working?"** and **"is this customer profitable?"** — and the obvious implementation for both was wrong in the same way.

The product already has a reporting module. Phase 13 built `App\Modules\Reporting`: eleven reports, a window with a documented cohort rule per figure, a chart set, and a CSV exporter. Every one of its builders is scoped to one team, and the panel has a middleware (`PlatformContext`) whose entire job is to remove that scope. Putting the two together is a two-line change.

It is also a trap, and the trap is worth stating precisely because it does not look like one. Reporting's figures are not _team-filtered versions of platform figures_. Each one answers a question **about a team**, with a cohort rule written for that: "of the prospects **this team** sourced in this window, how many has it contacted". Unscoped, that becomes "of every prospect anybody sourced, how many has anybody contacted" — a number that is arithmetically fine, means something quite different, and has **no customer to notice it is wrong**. A tenant whose dashboard misreports gets a support ticket within the day. A platform figure that quietly answers the wrong question gets quoted in a board paper.

The second problem is physical rather than conceptual. Every tenant-facing query in this product is narrowed by `team_id`, which is also the leading column of the index serving it. A platform aggregate is narrowed by nothing: **a cross-tenant sum over `ai_requests` is the one query in the product with no `team_id` in its `where` clause**, and `ai_requests` grows with every scored lead, every drafted message and every insight, for every tenant, for ever. It is fast today and will not stay fast, and the day it stops being fast is the day somebody opens a dashboard rather than the day a test fails.

The third is that gross margin — the figure the business actually lives on — crosses a boundary nothing else in the product crosses. Plan revenue is integer **minor units** of `billing.currency`; provider cost is integer **cost micro-units** of whatever currency the provider billed, recorded that way since Phase 5 precisely so a sub-penny AI call is not rounded away. The two are four orders of magnitude and one FX rate apart, and a margin figure has to put them on one scale.

## Decision

### 1. Platform reporting asks its own questions, in its own builders

`Admin\Services\Platform\PlatformReportBuilder` and `PlatformMarginBuilder` are new code. They do not call Reporting's Actions, and `Admin\Enums\PlatformReport` is a **separate enum** from `Reporting\Enums\ReportKey`.

The enum split matters as much as the builder split. Adding `margin` to `ReportKey` was the shortcut, and it would have put a cross-tenant figure inside a module whose entire contract is that it answers for one tenant — then leaked it into `ReportKey::tabular()`, the tenant dashboard's tabs and `/api/v1/reports`' validation rules, every one of which a **customer** reads.

What _is_ reused is the plumbing and the discipline, because duplicating those is how two sets of numbers come to disagree:

| Reused                                                                 | Why                                                                                                                                                                                                                 |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Reporting\Support\DateRange` and its UTC rule                         | A window must mean the same thing on both sides of the product.                                                                                                                                                     |
| `Reporting\Support\Ratio::of()`                                        | Null over a zero denominator. A `0%` must only ever mean zero.                                                                                                                                                      |
| `ReportColumn` / `ReportRow` / `Kpi` / `FunnelStep` / `BreakdownSlice` | One table shape, one validated chart set, no fourteenth chart.                                                                                                                                                      |
| `ReportCsvExporter`                                                    | It grew `rowsFor()`/`filenameFor()` rather than being copied. What would have drifted between two writers is the **formula hardening** — and a tenant's company name reaches these files through the margin report. |

Each platform figure's counting rule is written on its `PlatformMetric` case and rendered on the screen beside the number. That is the direct mitigation for the first problem above: a figure cannot be read without its definition.

### 2. The figures come from a rollup, and the rollup is a record rather than a counter

`platform_daily_metrics` — `(date, metric, dimension) → value`, unique on the three — is written nightly by `platform:roll-up` from the ledgers.

**This is ADR 0012's rule for `usage_meters`, applied again**, and deliberately so rather than by analogy. Nothing in the application increments a value here; the command derives every one of them and can rebuild any window with `--days`. The consequence is the one that matters: a crashed worker leaves the business's numbers **unwritten**, not **wrong**, and the fix is to run the command again. An incrementing counter would have had no such property, and no way to tell the two states apart.

One generic table rather than one per figure, because the alternative was thirty tables nobody would keep in step — and `dimension` is a plain string so "AI spend by model" is a new enum case rather than a migration.

Three properties follow from "a record, not a counter", and each is enforced by a test rather than by intent:

- **A metric's day is replaced atomically.** An upsert alone would leave yesterday's `google_places` row behind the day Google Places made no calls, and the figure would stay on the screen for ever.
- **A snapshot is never summed.** Thirty days of MRR summed is thirty months of revenue. `PlatformMetric::isSnapshot()` decides and the _store_ applies it, so no builder can collapse a series the wrong way.
- **A snapshot that empties reads as nothing.** Snapshots write only non-zero dimensions, so "the last populated day" is not "the last day we know about" — the night the last paying tenant cancels, MRR writes no rows and a naive reader reports the old figure for as long as the window holds it. Two bookkeeping markers (`rollup_day`, `rollup_state_day`) make freshness a fact about the rollup rather than about one metric. **A frozen number is worse than a zero.**

### 3. Three shapes of date, because a backfill cannot invent what nobody recorded

| Shape                     | Example                                                 | What a backfill does                                                                                                                                     |
| ------------------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Event**                 | signups, AI cost, messages sent                         | Rewrites it. Perfectly recomputable while the rows survive.                                                                                              |
| **Reconstructable state** | MRR, paying tenants                                     | Rebuilds it from the **grant and subscription windows** — not from `PlanResolver`, which answers for _now_ and would stamp today's plan onto last month. |
| **Point-in-time**         | dunning, seats, allowance consumption, reported overage | **Skips it.** Nothing historises `stripe_status`, `team_user` or `usage_meters.used`.                                                                    |

The third row is the honest one: recomputing those for an old day would write today's state onto it, so the command neither writes nor deletes them and says which days it treated as current.

A fourth shape sits across this: the **onboarding funnel is cohort-shaped**. Its rows are dated by the day a cohort signed up, and a team that signed up a fortnight ago and runs its first discovery tonight changes a row dated a fortnight ago — so those six metrics are recomputed over a trailing window every night. Treated as event-shaped, every step after the first would permanently under-report, which is the one report that is supposed to tell us whether the product's first hour works.

### 4. The rollup runs before the sweeps, and that is a scheduling contract

`platform:roll-up` is scheduled at **02:40**, ahead of the Signals prune (03:20), Compliance's retention sweep (03:40) and the insights prune (03:50). Those sweeps delete the rows the figures are computed from, and **a period that has been swept cannot be recomputed** — the same constraint `billing:sync-usage` runs hourly for (ADR 0012).

A comment would not have held. `PlatformRollupTest` asserts the ordering against the **live schedule**, so a later phase moving a prune earlier fails a test rather than silently losing a month of the business's history.

### 5. Margin converts on a configured rate, and shows what it could not convert

One reporting currency (`admin.reporting.currency`), and a table of **configured** rates (`admin.reporting.fx`) rather than a live FX call inside a nightly command for a figure whose third significant digit nobody acts on. Every money screen prints the rates it used.

The important half is the refusal: a currency with no configured rate is returned **alongside** the total as `ConvertedCost::$unconverted`, every screen that shows the one shows the other, and when it is non-zero the margins on the page are labelled a **ceiling**. Likewise spend with no tenant behind it (`ai_requests.team_id` is nullable) is its own `unattributed` row and is never divided across customers — that would flatter every margin equally and be impossible to notice.

Revenue is **prorated from the daily snapshots** rather than taken as MRR, so a window of any length and a plan change mid-window are both accounted for day by day.

## Consequences

**Good.**

- The two questions have answers, and each answer carries its own definition.
- The expensive query runs once a night, off-peak, on indexes added for it — not when somebody opens a page.
- Any window can be rebuilt from the ledgers while they survive, so a scheduler outage is recoverable rather than a permanent hole.
- Reporting stays a tenant module. Nothing a customer can reach gained a cross-tenant concept.

**Costs, accepted.**

- **Figures are as fresh as the rollup**, which is why "figures as at" is in the page header rather than a footnote, and why the schedule screen exists.
- **Two money figures are a floor.** A plan priced _on application_ contributes zero to MRR, because the contract's number is not in the system; and `discovery_runs`/`signal_runs` record a cost with no currency column, so theirs comes from config. Both are recorded in `docs/modules/admin.md` rather than left to be discovered.
- **Only the margin reports split cost by currency.** `TeamCostMicros` carries it in the dimension; the cost, AI-cost and lead-cost reports aggregate across whatever the ledger held. Exact while each ledger bills in one currency, which is today's case.
- **A second place that knows about plans.** The rollup reconstructs the plan in force on a past day instead of asking `PlanResolver`, so the precedence (grant → subscription → trial → default) is written down twice. The duplication is deliberate — the resolver answers for _now_ by design — and the test that asserts the ordering is what keeps them honest.
- **One more nightly command** in a list that is getting long enough to need its own ordering story. Phase 20's Pulse work is where that belongs.

## Alternatives considered

- **Reuse Reporting's Actions with the scope removed.** Rejected above: it answers a different question with no customer to notice, and it leaves the slowest query in the product on the request path.
- **Compute platform figures live, with a cache.** A cache in front of an unbounded scan is a slow query you meet on a cold key, usually during an incident. It also cannot survive retention: a closed month's cost is gone once the sweep has run, and a cache has nothing to recompute from.
- **A table per figure.** Thirty migrations and thirty things to keep in step; adding a breakdown would be a schema change.
- **Materialised views.** Not portable across the SQLite suite and MySQL production, and the backfill story is worse: a view cannot remember a month whose source rows have been pruned.
- **An FX API.** A live third-party dependency inside a nightly rollup, for precision nobody acts on, and one more provider to put a circuit breaker around.
