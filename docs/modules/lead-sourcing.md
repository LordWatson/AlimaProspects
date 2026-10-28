# Lead Sourcing

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
Discovers candidate leads for an ICP through a **pluggable `LeadSourceProvider` interface**. Owns discovery "jobs" (queued, tracked, progress-reported, per-plan-limited) and the raw discovered records before enrichment. It does **not** enrich, dedupe or score (downstream modules) and **never scrapes sites whose terms forbid it**.

## Planned scope
- `Contracts\LeadSourceProvider` + implementations:
  - **Companies House** (UK official registry, free) — company search/profile.
  - **Google Places** — local/SMB discovery by location + category (strong for UAE where no free registry exists).
  - **Web search API** (Serper/Brave/Bing-style) — general discovery + feeding the AI background check and enrichment.
  - (Post-MVP, pluggable: Apollo / People Data Labs / Hunter.)
- `discovery_runs` table (team+icp scoped, status, provider, params, counts, cost) + `DiscoverLeadsJob` (idempotent, `$tries`/`$backoff`/`$timeout`/`failed()`, progress over Reverb).
- Per-plan limits enforced before a run (reads Billing); respects provider rate limits + ToS + robots.txt.
- Writes raw `leads` (company-level) with **source attribution**; hands off to Enrichment.
- Inertia "new discovery" + run progress screens; mobile can watch progress.

## MVP vs post-MVP
MVP: Companies House + Google Places + web-search providers, queued runs with progress + limits. Post-MVP: paid B2B providers, saved searches, scheduled recurring discovery.

## Dependencies
Core, ICP, AI Platform (query shaping), Billing (limits), Compliance (ToS/robots). Feeds Enrichment, Scoring, Leads Workspace.
