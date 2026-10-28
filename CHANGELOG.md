# Changelog

All notable changes to AlimaProspects are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are ISO (UTC).

## [Unreleased]

### Added — 2026-10-28 — Phase 1: Auth, teams, roles & invitations

- **Auth & Users module** (`app/Modules/Auth`): the full team-access layer.
- Enabled **spatie teams mode** (`config/permission.php` `teams => true`, `models.team => Team`); seed four global roles (`owner/admin/member/read-only`) via `TeamRolesSeeder`. A user's role is stored authoritatively on the `team_user` pivot and mirrored to a spatie team-scoped role by the `TeamMembership` support service.
- **Registration** now creates the user **and** their personal team (Owner) in one transaction (`CreateNewUser` → `CreatePersonalTeamAction`).
- **Teams:** create / update settings / switch (web + `/api/v1`), guarded by membership + `TeamPolicy`.
- **Invitations:** `team_invitations` table; invite / accept / resend / revoke Actions; temporary **signed** accept link; queued `TeamInvitationMail`; accepting joins the team with the invited role and switches into it.
- **Members:** role change + removal with a **last-owner** guard.
- **Sanctum tokens:** credential→token exchange for mobile (`POST /api/v1/auth/token`, 2FA accounts deferred to web), list/revoke (web settings + API), per-token abilities; global `/api/v1` rate limiting (`throttle:api`) + hard `auth-token` limiter.
- **Cross-cutting (Core):** `LogContext` sets spatie's team id per request; base `Controller` gains `AuthorizesRequests`; `DomainException` renders a friendly toast+redirect for web.
- **Frontend:** Settings → Team (members, roles, invitations, create team), Settings → API tokens, the invitation-accept page, and a sidebar **team switcher**; current team + team list shared via Inertia.
- **Tests:** registration→team+owner, 2FA challenge (existing), invite→accept, switch-team denied for non-members, cross-tenant access, token auth on a sample `/api/v1` route, membership sync, last-owner guard.

### Added — 2026-10-28 — Project bootstrap (foundation session)

- Scaffolded Laravel 13.39 + PHP 8.5 app with the official **Vue starter kit** (Inertia v2, Vue 3, TypeScript, Tailwind v4, Fortify auth, `vite-plus` toolchain), MySQL config.
- Installed & wired: Sanctum, Horizon, Reverb, Pennant, Pulse, spatie/laravel-permission, spatie/laravel-data, Sentry, Laravel Boost (dev). Horizon installed for Linux prod/CI via `config.platform` (can't run on Windows).
- **Core module** (`app/Modules/Core`): multi-team tenancy (`teams`, `team_user` pivot, `users.current_team_id`), `BelongsToTeam` trait + `TeamScope` global scope + `TeamContext`, `HasTeams` user trait, `AuditLog` + `AuditLogger`, `TeamRole` enum, `LogContext` middleware, base `DomainException`, base `ModuleServiceProvider` (route/policy auto-registration).
- App defaults: `Model::shouldBeStrict()` outside production; dedicated log channels `ai`, `lead_sourcing`, `billing`; documented `.env.example`.
- Composer scripts: `analyse` added; `dev` now runs server + queue + Reverb + Vite.
- Tests: tenancy scope (cross-tenant access fails), audit logger, TeamContext.
- **Documentation & knowledge base:** `CLAUDE.md`, `docs/architecture.md`, 20 module docs + template, ADRs 0001–0009, `docs/runbooks/{local-setup,deployment}.md`, `docs/compliance.md`, `docs/ai-prompts.md`, `docs/brand/brand-tokens.md`, and `mvp.md`.

### Added — 2026-10-28 — Project agents & skills

- Agents (`.claude/agents/`): `laravel-module-reviewer` (backend rule compliance), `brand-reviewer` (UI vs brand §2a).
- Skills (`.claude/skills/`): `new-module` (scaffold a module per ADR 0002), `quality-gates` (run gates with Herd binaries), `phase-complete` (phase definition-of-done). Registered in `CLAUDE.md §5a`.

### Decisions

- ADRs 0001–0009 (see `docs/decisions/`). Key kickoff answers: multi-team tenancy; UK + UAE markets (bilingual/RTL, GDPR+PECR+PDPL); Anthropic Claude default behind `AiClient`; providers Companies House + web search + Google Places (no paid enrichment in MVP).
