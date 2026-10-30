# ADR 0008 — Lead-sourcing & enrichment providers

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

Lead discovery and enrichment need external data. The kickoff confirmed the team can provision **Companies House, a web search API, and Google Places** — but **not** paid B2B enrichment (Apollo / People Data Labs / Hunter) for MVP.

## Decision

- A **`LeadSourceProvider` contract** abstracts all sources. MVP implementations: **Companies House** (UK, free), **Google Places** (local/SMB, strong for UAE), and a **web search API** (Serper/Brave/Bing-style).
- **Enrichment without a paid provider:** derive contacts/company facts via **AI extraction** from fetched public web text (`lead.enrich_extract`, cheap tier) plus free **MX/DNS + disposable-domain email verification**. Confidence is stored, never asserted as fact.
- Paid providers (Apollo/PDL/Hunter, paid email verification) are **post-MVP**, pluggable behind the same contract.

## Consequences

- MVP data quality on personal contact details is lower than with a paid provider — acceptable, and aligned with the compliance preference for **business/role data over personal data**.
- Every provider implementation must respect ToS + robots.txt and attach **source attribution** to every field.
- Per-provider cost and rate limits are tracked; see cost model in `mvp.md`.
