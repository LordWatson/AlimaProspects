# Changelog

All notable changes to AlimaProspects are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are ISO (UTC).

## [Unreleased]

### Added — 2026-10-28 — Project bootstrap (foundation session)
- Scaffolded Laravel 13.39 + PHP 8.5 app with the official **Vue starter kit** (Inertia v2, Vue 3, TypeScript, Tailwind v4, Fortify auth, `vite-plus` toolchain), MySQL config.
- Installed & wired: Sanctum, Horizon, Reverb, Pennant, Pulse, spatie/laravel-permission, spatie/laravel-data, Sentry, Laravel Boost (dev). Horizon installed for Linux prod/CI via `config.platform` (can't run on Windows).
- **Core module** (`app/Modules/Core`): multi-team tenancy (`teams`, `team_user` pivot, `users.current_team_id`), `BelongsToTeam` trait + `TeamScope` global scope + `TeamContext`, `HasTeams` user trait, `AuditLog` + `AuditLogger`, `TeamRole` enum, `LogContext` middleware, base `DomainException`, base `ModuleServiceProvider` (route/policy auto-registration).
- App defaults: `Model::shouldBeStrict()` outside production; dedicated log channels `ai`, `lead_sourcing`, `billing`; documented `.env.example`.
- Composer scripts: `analyse` added; `dev` now runs server + queue + Reverb + Vite.
- Tests: tenancy scope (cross-tenant access fails), audit logger, TeamContext.
- **Documentation & knowledge base:** `CLAUDE.md`, `docs/architecture.md`, 20 module docs + template, ADRs 0001–0009, `docs/runbooks/{local-setup,deployment}.md`, `docs/compliance.md`, `docs/ai-prompts.md`, `docs/brand/brand-tokens.md`, and `mvp.md`.

### Decisions
- ADRs 0001–0009 (see `docs/decisions/`). Key kickoff answers: multi-team tenancy; UK + UAE markets (bilingual/RTL, GDPR+PECR+PDPL); Anthropic Claude default behind `AiClient`; providers Companies House + web search + Google Places (no paid enrichment in MVP).
