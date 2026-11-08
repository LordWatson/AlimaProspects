# ADR 0026 — Pre-launch scope, phase order and how `pre-launch.md` maps onto this repository

- **Status:** Accepted
- **Date:** 2026-11-08
- **Phase:** PL0 of `pre-launch.md`
- **Supersedes / amends:** nothing. It **constrains** PL1–PL12 and records where `pre-launch.md`
  loses to decisions already taken in ADRs 0006, 0008, 0011–0025.

> **Numbering note.** `pre-launch.md` §0.9 says ADRs live in `docs/adr/NNNN-*.md`. In this repository
> they live in `docs/decisions/` and are referenced by number from `CLAUDE.md` §5. The plan's own
> rule is that the repo wins; every "`docs/adr/`" in that file means `docs/decisions/`, and the
> pre-launch ADRs number from **0026**.

## Context

`pre-launch.md` is a twelve-phase plan (PL0–PL12) written from a competitive gap analysis to close
five launch blockers: dated evidence behind every lead, verified contact data via a vendor waterfall,
a way for leads to leave the app (CSV, webhooks, Pipedrive, HubSpot), a free sample report replacing
a trial, and a trust centre with the compliance plumbing behind it.

It was written against the **product**, not against this codebase. The codebase has since shipped
twenty phases of `mvp.md`, S0–S16 of two Signals plans and L0 of the lead-sources plan, and it
already contains a do-not-contact list, an opt-out and DSAR path, retention sweeps, a streamed CSV
export with injection protection, a circuit breaker, an SSRF guard, an AI cost ledger and a usage
meter. Executed literally, the plan would build a second one of each. The second one is always the
one that gets a rule wrong, and for a suppression list "half honoured" is the failure mode that ends
in an ICO complaint rather than a bug report.

PL0 was a recon phase: no application code. Its inventory, gap table and binding per-phase "adapt"
notes are in **`docs/prelaunch/recon.md`**, which every later PL phase reads before writing code.
This ADR records the decisions that came out of it.

## Decision

### 1. Scope is as the plan states it, and the out-of-scope list is binding

PL1–PL12 ship as written in intent. The plan's own out-of-scope list — outcome tracking and the ROI
dashboard, the weekly digest and push, AI-drafted openers, vertical packs, push-to-Instantly/lemlist,
the done-for-you tier and website-visitor signals — is **post-launch and not to be anticipated with
abstractions**. Where a phase should leave a hook, the plan says so and nothing more is built.

Phase order is the plan's recommended order: PL0 → PL1 → … → PL12. The parallelism it offers
(PL10/PL11 alongside PL7–PL9) is not taken: there is one operator, and a worktree's merge cost is
higher than the wall-clock saving.

### 2. Four places where the plan loses to the repository

The plan's §0 says the repo wins and the conflict is recorded. These are the four.

**2a. One queue, not six.** Plan §0.7 and Appendix B name `ai`, `enrichment`, `integrations`,
`webhooks`, `exports` and `sample-reports`. This application runs **one `default` queue**: Horizon
has a single supervisor and no job in `app/` calls `onQueue()` or sets `$queue`. Named queues are not
free — each one is a supervisor to size, starve and alert on, and a job on a queue no worker consumes
is a feature that silently does nothing. **Queues stay `default` through PL11.** PL12 already owns
Horizon tuning and long-wait alerts; it may split the queues then, from observed depth rather than
from a guess. A phase that genuinely needs isolation (a bulk enrichment batch starving the UI's jobs)
may take it early, and says so in its own ADR.

**2b. ADRs in `docs/decisions/`.** As the numbering note above.

**2c. No `config/plans.php`.** Appendix C asks for one, merged with "the existing plan config". Plan
limits already live in `config/billing.php` keyed by `PlanKey`, with per-plan `capabilities` and a
per-module block (`signals`), and ADR 0012/0015 put **enforcement in the guards** that read it. A
second plan config is how two answers to "what is included in Starter" come to disagree, and the
disagreement surfaces as a customer who was charged for something they were told was included.
**All pre-launch plan limits go into `config/billing.php`** in the existing shape.

**2d. No second suppression table and no second contact table.** PL5's `suppression_entries` is
`outreach_suppressions` (ADR 0012/0016, CLAUDE.md §3: that table is _the_ do-not-contact list, and a
platform suppression is a `global`-scoped row in it rather than a table of its own). PL2's `contacts`
is `lead_contacts`. Both are extended. Adding the new types PL5 needs (`company_number`,
`person_name_company`) is a case on `SuppressionType`, not a new table.

### 3. Monetary amounts are integer micros

PL2–PL4 talk in "millicredits and pence". Every monetary column in this codebase is integer
**micros** — `ai_requests.cost_micros`, `leads.enrichment_cost_micros`, `lead_scores.cost_micros`,
`signal_definition_spend.search_cost_micros` — and `PlatformMoney` already reports over them. A
second unit in the same ledger is a billing bug waiting to happen (the `usage_meters` migration says
so in as many words). **Vendor cost is micros.** Customer-facing allowances stay in the metric's own
whole unit (a reveal is one reveal), exactly as `usage_meters.used` already does.

### 4. A Pennant flag and a `Capability` answer different questions, and neither is the other

The plan asks for nine Pennant flags. Pennant is installed here and **entirely unused** — PL1 is the
first flag. That is fine, and the flags ship as named (Appendix A). But this repo already has a
plan-entitlement mechanism, `Core\Enums\Capability` behind `Core\Contracts\CapabilityGate`
(ADR 0015), and the two must not be conflated:

- A **Pennant flag** answers _is this code finished enough to be seen_. It is a deploy-time
  concern, it is off for everyone until it isn't, and it disappears once the feature has shipped.
- A **`Capability`** answers _is this feature part of what this team pays for_. It is permanent, it
  is a sales artefact, and the rule on that enum is that **every case on it is actually enforced**.

A feature therefore has, at most, one of each: a flag while it is being built, and a capability if
and only if a plan withholds it. A numeric per-plan limit is neither — it is a **quota seam** that
Billing decorates, the pattern ADR 0012 established and ADR 0023/S16 used for the fourth time.

The nine flags are **removed** as each feature goes live; a flag left in the codebase after launch is
a branch nobody tests.

### 5. Enforcement stays in the guards

PL3's reveal, PL4's allowance, PL5's suppression, PL6's export and PL7's sync are all spend or
compliance decisions, and all of them can be reached by a queued job, a scheduled command or a
playbook step with **no request behind it**. They are therefore enforced in Actions and guards, never
in middleware (ADR 0012), and the one that answers _may this prospect be approached_ remains
`Outreach\Services\OutreachGuard` — a reveal, an export, a CRM push and a webhook ask it, or ask a
sibling that reads the same list. The plan's "one `SuppressionChecker` on every outbound path", with
an architecture test, is the right instinct expressed in the wrong noun: the test asserts the
dependency on **the existing guard**.

### 6. Vendor choice does not block the architecture

The enrichment vendors are Alex's decision and are not made yet (**ADR 0027**, `DECISION PENDING`).
PL2 and PL3 are **not** blocked on it: contracts, DTOs, a registry, fakes and the whole waterfall,
rate limiting, breaker, metering and UI are vendor-agnostic by construction, and PL2 ships **one
reference adapter marked experimental** as the plan allows. No vendor key reaches code, tests or docs.

### 7. What "done" means for a PL phase

The repo's existing definition (CLAUDE.md §0): `composer test` green, `composer analyse` clean at
Larastan 7, `npm run check` + `npm run types:check` + `npm run build` passing, the mobile project's
own gates where it was touched, module docs updated with a changelog row, and `CHANGELOG.md`. The
plan adds one thing worth keeping: a line in **`docs/prelaunch/progress.md`** per phase. It also asks
for tenant-isolation tests on every new model, which is already CLAUDE.md §2's rule.

`mvp.md`'s status table is **not** extended with PL phases; `pre-launch.md` keeps its own progress
file, the way `signals-plan.md` and `lead-sources-plan.md` keep theirs.

## Consequences

- Later phases are smaller than the plan reads, and PL5 and PL6 in particular are extensions of
  shipped code. The saving is real and the risk it removes is larger than the saving: one
  suppression list, one export path, one plan config, one monetary unit.
- `recon.md` becomes a required read for PL1–PL12. It will go stale as the phases land, so each
  phase that invalidates an adapt note **fixes that note** rather than leaving it to mislead.
- Keeping one queue means PL12 inherits a real decision rather than a tidy-up. That is the right
  place for it: it is the only phase that will have queue-depth numbers.
- Nine Pennant flags are nine branches. The removal rule in §4 is load-bearing; without it the
  pre-launch work doubles the number of states the app can be in, permanently.
- The plan's millicredits, `config/plans.php`, `suppression_entries` and `contacts` names appear
  throughout `pre-launch.md` and will keep reading as instructions. `recon.md` §6 is the translation
  table, and a phase that follows the plan's noun instead of the repo's has introduced a duplicate.
