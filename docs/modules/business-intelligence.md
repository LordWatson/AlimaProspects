# Business Intelligence (AI background check)

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
Runs an AI background check on the **signed-up business** (from its website + public sources) and produces a stored, versioned profile: summary, offering, positioning, likely competitors, and suggested ICP refinements. The user reviews / edits / accepts it. It does **not** research prospects (that is Lead Sourcing/Enrichment) — only the tenant's own business.

## Planned scope
- `business_intel_reports` table (team-scoped, **versioned**, status: pending/ready/accepted/failed) with structured fields + raw evidence links.
- `RunBackgroundCheckJob` (idempotent, `ShouldBeUnique` per team+version): fetches site/public pages via the web-search provider, calls `business_intel.background_check` prompt through the AI Platform, validates JSON schema, stores report, reports progress over Reverb.
- Scraped content handled as **data, never instructions** (injection-safe).
- Actions: `AcceptBackgroundCheckAction` (merges accepted fields into Business Profile + optionally seeds an ICP), `RerunBackgroundCheckAction` (new version).
- Inertia review screen (diff against business's own profile); accept/edit.

## MVP vs post-MVP
MVP: single re-runnable, versioned background check with review/accept. Post-MVP: scheduled refresh, competitor deep-dives.

## Dependencies
Core, Onboarding/Business Profile, AI Platform, Lead Sourcing (web fetch/search). Feeds ICP, Scoring, Outreach.
