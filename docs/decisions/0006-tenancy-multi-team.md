# ADR 0006 — Multi-tenancy: single database, multi-team membership

- **Status:** Accepted
- **Date:** 2026-10-28

## Context
AlimaProspects is multi-tenant. The kickoff confirmed users can belong to **several teams and switch** between them (agency/consultant use), not one-team-per-user.

## Decision
- **Single database, row-level tenancy.** Every tenant-owned table has a `team_id`.
- **Team = tenant.** A `team_user` **pivot** records membership with a team-scoped role; `users.current_team_id` holds the active team; a switcher changes it (validated against membership).
- A **`BelongsToTeam` trait** provides a global scope filtering by the active `team_id` and auto-assigns `team_id` on create. **Policies double-check ownership** regardless of the global scope.
- Every tenant-owned model gets a test proving **cross-tenant access fails**.

## Consequences
- Flexible membership and painless onboarding; one connection, simple ops.
- The active team must be resolved early in the request lifecycle and set as the context for both the global scope and spatie permission's team mode.
- Global scopes can be bypassed by raw queries — hence mandatory policy checks and tenancy tests.
- Rejected: database-per-tenant (ops overhead, overkill at this stage) and one-team-per-user (cannot retrofit multi-team cheaply).
