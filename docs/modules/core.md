# Core

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
The foundation every other module builds on. Owns multi-tenancy (`teams` + the user↔team pivot + active-team concept), the `BelongsToTeam` trait and global scope, shared base classes and conventions (base Action, DTO conventions), the immutable **audit log**, structured-logging context, feature flags (Pennant), central exception rendering, and app-wide concerns (timezone/locale handling, money casting). It does **not** own authentication (Auth & Users) or any product domain.

## Planned scope
- `teams` table; `team_user` pivot with team-scoped role; `users.current_team_id` (active team) + team switcher.
- `BelongsToTeam` trait: global scope filtering by active team + automatic `team_id` assignment on create; paired with policies that double-check ownership.
- Team settings: name, timezone, locale (en/ar), default market (UK/UAE).
- `audit_logs` table + `AuditLogger` service; records actor, team, action, subject, metadata, request_id.
- `LogContext` middleware injecting `team_id`, `user_id`, `request_id` into every log line; dedicated channels `ai`, `lead_sourcing`, `billing`.
- Base module service provider + auto-registration of module web/api routes, policies, migrations.
- Central exception rendering in `bootstrap/app.php`; base `DomainException` type.
- `Model::shouldBeStrict()` outside production; money cast helper (integer minor units); UTC storage + team-tz display helpers.
- Pennant feature-flag scaffolding scoped to team.

## MVP vs post-MVP
Entirely MVP — it is the scaffold.

## Dependencies
None (other modules depend on it).
