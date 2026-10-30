# AI Insights

> Status: **Post-MVP (light "contact today" digest may land in MVP)** · Last updated: 2026-10-28

## Purpose

Periodic AI-generated, **stored, dismissable and actionable** insights: which segments convert best, ICP-adjustment suggestions, best-performing message angles, stale-lead alerts, and a "leads to contact today" digest. One-click apply for an ICP tweak. It does **not** replace Reporting (facts) — it interprets and recommends.

## Planned scope

- `insights` table (team, type, payload, status: new/dismissed/applied, generated_at, model/prompt version).
- `GeneratePeriodicInsightsJob` (scheduled, per team) → `insights.periodic_digest` (AI, reasoning tier) over Reporting read-models + feedback.
- Insight types: best segments, ICP adjustment (one-click apply → ICP module), message-angle performance, stale-lead alert, daily "contact today" list.
- Inertia insights feed with apply/dismiss; notifications for the daily digest.

## MVP vs post-MVP

MVP (light): the "contact today" digest + stale-lead alerts if cheap. Post-MVP: full insight suite, one-click ICP apply, learning from applied/dismissed signal.

## Dependencies

Core, Reporting, Scoring, ICP, AI Platform, Notifications.
