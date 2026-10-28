# Leads Workspace (CRM-lite)

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
The day-to-day workspace where a team works its leads: list with saved filters/views and search, bulk actions, a lead detail page, contacts per company, a customisable pipeline (kanban stages), tags, notes, tasks/reminders, assignment to team members, an activity timeline, and CSV import/export. It owns pipeline + collaboration state; it does **not** generate messages (Outreach) or scores (Scoring).

## Planned scope
- `leads` (shared with sourcing/enrichment), `lead_contacts`, `pipeline_stages` (team-customisable), `lead_tags`, `lead_notes`, `lead_tasks` (due dates/reminders), `lead_assignments`, `lead_activities` (timeline, polymorphic).
- Saved views/filters per user/team; full-text-ish search over company/contact.
- Bulk actions (tag, assign, move stage, add to outreach, suppress).
- **CSV import** (map columns → leads/contacts, dedupe on import) and **export**.
- Activity timeline aggregates discovery, enrichment, scoring, outreach, notes, stage moves.
- Inertia: leads index (table + filters), kanban board, lead detail; mobile: list, detail, stage move, note, task, log activity.

## MVP vs post-MVP
MVP: list/filters/search, detail, contacts, kanban, tags, notes, tasks, assignment, timeline, CSV in/out. Post-MVP: custom fields, automations, duplicate-merge UI, shared team inboxes.

## Dependencies
Core, Scoring, Enrichment, Outreach, Notifications. Central hub of the product.
