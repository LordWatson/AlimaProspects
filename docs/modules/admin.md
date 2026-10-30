# Admin (internal)

> Status: **MVP (minimal) → Post-MVP (full)** · Last updated: 2026-10-28

## Purpose

The super-admin panel the AlimaProspects team uses to operate the platform: manage teams, users and plans; view AI usage/costs and provider health; inspect failed jobs; and **impersonate** a user (with audit). It is internal-only, separate from tenant-facing UI, and must never leak one tenant's data to another in normal product flows.

## Planned scope

- Admin guard + `super_admin` ability (outside team scope); all access audited.
- **Filament** admin panel if compatible with Laravel 13 / PHP 8.5 (ADR 0007); otherwise a simple Inertia admin.
- Resources: Teams, Users, Plans/Subscriptions, AI Usage & Cost, Provider Health (Companies House / Places / web search / Claude status), Failed Jobs (Horizon link), Audit Log viewer.
- **Impersonation** with start/stop, banner, and full audit trail.

## MVP vs post-MVP

MVP (minimal): teams/users list, plan view, AI cost view, failed-jobs + Horizon access, impersonation with audit. Post-MVP: full CRUD, provider health dashboards, feature-flag toggles UI, broadcast announcements.

## Dependencies

Core (audit), Auth & Users, Billing, AI Platform, observability (Horizon/Pulse). Decision on Filament vs Inertia in ADR 0007.
