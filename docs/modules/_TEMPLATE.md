# <Module name>

> Status: **MVP** | **Post-MVP** · Owner: <team/role> · Last updated: <date>

## Purpose

One paragraph: what this module owns and what it explicitly does **not**.

## Code map

- **Backend:** `app/Modules/<Module>/…` — list key Actions, Services, Jobs, Models, Contracts, Enums.
- **Routes:** web + api route files and named routes.
- **Frontend:** `resources/js/Pages/<Module>/…`, `resources/js/Components/<Module>/…`, mobile screens in `/mobile`.
- **Shared:** anything added to `resources/js/shared/` (types, API client methods, validation, composables).
- **Tests:** `tests/Feature/<Module>`, `tests/Unit/<Module>`.
- **Config / env vars:** keys this module reads.

## Data model

Tables, key columns, relationships, indexes. Include a Mermaid ER diagram if > 2 tables.

```mermaid
erDiagram
```

## Key flows

Step-by-step of the main flows, including jobs/events dispatched and what runs `afterCommit`. Add a sequence diagram for anything with ≥3 participants.

## External dependencies

APIs/providers used, auth, rate limits, timeout/retry/circuit-breaker behaviour, cost per call.

## Permissions

Which roles (Owner, Admin, Member, Read-only) can do what. Policy class + abilities.

## Known limitations / TODO

Deferred items, tech debt, post-MVP hooks.

## Changelog

| Date | Phase | Change | Files |
| ---- | ----- | ------ | ----- |
