# ADR 0038 — Signal types are suggested from the business, tested, and never saved

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P2 of `docs/plans/signals-v3-plan.md`
- **Supersedes / amends:** nothing. It is **ADR 0020** (an AI that writes configuration drafts,
  is bounded three times and is previewed before it is kept) applied to a model that is not given
  a brief at all.

## Context

A team got signal types in two ways: take one from the library, or describe a buying moment in a
sentence and let the composer draft it. Both ask the member to already know which events matter.
The library's types were written for wealth events; on the P1 eval they returned 235 search
results for four ordinary businesses and five feed cards.

The business profile now carries what a model needs to answer the question for them: what is
sold, to whom, what triggers a purchase, who is not a customer.

## Decision

1. **One reasoning-tier call proposes up to five types** (`signals.propose_definitions`) from
   `SellerBrief`, the active ICP, the team's existing types and the library's list. All of it
   member-typed or web-derived text is wrapped as untrusted content.
2. **A library type is named, not rewritten.** Where the idea is one the library covers, the
   model returns its key and the suggestion is that type as shipped. A key not in the library,
   or one the team already has, is dropped in code.
3. **A drafted type is validated by the save path's own rules** (`SignalDefinitionData::rules()`)
   before it is stored as a suggestion; one the form would refuse is dropped.
4. **Every suggestion is tested before it is ranked**, by `PreviewSignalDefinitionAction`: the
   same sample search a member runs by hand, on the same `signal_previews` ledger and inside the
   same daily preview budget. One queued job per suggestion, so each stays inside the queue's
   retry window. A suggestion that could not be tested is shown and marked, not hidden.
5. **Nothing is saved.** A library suggestion is added through the existing clone action; a
   drafted one opens in the existing review form, where the member can edit it, preview it
   again, and save it or not. There is no "add all" in this version.
6. **It is capped per team, over its own ledger.** `signal_suggestion_batches` is the row the cap
   is counted against: one batch that produced suggestions per `cooldown_hours` (24), at most
   three attempts in that window whatever became of them (a failed attempt can still have been
   billed), and a route limiter keyed by team rather than by member.
7. **It sits behind `Capability::SignalComposer`**, in the route and in the action. It is the
   composer's AI shortcut by another door; the library and the manual form stay free.
8. **The batch holds configuration and counts only**: drafts, and how many results, events and
   kept events a sample search found. Never an article, a company or a person. It is swept after
   `retention.suggestion_days` (90) and has nothing for a subject access request to find.

## Consequences

- Measured on the eval (see `docs/modules/signals.md` → _Signal evaluation_): the suggested types
  are specific to each business where the library's are not.
- A batch can spend the day's preview allowance before a member has run a preview by hand. The
  intro line says so. Accepted: the previews are the feature.
- The screen polls while a batch is pending or a sample search is out, and stops on its own: a
  pending batch is waited on for ten minutes, a "testing" row for five.
- Deferred: a one-step "add these and start watching", and drafting from won leads (no team has
  enough dated history yet).
