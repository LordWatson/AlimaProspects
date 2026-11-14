# ADR 0040 — The watched place is where a watch looks, not a filter on what it keeps

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P3 of `docs/plans/signals-v3-plan.md`
- **Supersedes / amends:** amends the classifier rule in force since S3 ("anything not clearly
  tied to the location is not an event"). **ADR 0019** stands untouched: which law applies to a
  converted lead is still resolved by our code from the event's own place.

## Context

A watch names places, and they did two jobs. They went into the search queries, and the
classifier used them as a filter: an event in another town was "not an event".

The second job was wrong for most sellers. A studio that sells across the North West lost a
Cheshire brewery to a Manchester watch. A sample search for a new signal type, run against the
market's default place, called a new factory in Chippenham a non-event because the search had
been for London — so three of four businesses were told their suggested types found nothing.
On the labelled set this rule was the whole of the remaining good-article loss after ADR 0037.

How near a customer has to be is a fact about the seller, not about the watch.

## Decision

1. **The place a watch names is where its searches look.** `signals.extract_event` v5 no longer
   treats it as a filter. An event in another town or region of the same country is an event.
2. **Nearness is part of fit.** The classifier reads where the seller is based, the regions it
   serves and how it delivers, and a company too far away for a local seller is `not_fit`. When
   the profile does not say how far the seller travels, distance is not held against the company.
3. **A different country is still a hard no**, in the prompt and, independently, in code: the
   jurisdiction resolver decides which rules a converted lead carries and fails toward the
   restriction (ADR 0019). The prompt change relaxes nothing about that.
4. **One deal is one card when outlets lead with different parties.** Within a run, a row whose
   subject and counterparty are an earlier row's counterparty and subject, for the same type of
   event, is filed under that earlier subject. An exact swap only.

## Consequences

- The quality of this rule depends on the business profile saying how the seller delivers. The
  background check fills `delivery_model`; a team that skipped it gets the permissive reading.
- A watch on one town now surfaces events from the rest of the country when its queries are
  national in wording. For a local seller the fit judgement removes them; for a seller with an
  empty profile it does not.
- The party rule needs both outlets to name both parties. A second report that names only one
  still gets its own card.
- Measured in `docs/modules/signals.md` → _Signal evaluation_.
