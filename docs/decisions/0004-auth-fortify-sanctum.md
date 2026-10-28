# ADR 0004 — Authentication: Fortify (web) + Sanctum (API), spatie permissions

- **Status:** Accepted
- **Date:** 2026-10-28

## Context
We need web auth with 2FA and token auth for the mobile API, plus team-scoped roles.

## Decision
- **Web:** **Laravel Fortify** (bundled with the Vue starter kit) for login, registration, email verification, password reset and **2FA (TOTP + recovery codes)**. `@laravel/passkeys` is present and may be enabled later.
- **API/mobile:** **Laravel Sanctum** personal access tokens on `/api/v1`, with per-token abilities and rate limiting.
- **Permissions:** **spatie/laravel-permission**, **team-scoped** (team as the permission team context). Roles: Owner, Admin, Member, Read-only.
- **Authorisation** lives only in Policies/Gates; **validation** only in FormRequests/Data objects.

## Consequences
- Fortify gives battle-tested web auth without hand-rolling; 2FA included.
- Sanctum tokens keep mobile stateless and revocable.
- spatie permission's team mode aligns with the multi-team tenancy (ADR 0006); care needed to set the active team's permission context per request.
