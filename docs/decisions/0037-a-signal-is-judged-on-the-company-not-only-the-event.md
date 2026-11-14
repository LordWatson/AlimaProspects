# ADR 0037 — A signal is judged on the company, not only the event

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P1 of `docs/plans/signals-v3-plan.md`
- **Supersedes / amends:** nothing. It applies **ADR 0035**'s relevance gate (drop only a confident
  misfit, keep the unsure, never fail a run) to Signals, and adds a ninth component to **ADR 0022**'s
  stored score under that ADR's own rules.

## Context

`signals.extract_event` asked one question of an article: is this one of the team's events? It was
told four fields about the seller. So a competitor's acquisition was a perfectly good acquisition,
and the feed could not tell a customer from a rival. The ICP-fit score component could not help:
it builds a lead from a signal, and a signal has no industry, size or technology to match.

The first `signals:eval` run also found the classifier broken in two ways that had nothing to do
with fit, and both had to be fixed before fit could be measured at all:

- Its answer was cut off at 2,048 output tokens on `claude-haiku-5-5`, whose reasoning counts
  against the limit. 27 of 47 calls failed.
- MySQL's `json` column re-orders an object's keys when a prompt template is stored. The provider
  constrains the answer to the schema, and optional keys may then only appear in the schema's
  stored order. `event_type` had moved in front of `subject_company_name`, so a model that wrote
  the company first could no longer emit the type and wrote it into the company string. The run
  read that as "not an event". SQLite keeps key order, so no test could see it.

## Decision

1. **Every field of a classifier answer is required** (v3). A required key cannot be skipped, so
   the order the database stores them in stops mattering. Each optional field was already
   nullable. This is a rule for any prompt whose schema has optional keys: on MySQL, optional
   means "may be silently unreachable".
2. **Fit is asked in the same call** (v4), not in a second one. The classifier gets the whole
   `SellerBrief` and returns, per event, `subject_fit` (`fit` / `unknown` / `not_fit` /
   `competitor`), `fit_confidence` and, for a fit only, `why_it_matters`. A run costs no more
   calls than it did.
3. **Code decides what is dropped, not the prompt.** `RunSignalWatchAction` leaves out an event
   only when the verdict is a rejection **and** `fit_confidence` is at or above
   `signals.seller_fit.min_confidence` (70). An unsure rejection is kept and ranked lower. A wrong
   "no" hides a lead; a wrong "yes" costs a member a glance.
4. **`unknown` is the expected answer and is not a penalty.** A snippet rarely says what a company
   sells. `SellerFit` is left out of both sides of the score's average when unknown, as ADR 0022
   requires.
5. **A confident fit clears the relevance floor.** `relevance` and fit used to be one question.
   With fit asked directly, confident fits were scoring 30–35 for relevance because they were a
   town away from the watched place, and the floor discarded them.
6. **A dropped event is recorded, twice.** In `signal_rejections` under a new `not_fit` verdict, so
   next week's run does not pay to be told again; and as `signal_runs.not_fit_count`, shown on the
   watch's run line, so a quiet feed is not mistaken for a quiet week.
7. **`why_it_matters` never appears without its evidence.** It is stored only for a `fit`, shown
   on the card directly above the article it was read from, and labelled as Alima's reading.
8. **The preview applies the same two rules**, so a sample predicts the feed
   (`PreviewVerdict::NotACustomer`).
9. **Silence is a verdict only from an answer that covered most of the batch.** One damaged row
   for a batch of ten used to mark the other nine "not an event" for thirty days.

## Consequences

- Measured on the labelled set (80 articles, four fixtures): good articles reaching the feed went
  from 0 of 28 to 23 of 28; competitors and misfits from 1 of 24 to 0 of 24. The five still lost
  are outside the watched town, which is the watch's own place rule and P3's subject.
- A team with a thin business profile gets mostly `unknown`, and so the old behaviour. The feature
  is only as good as the profile, which is what the background check is for.
- A remembered verdict now depends on the business profile, so the profile's `updated_at` is
  part of the run's rejection fingerprint. Editing the profile asks every remembered article
  again, once. That errs toward paying a second time rather than hiding an event for a month.
- `SignalArticleEnricher` re-extracts with an empty seller description and does not touch the fit
  columns; fit is judged once, at extraction.
- The score breakdown has nine components. Existing rows keep eight until rescored; an absent
  component reads as not judged.
- Prompts with optional schema keys elsewhere in the app have the same exposure on MySQL. Not
  audited here.
