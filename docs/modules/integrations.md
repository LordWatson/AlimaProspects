# Integrations

> Status: **Post-MVP** · Last updated: 2026-10-28

## Purpose
Connects AlimaProspects to the outside tools teams already use: outbound webhooks, Zapier/Make, CRM sync (HubSpot/Pipedrive), and calendar booking links. Entirely post-MVP; documented now so the core data model and events are shaped to support it later.

## Planned scope
- **Outbound webhooks** on key events (lead discovered, graded A, stage changed, replied) — signed, retried.
- **Zapier / Make** app (REST + webhooks via `/api/v1`).
- **CRM sync:** HubSpot / Pipedrive two-way lead/contact/activity sync.
- **Calendar booking** links in outreach (Cal.com/Calendly-style).

## MVP vs post-MVP
All Post-MVP. MVP obligation: emit the right domain **events** and keep `/api/v1` clean so these can be built without refactoring.

## Dependencies
Core (events), Leads Workspace, Outreach, API v1. Each external provider needs its own ADR + account.
