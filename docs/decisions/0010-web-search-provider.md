# ADR 0010 — Web-search provider (Serper)

- **Status:** Accepted
- **Date:** 2026-10-29
- **Extends:** ADR 0008 (lead-sourcing & enrichment providers)

## Context

Phase 7b adds a web-search lead source behind the existing `LeadSourceProvider` contract (ADR 0008), used both for general company discovery and, later, for feeding the AI background check / enrichment. ADR 0008 named the candidates as "Serper/Brave/Bing-style" without committing to one. We need a concrete default for the MVP.

Options considered:

- **Serper.dev** — Google SERP results via a single `POST /search`, pay-as-you-go (~$0.001–0.003/query at low volume), no monthly minimum, trivial JSON. Google-quality results, which best match UK/UAE SMB discovery.
- **Brave Search API** — independent index, privacy-positioned, free tier; results are thinner for local/SMB queries than Google.
- **Bing Web Search** — strong, but Microsoft is retiring the standalone Bing Search API in favour of a pricier Azure "Grounding" SKU, so it is a poor long-term base.

## Decision

- **Serper is the MVP web-search provider.** `WebSearchProvider` implements `LeadSourceProvider` and reads `config('lead-sourcing.providers.web_search.provider')` (default `serper`); only `serper` is implemented in Phase 7b, and an unrecognised value fails fast with a configuration error rather than silently degrading.
- The provider is a **driver-style seam**: adding Brave or Bing later is a new mapping branch behind the same config key and contract, with no change to the discovery pipeline.
- Web-search results are **web pages, not registry records**: the provider maps each result's title → company name and its link → website/domain, and leans on the **domain-based dedupe key** so results dedupe across providers. Richer company facts (and contacts) come from Enrichment (Phase 8), which can reuse this provider to fetch pages.

## Consequences

- One paid, metered dependency (Serper); per-query cost is logged on `discovery_runs.cost_micros` like any other provider (indicative rate in `config/lead-sourcing.php`, verify against live pricing).
- Web-search leads are coarser than Companies House (no company number, status or SIC codes) and are keyed by domain, so they dedupe with Google Places (also domain-keyed) but not with number-only Companies House leads until enrichment links the two.
- Switching providers is a config + one-class change; no data migration.
