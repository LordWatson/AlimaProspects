# AlimaProspects — Lead Quality Build Plan

> Companion to `mvp.md`, `signals-plan.md`, `signals-v2-plan.md` and `lead-sources-plan.md`.
> Created 2026-11-06. Feed this to Claude Code **one phase at a time**.
>
> **This plan is about precision, not breadth.** `lead-sources-plan.md` adds _more_ sources; this
> one makes the leads we already find worth working. The two do not overlap and can run in either
> order. Nothing here needs a paid enrichment provider: ADR 0008 is not the constraint — the
> constraint is that we do not use what we already hold.

## Status

| Phase | Title                                                   | Status      | Completed  |
| ----- | ------------------------------------------------------- | ----------- | ---------- |
| Q1    | Enrichment: keep the facts we already extract           | ✅ Complete | 2026-11-06 |
| Q2    | Companies House: search the register, not company names | ⬜ Pending  |            |
| Q3    | Scoring: the buying signals we already hold             | ✅ Complete | 2026-11-06 |
| Q4    | A lead has two numbers — fit and readiness              | ✅ Complete | 2026-11-06 |
| Q5    | Re-enrich what failed and what went stale               | ⬜ Pending  |            |
| Q6    | Close the feedback loop (propose, never apply)          | ⬜ Pending  |            |
| Q7    | Places pagination + multi-query web search              | ⬜ Pending  |            |

Q1–Q4 are one slice and should land together: Q1 un-blanks the fields Q4 measures and Q2 feeds, Q2
changes what arrives, Q3 fills the one criterion the scorer always abstained on. Q5 is independent.
Q6 depends on Q3 only for the shape of its advisor. **Q7 last, deliberately** — Q1–Q4 change what a
lead looks like, so measure the query strategy after them, not before.

---

## The diagnosis

Three separate causes, and only one of them needs new capability.

1. **We extract good facts and throw them away.** `EnrichLeadAction::applyFacts()` filled
   `website`, `industry`, `employee_range` and `technologies`. `ExtractedFacts` also carries
   `companyName` and `locations` — validated, bounded, already paid for — and both were dropped on
   the floor. So a `web_search` lead's `company_name` stayed a **page title** for ever, and
   `locality`/`region`/`country` stayed null, which made the scorer's Geography criterion answer
   **Unknown** on every web-sourced lead. (Q1)
2. **We search the sources badly.** `CompaniesHouseProvider` uses `/search/companies?q=`, so an ICP
   industry term matches _a word in a company's name_, and dissolved companies come back —
   `company_status` is stored and never filtered. (Q2)
3. **One number is answering two questions.** Scoring is deliberately blind to missing data, which
   is right for fit and means an A-grade lead can have no website, no contact and no routable
   email. Nobody can tell those apart on the list. (Q4)

Plus two loops that were built and never closed: `score_feedback` has been written since Phase 9 and
read by nothing, and `EnrichLeadAction::execute($lead, force: true)` exists with no caller. (Q5, Q6)

## Ground rules for every phase

- **Reuse the seam, don't import the module.** Q3 reaches Signals through a contract Scoring owns
  with a safe default, the way Billing fills `CreditSource` and Outreach fills
  `SignalOutreachDrafter`. No module here imports another's namespace.
- **No AI where a rule will do.** Q1, Q4 and Q5 add no model call and no outbound HTTP. Q2 extends
  an existing prompt rather than adding one. Only Q6 spends, and it spends on a member's request.
- **A derived figure is a record, not a counter** (ADR 0012's rule): `readiness` is recomputed from
  the lead's own fields, never incremented.
- **Missing data is still not a failure.** Q4 exists precisely so that Q1's new fields do not get
  smuggled into the fit score — fit and readiness stay two columns and two questions.
- The usual gates: `composer test`, `composer analyse`, `npm run check`/`types:check`/`build`, and
  the module doc + `CHANGELOG.md` updated per phase.

---

## Phases

### Phase Q1 — Enrichment: keep the facts we already extract ✅

**Done 2026-11-06.** `applyFacts()` fills `company_name` from the extraction, governed by
`LeadSource::rank()` — the same "fill a null, overwrite only from a stronger source" rule
`LeadDeduplicator` already applies. The AI extraction of a company's **own homepage** outranks a
Serper result title and does **not** outrank the Companies House register.

Three things this phase settled:

- **`dedupe_key` is stored at discovery and never recomputed**, so correcting a name-derived key's
  name would leave the lead with a key pointing at the old name — quietly stopping it
  de-duplicating ever again. Recompute the key when the name changes **and** the stored key is
  name-derived; a number- or domain-keyed lead is untouched, which is nearly all of them.
- **An overwrite is not a fill**, so it needs its own provenance. `SourcedValue::METHOD_AI_EXTRACT`
  already carries the source URL and timestamp; the overwrite records it the same way, so the lead
  says where its corrected name came from.
- **The extracted locations are stored as themselves, not parsed into the address.** The plan as
  written said "the three location fields", and that was wrong. `locality`/`region`/`country`
  describe **one postal address a provider gave us**; an extracted location is free text a company
  wrote about itself ("London", "Dubai & Abu Dhabi", "remote, UK"). Turning one into the other needs
  a guess, and a guess sitting in an address column is worse than the honest null already there — so
  they go in `leads.locations`, and `DeterministicScorer` reads them **alongside** the address for a
  Geography criterion. The AI half is told them as "Operates in", separately from "Location", for
  the same reason: the two are different claims and the explanation must not merge them.

### Phase Q2 — Companies House: search the register, not company names

Use `/advanced-search/companies` when the query carries structure, keeping `/search/companies` as
the fallback when it does not. Free, same key, same auth, same provider class.

1. **Spike first, like L0 did.** Verify the live endpoint's parameter names, its SIC-code format,
   its `items_per_page` cap and its rate limit; record the findings in
   `docs/modules/lead-sourcing.md` before building. A parameter we send wrongly and a plan we
   guessed at arrive the same way.
2. Add `sicCodes` + `incorporatedFrom` to `DiscoveryQuery` (nullable; absent means "no position").
3. Extend `lead_sourcing.shape_query` to emit SIC codes as a **new prompt version** (v2), bounded by
   the schema; the shaper already runs once per source before the fan-out, so this costs nothing new.
4. `CompaniesHouseProvider`: advanced search with `sic_codes`, `company_status=active`, `location`,
   `incorporated_from`; fall back to `/search/companies` when no SIC codes were emitted. SIC codes
   now arrive from the register, so stop leaving `sicCodes: []`.
5. Tests: a structured query hits advanced search, a bare one hits the name search, a dissolved
   company is not returned, and the shaper degrading to `fromIcp()` still produces a working run.

### Phase Q3 — Scoring: the buying signals we already hold ✅

**Done 2026-11-06.** `DeterministicScorer` answered **Unknown** for every
`CriterionType::BuyingSignal`, on the stated grounds that funding and hiring "aren't in any column we
hold". Signals has held them since S10: `signals.matched_lead_id` is _the lead an event is about_.

Two departures from the plan as written, both in the direction of less code:

- **The contract returns `list<string>`, not a DTO.** The plan asked for "a small DTO (event label,
  occurred-at, heat)". The scorer matches text and nothing else, so a DTO would have been three
  fields of which it read one. What counts as recent, which statuses count and one-entry-per-event
  are all decisions the **implementation** owns, because they are Signals' own rules — so they live
  there, not in a shape Scoring has to understand.
- **The buying-signal special case was deleted rather than extended.** It now goes through
  `haystackFor()` like every other criterion, which means the matcher, the Unknown-when-empty rule
  and the evidence string are all the existing ones. The breakdown names the matched signal because
  `matchAny()` already returns the hit as evidence — nothing had to be added for step 4.

1. `Scoring\Contracts\LeadSignalSource` with an **empty** default implementation. Empty is safe
   here because "this lead has no signals" is a true answer — unlike `SignalOutreachDrafter`, whose
   default refuses, because _may we approach this prospect_ must not be answered permissively.
2. `SignalsServiceProvider` binds the real one: recent, non-dismissed signals matched to the lead,
   as a small DTO (event label, occurred-at, heat) — **cluster representatives only**, so one event
   counts once.
3. `DeterministicScorer` matches a buying-signal criterion against those labels with the same
   case/punctuation-insensitive, word-bounded matcher every other criterion uses. **No signals is
   still `Unknown`, not a miss** — the rule that missing data leaves both sides of the ratio.
4. The score screen's breakdown names the signal that matched, so the explanation stays grounded.
5. Tests: a matching signal scores, a dismissed one does not, no signals is `Unknown`, and a
   cross-tenant signal is invisible.

### Phase Q4 — A lead has two numbers: fit and readiness ✅

**Done 2026-11-06**, with three departures from the plan, all smaller than what it asked for:

- **Nothing was wired into the workspace's create and merge paths** (step 3). Every path that
  creates a lead — the form, the CSV import, a signal conversion — already queues an
  `EnrichLeadJob`, and the readiness stamp sits in that Action **outside** `applyFacts`, so it runs
  whether or not the AI was called. One place computes the figure and every creation route reaches
  it. A lead whose enrichment **failed** keeps a null, which is the honest answer.
- **No new column in the leads table** (step 4). The meter went **inside the existing "Data"
  cell**, under the enrichment badge, and that column's header became the sortable one — because
  "how complete is this lead's data" is the question that column already asks, and the list hides
  seven of its nine columns on a phone as it is.
- **The figure is computed after de-duplication, on the survivor.** Not in the plan and
  load-bearing: a merge moves contacts across and fills gaps from the loser, so a figure taken
  before it describes a row that no longer exists. `load('contacts')`, not `loadMissing` — the
  merge has just changed them underneath.

And one thing the plan did not say, which the service turns on: **null is not zero.** A lead we
hold nothing about beyond its name gets **no figure**, because "not computed" and "nothing to reach
them by" are different states and a zero reads as the second — the same reason Scoring writes no
row for a lead it could assess nothing about, and the reason the list orders nulls **last
explicitly** rather than letting MySQL and SQLite disagree about where one sorts.

1. `leads.readiness` (unsigned tinyint, nullable) + `readiness_at`. Deterministic, no AI, no network.
2. `Enrichment\Services\LeadReadiness` scores what we hold: a website, an industry, a size, a
   location, a contact, a contact at `mx_found`, a non-role contact. Weights in
   `config/enrichment.php` so tuning is a config change.
3. Computed in `EnrichLeadAction` (same transaction as the facts) and on the Leads Workspace's own
   create/merge paths, so a hand-added lead is not permanently blank.
4. Workspace: a sort and a filter, and a readiness meter on the lead detail using the existing
   `MeterBar`. **Not a grade** — no letters, no status colour, because readiness is not a verdict
   about the prospect.
5. Reporting gets an honest enrichment-coverage figure for free.
6. Tests: the scorer over a bare lead, a full lead, and the ordering the list promises.

### Phase Q5 — Re-enrich what failed and what went stale

1. `enrichment:refresh` — a scheduled command sweeping `failed` leads (bounded by an attempt count,
   so a permanently broken page is not retried for ever) and `enriched_at` older than
   `enrichment.stale_after_days` for leads in a live pipeline stage.
2. Reuses `EnrichLeadAction::execute($lead, force: true)` and `EnrichLeadJob`'s existing
   unique-per-lead guard. Chunked, team-scoped through `TeamContext`, bounded per run.
3. Because the sweep re-fires `LeadEnriched`, it also closes Scoring's open TODO — _a score left
   stale by a failed AI half is never retried_.
4. Tests: a failed lead is retried, an exhausted one is not, a fresh one is skipped, the AI
   allowance still bounds the spend.

### Phase Q6 — Close the feedback loop (propose, never apply)

`score_feedback` is written and read by nothing. `icp.refine_suggestions` is seeded with no consumer.
Join them with the pattern `SignalTuningAdvisor` already proved.

1. `Scoring\Services\IcpTuningAdvisor`: aggregate feedback per criterion over a window, propose
   weight changes and criteria to add or drop.
2. **Proposes and never applies.** Accepting is an ordinary audited ICP edit (and therefore already
   fires `IcpUpdated` and re-scores). A decline is stored so it is never raised twice. "Raise this
   weight" may never lower one, the asymmetry S12 settled.
3. Surfaced in the ICP editor beside the live "what this profile will do" summary.
4. A floor on the sample size — an advisor that fires on two votes is noise wearing a number.

### Phase Q7 — Places pagination + multi-query web search

1. Google Places `nextPageToken` pagination, bounded by the run's own limit (today a larger request
   is **silently** capped at 20).
2. Let the shaper emit two or three narrow queries per provider instead of one broad one, with the
   per-run request count bounded and metered — the cost multiplies, which is why this is last.
3. Surface `leads_excluded` on the run screen; it reaches the `lead_sourcing` log today and no
   screen.

---

## Out of scope

- **Paid enrichment / SMTP verification** (ADR 0008). Q1 recovers most of what it would buy.
- **Anything from `lead-sources-plan.md`** — that plan adds breadth, this one precision.
- **Learning-to-rank over feedback.** Q6 proposes ICP edits; it does not train a model.
