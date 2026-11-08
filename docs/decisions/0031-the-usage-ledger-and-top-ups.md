# ADR 0031 — The usage ledger, plan gating for contact data, and top-ups

- **Status:** Accepted
- **Date:** 2026-11-08
- **Phase:** PL4 (`pre-launch.md`)
- **Supersedes / amends:** nothing. Extends ADR 0012 (billing) with a fifth and sixth answer; the
  record-over-ledger rule is unchanged.
- **Related:** ADR 0012 (billing, plan limits bite in the guards), ADR 0015 (plan-driven
  capabilities), ADR 0026 (PL scope — plan limits go in `config/billing.php`), ADR 0029 (contact
  data contracts), ADR 0030 (the enrichment waterfall).

---

## Context

PL3 shipped a waterfall that can spend real money per lead, behind two seams with permissive
defaults: `Enrichment\Contracts\CreditReserver` and `Enrichment\Contracts\ContactApprovalGate`. The
reserver's default is a no-op, which was safe only because PL2 ships no **enabled** vendor. The
moment one is enabled, nothing stands between a plan and a vendor bill.

Billing already meters three things — leads, AI credits, signal searches — and each reads an
authoritative table somebody else owns (`discovery_runs`, `ai_requests`, `signal_runs`). That is
`UsageLedger`'s whole design, and ADR 0012's standing rule: **nothing increments a counter at the
point of use**, because an increment the app forgot is an allowance a team silently overspends and
one made twice is an allowance they are wrongly refused.

A contact reveal has no such table. It is a discrete chargeable act with nothing behind it but the
fact that somebody clicked.

## Decisions

### 1. `usage_ledger` exists, and `team_allowances` does not

The plan asks for two tables: an append-only ledger and a `team_allowances` projection carrying
`included`, `topped_up`, `used` and `reserved`, rebuildable by a command.

**Only the ledger was built.** Every one of those four figures is derivable from the rows plus the
plan: `used` is the committed quantity, `reserved` is reserve minus commit minus release,
`topped_up` is the granted quantity, `included` is the plan's figure. A second table holding the
same answers is a second answer, and the one that drifts is the one a customer reads. `usage_meters`
remains the period snapshot, exactly as it is over the other three meters, and `usage:rebuild` is
unnecessary because there is nothing to rebuild.

The shape is deliberately **identical** to the three ledgers that already existed: append-only, read
by aggregate, never incremented. Only the source differs, which is the whole point of
`BillingMetric` — a reader asks the enum which table to aggregate
(`BillingMetric::isLedgered()`).

### 2. The mutex is the `usage_meters` row, not a projection row

A reserve needs serialising, or two clicks for the last unit both succeed. The lock is the
`usage_meters` row for the same `(team, metric, period)` — a row that already exists for every
tuple, uniquely keyed on precisely the thing being serialised, and whose meaning already is "this
team's position on this metric this period". `UsageAllowance::reserve()` takes it with
`lockForUpdate()` and then **recounts inside the lock**: the lock alone would serialise two requests
that both read "one left".

**And the recount has to be a locking read, which is the part that is easy to get wrong** (found by
`laravel-module-reviewer`). InnoDB's default isolation is REPEATABLE READ, and a transaction's
snapshot is taken at its **first consistent read** — which is `lockMeter()`'s `firstOrCreate`,
_before_ it blocks. A plain `SUM()` afterwards therefore counts from a snapshot taken before the
winner committed: both requests wait for the lock exactly as designed, both acquire it in turn, and
both see one unit left. `remainingUnderLock()` is one `lockForUpdate()` read over the period's rows
with the arithmetic in PHP, because a locking read in InnoDB is a **current** read.

`lockForUpdate()` is a no-op on SQLite, so the suite cannot prove this half — the sequential test
passes either way, and says so. A two-connection MySQL test in the style of
`SignalsMysqlDialectTest` is the thing that would catch a regression, and is an open item in
`docs/prelaunch/progress.md`.

### 3. A reserve settles by **writing beside itself**, and it settles once

A commit or a release is a new row carrying the same `reservation` uuid, never an update to the
reserve. "We held three and spent one" is a different fact from "we held one", and a disputed month
has to stay readable. A reservation that already has a commit or a release beside it is left alone,
which is what makes a retried worker safe — the failure mode is a team charged twice for one run.

**That check is taken under the same lock as the reserve**, and not because settling is contended:
"is it settled" followed by "write a settlement" is a check-then-act, and the waterfall's job
commits and then releases in a `finally`, so two settlements racing is the ordinary path rather than
an edge case. The lock is taken for the period **the hold belongs to** (`BillingPeriod::fromKey()`),
not for today's, which is a different row the moment a month turns over mid-retry. An index cannot
do this job: a reservation legitimately has three rows — the reserve, a commit and a release — so
`reservation` is deliberately **not** unique.

`commit()` and `release()` take the **handle alone**, with no team beside it. That is safer rather
than looser: the handle _is_ the reserve row, so the team, metric and period all come from the thing
being settled and there is no second parameter a caller can get wrong.

### 4. No cost column on the ledger

Vendor spend stays on the tables that own it: `enrichment_attempts.cost_micros` per call,
`ai_requests.cost_micros` per prompt. The margin guard sums those. A `cost_micros` on
`usage_ledger` would be a copy, and the copy would be the one that was wrong.

### 5. An absent allowance means **zero** for the six, and **unlimited** for the three

The asymmetry is deliberate, and is `StubSignalQuotaSource`'s argument again. Leads and AI credits
are things the product does, so a plan that does not mention them is a plan with no ceiling. A
reveal costs us money per unit, so a plan that does not mention it includes **none** — a plan config
that forgot one must fail closed rather than hand out an unmetered vendor bill.

For the same reason there is **no unlimited contacts plan**. Enterprise gets large numbers in
`config/billing.php` rather than nulls: a number somebody chose, where a null would be a vendor bill
nobody capped.

### 6. The fifth seam is bound **whatever `BILLING_ENFORCE_PLANS` says**

`CreditReserver` is the first of the seams ADR 0012 established to be bound outside the
`enforce_plans` check. A contact reveal is money leaving the building per unit, so the
reserve/commit path is a **cost guard** rather than a plan feature — the same argument
`config/billing.php` already makes about the AI credit allowance. What still follows the flag is the
_size_ of the allowance, which comes from `config/billing.php` through `PlanResolver` either way.

### 7. Contact metrics do not meter; they top up

`MetricUsage::overageAllowed` is **false** for every ledgered metric whatever the plan says. A
metered reveal is an uncapped vendor bill. The commercial answer is a top-up: extra allowance bought
outright, before the spend rather than invoiced after it.

Three metrics are toppable — email reveals, phone reveals, people searches. Not leads or signal
searches, because those are standing instructions to spend and more of one is a plan change; not
export rows, because charging for a team's own data on its way out is not a product.

**Top-ups lapse with the period they are granted in and do not roll over.** The alternative needs
consumption tracked per grant across periods, and the way that fails is resurrecting units somebody
has already spent. _This is a commercial call and a cheap one to revisit; it is recorded here so the
revisit is deliberate._

### 8. The client names an **option**, never a price

`POST /billing/topup` carries a metric and an index into `config('billing.topups')`. A request that
could name a Stripe price could name a cheaper one; one that could name a quantity could name a
larger. An option whose Stripe price is unset **does not exist**, so a deployment that has not
created the prices has no button rather than a button that fails at Stripe with a customer's card
details already typed in.

Nothing is granted at purchase. The allowance arrives on `checkout.session.completed`, idempotent on
the **Stripe event id** via `usage_ledger.idempotency_key` — the event id rather than the session id
because it is the thing Stripe's own idempotency is expressed in, so a replay from the dashboard is
recognised too. A session that completes **unpaid** grants nothing.

The `exists()` check before the insert is a courtesy; the **unique index is the guarantee**. Losing
that race throws, so `grant()` catches `UniqueConstraintViolationException` and returns false — an
exception here becomes a 500, and Stripe reads a 500 as "retry" and retries for three days over an
event it has already had granted. The grant and its audit entry are in one transaction, because a
failure writing the audit row would otherwise leave the units granted with no record, and the retry
would then read "already granted" and write no record either.

**The listener is on `WebhookReceived`, not `WebhookHandled`.** Cashier fires `WebhookHandled` only
for event types it has a handler method for, and `checkout.session.completed` is not one of them —
so a listener on that event would never run at all: a paid top-up granting nothing, silently, with a
200 going back to Stripe.

### 9. There are no new gates

The plan asks for `can-reveal-phone`, `can-export`, `can-sync-crm`. There is one answer to "may this
team spend this" and it is `UsageAllowance::remaining()`, asked at the point of spend. A gate would
be a second place for the same answer to live, and `Capability`'s own rule is that every case on it
is actually enforced — `Capability::Enrichment` and `Capability::CsvExport` already exist and are
already asked.

### 10. Rollover needs no code

An allowance is derived per period from the plan, so a new month starts fresh on its own. There is
no job to grant it and nothing to be idempotent about — which is the record-over-ledger rule paying
out one more time.

### 11. The margin guard stops **one thing**

`MarginGuard` compares vendor spend over the period (`enrichment_attempts` + `ai_requests`) against
the plan's list price, and above `billing.margin_guard.max_vendor_cost_ratio` (0.35) it refuses
**phone reveals** and nothing else. A guard that stopped everything would turn a pricing problem
into an outage for a customer who is paying us; stopping the one line item that moves the ratio buys
the time to have the conversation.

A team with no price — free, trialling, Enterprise-on-application — is **exempt**: the ratio has no
denominator, and reading "any cost at all" as a breach would refuse the first phone reveal of every
trial. The check runs **before** the hold, so a refusal does not leave a reservation nobody settles.

Discovery and signal spend are deliberately out of the sum: they are the cost of _finding_ a
company rather than of enriching one, they are bounded by their own quotas, and including them would
let a team's own discovery allowance switch their phone reveals off.

`shortcut: the ratio is computed without converting currency — the vendors bill in USD and the plans
are priced in GBP, so it reads low by roughly the spread and the limit is slightly permissive.
Upgrade when a second billing currency ships, or when Admin's PlatformMoney conversion moves
somewhere both can share.`

### 12. `enrichment_attempts` joined the platform cost rollup

`PlatformRollup::rollUpTeamCost()` now sums it alongside `discovery_runs` and `signal_runs`. Without
that, the margin screen would have flattered every tenant by the one cost that scales with use —
authoritative-looking and short. This is the risk ADR 0016's own note warned about, arriving.

## Consequences

- One new table, nine `BillingMetric` cases, and one new binding. No module gained an import of
  `App\Modules\Billing`.
- `UsageLedger::used()`, `summary()` and `sync()` now cover nine metrics; the billing screen filters
  by capability as it already did, so a plan without Enrichment shows no reveal bars.
- `UsageAlerter` covers the six for free: it is generic over whatever `summary()` returns, so the
  80% and 100% notices to Owners and Admins work with no new code.
- Overage reporting is inert for the six by construction: `reportMetric()` returns early with no
  configured Stripe price, and none is configured.
- `PlanData` gained a `contacts` map. It is **not grantable** through `withAllowances()`, because
  `plan_grants` has no column to read.

## What was deliberately not built

| Asked for                       | Why not                                                                                                                                                                                                                                                                                   |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `team_allowances` projection    | §1 — a second answer to "what's left".                                                                                                                                                                                                                                                    |
| `usage:rebuild`                 | Nothing to rebuild.                                                                                                                                                                                                                                                                       |
| `can-*` gates                   | §9 — `remaining()` is the one answer.                                                                                                                                                                                                                                                     |
| Rollover job                    | §10 — derived per period.                                                                                                                                                                                                                                                                 |
| AI tokens moved into the ledger | `ai_requests` is authoritative and recorded by the work itself (recon.md §3, PL4). Nothing to migrate.                                                                                                                                                                                    |
| Low-balance **banner**          | The bars already turn warning-coloured and name what is left, and the notification already sends.                                                                                                                                                                                         |
| Admin margin **alert**          | Deferred: the margin tab is sorted worst-first, and a platform-admin alert channel is new mechanism.                                                                                                                                                                                      |
| Breach list screen              | Deferred for the same reason; the threshold is in config and a breach sorts to the top.                                                                                                                                                                                                   |
| Inline reveal "remaining" count | Deferred: a usage query per lead page. The refusal code already names which allowance ran out.                                                                                                                                                                                            |
| Refund / dispute handling       | **Deferred, and recorded as a gap.** Nothing listens for `charge.refunded` or `charge.dispute.created`, and `quantity` is unsigned so `LedgerDirection::Adjustment` can only add — units are clawed back by hand. The fix is a signed adjustment or a `Revoke` direction plus a listener. |
| MySQL concurrency test          | Deferred to PL12; see §2. The code is right, the suite cannot prove it.                                                                                                                                                                                                                   |

## Risks accepted

**A top-up's quantity and its price are two sources of truth** (`laravel-module-reviewer`). Stripe is
charged `[$price => 1]` — one unit of a price that _means_ "+250 email reveals" — while the units
granted come from `config('billing.topups.*.quantity')` through the session metadata. Editing the
config quantity without changing the Stripe price charges one amount and grants another, silently
and in whichever direction the edit went.

Not closed here, because every way of closing it is worse than the risk: reconciling at grant time
needs an `expand` call on the session's line items inside the webhook, and putting the quantity in
Stripe price metadata moves the number out of the repository. The mitigation is that the two live
next to each other in one config block with a comment saying so, and that creating these prices is
an explicit checklist item in `docs/prelaunch/progress.md` naming the quantity for each.

**The margin guard's ratio includes AI spend** (§11), so a team on a cheap plan with heavy AI use
can lose phone reveals for a reason that is not about phone reveals. That is intended — it is one
budget — but the refusal reads as a _temporary_ unavailability rather than a spent allowance, which
is why it has its own code (`billing.margin_guard.<metric>`) rather than reusing
`billing.no_allowance.<metric>`.

**`PlatformRollup::rollUpTeamCost()` uses an inclusive `whereBetween`** where
`EnrichmentCostCounter` uses a half-open range, so the new enrichment source inherits the
double-counted boundary row the other two already have. Pre-existing, one microsecond wide, and left
alone rather than changed under a phase that is not about it.

## Found while building this, not fixed here

`Billing\Listeners\SyncSubscriptionFromStripe` listens on `WebhookHandled` and includes
`invoice.payment_failed` in its allow-list — but Cashier has no `handleInvoicePaymentFailed`, so
that event never reaches it and the "tell the Owners their card was declined" notification added in
Phase 19b **has never fired**. The fix is a second listener on `WebhookReceived`, not a change to
this one (moving it would make it run before Cashier syncs the subscription row). Recorded in
`docs/prelaunch/progress.md`.
