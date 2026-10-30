# ADR 0012 — Billing: the team pays, and limits bite at the point of spend

- **Status:** Accepted
- **Date:** 2026-10-30
- **Extends:** ADR 0002 (module structure), ADR 0006 (multi-team tenancy)
- **Supersedes nothing**

## Context

Phase 16 had to monetise a product whose costs are already metered in two places — AI
spend in `ai_requests.cost_micros` since Phase 5, discovered companies in
`discovery_runs.leads_created` since Phase 7 — and which already had **seams** waiting
for a plan to arrive: `AiPlatform\Contracts\CreditSource`,
`LeadSourcing\Contracts\LeadQuotaSource`, both shipped with permissive stubs.

Four questions had answers that were not obvious, and each one would have been
expensive to change later.

### 1. Who is the customer: the user, or the team?

Laravel Cashier's default is a `User`. But a plan, its seats and its allowances all
describe a **tenant**: one person may own two teams on two different plans, and two
owners may share one subscription. A `stripe_id` on `users` makes the subscription
follow whoever happened to click subscribe, and breaks the first time either of those
happens.

### 2. Where does a plan limit get enforced?

`mvp.md` specified an `EnforcesPlanLimits` **middleware**. Middleware only exists where
a request does. Three of the four places a limit actually has to bite do not have one:

- a queued discovery job (`DiscoverLeadsJob`),
- a scheduled signals watch (`RunSignalWatchAction`),
- the nightly AI insight generation.

A middleware would have guarded the one case that is cheapest to guard — somebody
clicking a button — and left the three that genuinely run up a bill.

### 3. What window is an allowance measured over?

The Stripe billing cycle is the conventional answer. It also means the reset day moves
whenever a team changes plan, a trial and a subscription measure different windows, and
every usage query needs a round trip to Stripe to find out where the window starts.

### 4. Is usage a counter the app increments, or a record it derives?

A counter is the obvious shape and the wrong one: an increment the app forgets is an
allowance a team silently overspends, and one made twice is a team wrongly refused. But
the ledgers alone cannot answer what a _closed_ month cost once Compliance's retention
sweep (Phase 15) has pruned the rows behind it, nor how much overage Stripe has already
been told about.

## Decision

- **The billable party is the `Team`.** Cashier's customer columns are on `teams`,
  `Cashier::useCustomerModel(Team::class)`, and `subscriptions`/`subscription_items` are
  keyed on `team_id`. Cashier's `Billable` trait therefore lives on `Core\Models\Team`,
  because Cashier resolves the customer model statically; the _domain_ side of billing
  (plans, allowances, enforcement) stays in `App\Modules\Billing`.
    - Those two tables are **Cashier's models, not ours**, so `BelongsToTeam`'s global
      scope never reaches them. Tenancy is enforced where they are read — every lookup
      goes through `PlanResolver::subscriptionFor()`, which queries by `team_id`, and the
      screens authorise with the `billing.view` / `billing.manage` gates. ADR 0006's rule
      stands: a scope is a convenience, a policy is the guarantee.

- **Limits are enforced at the point of spend, not in middleware.**
  `Billing\Support\EnforcesPlanLimits` is a concern consulted by the guards that already
  existed — `AiPlatform\Services\AiCreditGuard` and
  `LeadSourcing\Services\DiscoveryLimitGuard` — and Billing fills their seams with
  `PlanCreditSource`, `PlanLeadQuotaSource` and a new
  `Auth\Contracts\SeatLimitSource` → `PlanSeatLimit`. **No guard and no caller of one
  changed in Phase 16**, which is what the seams were for, and no module imports
  `App\Modules\Billing`.
    - The sources report the **overage ceiling** as the limit, not the included allowance:
      on a metered plan a team past its allowance is working and being billed, and
      refusing them there would be charging for something we then decline to do.
    - The ceiling exists at all so a metered plan cannot become an unbounded invoice whose
      first warning is the invoice.

- **Allowances are measured over the calendar month, in UTC** (`Billing\Data\BillingPeriod`).
  It is what a customer can predict without reading an invoice, it is what the AI ledger
  has measured since Phase 5, and overage is reported to Stripe as it accrues so it still
  lands on whatever invoice is open. UTC because the bounds reach a `where created_at >= ?`
  on tables written by workers in UTC — the same rule as Reporting's `DateRange::constrain()`.

- **`usage_meters` is a derived record, not a counter.** Enforcement reads the
  authoritative ledgers live, through each owning module's own counter
  (`AiUsageLogger::usedMicrosBetween()`, `LeadUsageCounter::createdBetween()`), so Billing
  never writes a query against another module's schema. `UsageLedger::sync()` writes
  absolute values into the row, which makes it idempotent; `reported` is the Stripe
  idempotency key; and a row is **sealed** once it has been synced at or after its period
  ended, so a settled month can never be rewritten from pruned ledgers or against a plan
  the team has since moved to.

- **Plans are config, not data** (`config/billing.php`). A plan is a product decision that
  ships with a release, so a price change is a reviewed diff rather than an `UPDATE`
  nobody can find later. Five of them: a `free` fallback for a lapsed trial, three
  self-serve tiers, and Enterprise by arrangement.

## Consequences

- Cashier is coupled to `Core\Models\Team`, so a future change of billable model is a
  migration plus a data move, not a config change. Accepted: the alternative breaks as
  soon as somebody owns two teams.
- A team's allowance resets on the 1st regardless of when it is invoiced, which will read
  as a quirk to anyone expecting the Stripe cycle. Documented on the billing screen in as
  many words ("Allowances run with the calendar month and reset on the 1st").
- Enforcement cannot be seen in `bootstrap/app.php` or a route file — somebody looking for
  "where is the plan checked" has to be told it is in the guards. Hence this ADR, the
  `EnforcesPlanLimits` docblock, and `docs/modules/billing-plans.md` §1.
- Two aggregate queries per guard check, which is what `AiCreditGuard` has cost since
  Phase 5. No new hot-path cost, and no write in the path of a limit check.
- Reporting overage correctly turned out to need three separate mechanisms — a conditional
  claim, a deterministic meter-event identifier, and treating an ambiguous failure
  differently from a refusal. See `docs/modules/billing-plans.md` §6; none of them is
  optional, and the first two were missing until review caught them.
- **VAT (UK/UAE) is deferred** (⚖️), as `mvp.md` allows. Cashier supports Stripe Tax via
  `calculateTaxes()`; switching it on needs a decision about tax-inclusive pricing and
  whether a VAT number is collected at checkout.
