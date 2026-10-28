# Auth & Users

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
Identity and access: registration, login, email verification, password reset, two-factor auth, team invitations, team-scoped roles, and API tokens for the mobile app. Owns the user model, membership management and the role/permission wiring (spatie/laravel-permission, team-scoped). It does **not** own tenancy primitives (Core) or billing seats (Billing, which reads seat counts here).

## Planned scope
- Web auth via **Laravel Fortify** (bundled with the Vue starter kit): login, registration, email verification, password reset, **2FA (TOTP + recovery codes)**. Passkeys available via `laravel/passkeys` if kept.
- **Sanctum** personal access tokens for `/api/v1` (mobile), with per-token abilities and rate limiting.
- **Team invitations:** invite by email → pending invite → accept creates membership with a role. Resend/revoke.
- **Roles (team-scoped):** Owner, Admin, Member, Read-only. Permissions registered via spatie/laravel-permission with the team as the scope.
- Team switching endpoint (sets `current_team_id`) honouring membership.
- Profile & account settings (name, locale en/ar, timezone, notification prefs link).

## MVP vs post-MVP
MVP: email+password, verification, reset, 2FA, invitations, 4 roles, Sanctum tokens, team switch. Post-MVP: SSO/SAML, SCIM, passkeys polish.

## Dependencies
Core (teams, pivot, audit).
