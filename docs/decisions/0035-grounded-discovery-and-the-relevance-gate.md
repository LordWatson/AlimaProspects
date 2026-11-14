# ADR 0035 — Grounded discovery and the relevance gate

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** P1–P2 of `docs/plans/ai-relevance-plan.md`
- **Supersedes / amends:** nothing. It closes the limitation ADR 0008's AI source was shipped with
  ("a web-search-grounded variant is the obvious next step and is not built"), and applies
  **ADR 0028** (a reason is evidence on the lead, not a second score) and **ADR 0025** (no named
  individual from a `LeadSourceProvider`) to two new AI steps.

## Context

The relevance baseline (`docs/modules/lead-sourcing.md` → _Relevance evaluation_) measured the
chain as it stood. Three findings decided this ADR:

1. **86% of labelled competitors passed the only screen there was.** `LeadCandidateFilter` matches
   an excluded word against a company's name and domain. It refuses "Apex Graphic Design" and
   keeps "Studio Eight", which does the same work.
2. **Web search was 26% precise.** One long query returned pages, not companies: directory
   listings and "Contact us" titles were stored as company names.
3. **The AI source named companies from recall.** Nothing checked that a named company exists, and
   it named between one and six per run.

The context each step worked from was also thinner than what the product already held: the price
band and size of the seller reached no prompt on the search path at all.

## Decision

### 1. The profile is the one carrier of what is known about the seller

Accepting a background check writes its customer-side findings and competitors onto
`business_profiles`. One renderer, `Onboarding\Support\SellerBrief`, turns the profile into the
seller description every prompt reads: the query shaper, the ICP composer, the candidate gate, the
grounded discovery prompt and the scorer's AI half.

A seam into Business Intelligence was the alternative and was rejected: every one of those
consumers already reads the profile, so a finding the team accepted becomes the team's own
description of itself, and no module learns that a background check exists.

### 2. One shaping call, several search angles

`lead_sourcing.shape_query` v2 is asked once per run and phrases the search for every selected
provider. For web search it returns several short queries, each a different angle (the strongest
customer trade, a second trade or trigger, lookalikes of the customers the seller already has).
`web_search.max_queries` is the ceiling on what a run may send; each request is billed and
recorded on the run.

Lookalike discovery is an **angle, not a provider**. Seeding from a team's best-converted leads is
deferred until teams have conversion data; the customers typed into the profile cover the cold
start.

### 3. A named company must trace to a result

`lead_sourcing.ai_discovery` v2 is handed real search results and returns companies each citing
the number of the result it came from. **The grounding is enforced in code:**

- a citation that was not offered is dropped, and counted;
- the website and source URL stored are the **cited result's own**, never text the model wrote;
- there is no recall path left. A company the model "knows" but no result shows is not stored.

So the worst the prompt can do is misread a result, which the gate below and enrichment both then
see. The source keeps `LeadSource::rank() === 0`: it is still the least authoritative reading of a
page, and any other source corrects it on a merge.

Two consequences accepted: the AI source now needs the web-search provider's credentials, and it
makes paid requests, so its `costMicros()` is no longer zero — the search spend goes on the run,
while the model spend stays on `ai_requests` as before (still counted once each).

### 4. A relevance gate before a candidate becomes a lead

`CandidateRelevanceGate` runs in `DiscoverLeadsJob` after `LeadCandidateFilter` and before
anything is persisted, for every provider. One cheap-tier, batched call
(`lead_sourcing.judge_candidates`) gives each candidate a verdict — `fit`, `unclear`, `not_fit`,
`competitor` — with a confidence and a one-line reason.

- **It drops only a confident `not_fit` or `competitor`.** `unclear` is kept. The costs are not
  symmetrical: a wrongly dropped customer is lost for good and nobody sees it go; a wrongly kept
  one is graded and shown. The eval's _good-fit loss_ figure is the guard on this, and
  `relevance_gate.min_confidence` is the dial.
- **It degrades open and never fails a run.** No profile, no AI allowance, an unusable response or
  a spent call budget all let candidates through unjudged, exactly as before the gate existed.
- **It is bounded:** a fixed batch size and a fixed number of calls per run. Candidates past the
  budget pass unjudged rather than costing more.
- **It runs before the lead allowance is touched,** so a refused company is never enriched (the
  expensive step) and never counted against a plan.

### 5. Relevance is evidence, not a second score

A kept candidate's reason is written through `RecordLeadEvidenceAction` as a `lead_evidence` row
(`EvidenceKind::AiInference`, source `relevance_gate`). It appears in the panel that already
answers "why is this company here", and it feeds `leads.confidence` by the arithmetic ADR 0028
already defines. There is **no** `relevance_score` column: fit is still `lead_scores.score`, whose
AI half (`scoring.qualitative_fit` v3) now also reads the seller.

A refused candidate is not a lead, so its reason has nowhere to live on one. The run records
`leads_rejected` and a capped sample of `{name, verdict, reason}` — company-level only — so a
member can see what was left out and correct their profile when the gate was wrong.

### 6. Spend is a config ceiling, not a plan quota

Paid discovery searches are bounded by `web_search.max_queries` and the gate's call budget. A quota
seam like `SignalQuotaSource` was considered and not built: a run costs about a cent in searches,
the lead allowance already bounds how many runs matter, and no plan sells searches. Revisit if
discovery becomes schedulable.

## Consequences

- A discovery run makes more requests than it did: up to three searches instead of one, plus one
  or two cheap-tier calls. It makes fewer reasoning calls (one shaping call instead of one per
  provider) and enriches fewer companies.
- The AI source can no longer return a company with no web presence. That is the point, and it
  is also a loss for very small local trades, where Google Places remains the better source.
- The gate is an AI opinion applied before a human sees anything. The sample on the run and the
  labelled eval are the two checks on it; neither is automatic.
- A team with no business profile gets no gate and the deterministic query, as before.
