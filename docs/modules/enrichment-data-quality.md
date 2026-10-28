# Enrichment & Data Quality

> Status: **MVP** · Last updated: 2026-10-28

## Purpose

Turns raw discovered records into clean, trustworthy leads: company + contact enrichment, **email verification**, **de-duplication**, normalisation, **data-freshness timestamps**, and **source attribution on every field**. It does **not** source (Lead Sourcing) or score (Scoring).

## Planned scope

- Enrichment pipeline job (`EnrichLeadJob`, idempotent) that: normalises company data, derives contacts, and — because **no paid enrichment provider is in MVP** — extracts structured facts from fetched public web text via the AI (`lead.enrich_extract`, cheap tier) with injection safety.
- **Email verification (free-tier):** syntax + MX/DNS + disposable-domain checks (SMTP probe optional, carefully). Confidence stored, not a hard claim.
- **De-duplication** by domain, company number (Companies House), and email; merge strategy keeps best source per field.
- Every field stores `source`, `source_url`, `collected_at`, `method` (enforced by a `SourcedValue` cast/DTO).
- `lead_contacts` table; `data_freshness` timestamps; `enrichment_runs` tracking + cost.
- Prefer business/role data over personal (compliance); flag personal-looking data.

## MVP vs post-MVP

MVP: AI extraction + MX/DNS verification + dedup + source attribution + freshness. Post-MVP: Apollo/PDL/Hunter providers, paid email verification, technographics, re-enrichment schedules.

## Dependencies

Core, Lead Sourcing, AI Platform, Compliance. Feeds Scoring, Leads Workspace.
