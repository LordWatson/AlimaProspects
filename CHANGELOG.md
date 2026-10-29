# Changelog

All notable changes to AlimaProspects are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); dates are ISO (UTC).

## [Unreleased]

### Added — 2026-10-29 — Phase 3: Onboarding & Business Profile

- **Onboarding module** (`app/Modules/Onboarding`): the guided, resumable wizard a new team completes and the stored **Business Profile** that feeds (later) the AI background check, ICP, scoring and outreach.
- **Data model:** `business_profiles` and `onboarding_progress` (both `BelongsToTeam`, one row per team). Money (`pricing_min`/`pricing_max`) stored as integer **minor units**; `version` bumps on each (re)completion.
- **Actions:** `SaveOnboardingStepAction` (resumable per-step save, money normalised to minor units by the chosen currency), `CompleteOnboardingAction` (guards all steps saved, stamps complete, applies the primary market to `teams.market`, dispatches the background check `afterCommit`), `GetOnboardingStateAction` (shared read for both controllers). Guard/reads/writes run in one locked transaction to make completion race-safe.
- **Background check:** `RequestBackgroundCheckJob` — a **stub** (`ShouldBeUnique` per team, `$uniqueFor`, `$tries/$backoff/$timeout`, `failed()`) that Phase 6 (Business Intelligence) will implement.
- **Enums:** `OnboardingStep`, `CompanySize`, `ServedRegion` (module) + cross-cutting `Market` and `Currency` (Core). No magic strings.
- **Web + API:** Inertia wizard (`GET /onboarding`, `POST /onboarding/step/{step}`, `POST /onboarding/complete`) guarded by `BusinessProfilePolicy` (view = any member; edit = Owner/Admin); read-only `GET /api/v1/onboarding` returning profile + progress via `OnboardingStateResource`.
- **Frontend (on brand, EN/AR + RTL, WCAG AA):** focused `OnboardingLayout` (Cloud Cream, logo, locale/appearance, no sidebar), a resumable wizard (`pages/onboarding/Wizard.vue`) with a step rail (`WizardProgress`) and a chip `ListInput`, a Dashboard first-run setup CTA (Ink panel + spectrum rule), and a sidebar "Business profile" entry. One orange full-stop accent; `rtl:font-bold` headings (Tajawal); RTL eyebrow letter-spacing reset in `app.css`.
- **i18n:** EN + AR bundles extended to full parity (141 keys each).
- **Demo data:** both demo teams get a completed Business Profile so demo logins land on the dashboard.
- **Tests:** `OnboardingWizardTest` (step save/resume, per-step validation, minor-unit conversion, completion applies market + dispatches the faked job, blocked completion, admin-allowed/read-only-forbidden, cross-tenant model + policy isolation, API auth/null/happy path, re-completion version bump) + `OnboardingStepTest`. Full suite 101 green; Larastan level 7 clean; `npm run check`/`types:check`/`build` pass. Reviewed by the `laravel-module-reviewer` and `brand-reviewer` agents; their findings actioned.

### Added — 2026-10-29 — Public marketing pages, on-brand auth & light-theme default

- **Landing page** (`resources/js/pages/Welcome.vue`) rebuilt on brand: Cloud Cream canvas, Deep Teal structure, Midnight Ink inverse panels, a hero with the promise eyebrow + display headline (one decorative orange full-stop), a three-step "how it works" section, and a closing CTA band. Brand voice throughout; one hero accent per section.
- **About page** (`resources/js/pages/About.vue`, route `GET /about` → `about`) presenting the brand's four voice principles as values, the UK + UAE bilingual story, and a CTA.
- **Marketing shell** (`resources/js/layouts/MarketingLayout.vue`): sticky 88px header (logo, nav, locale toggle, one primary CTA), footer, and a spectrum signature rule — shared by the landing + about pages.
- **Spectrum gradient utility** (`resources/css/app.css` `bg-spectrum`) — the brand §1.4 gradient with its fixed stop order (Ink·Orange·Blue·Lilac·Lime·Teal·Cream), built from palette tokens.
- **On-brand auth redesign:** `AuthSplitLayout` rebuilt as a Midnight Ink brand panel (cream logo colourway — never Teal-on-Ink — tagline + spectrum rule) beside the Cloud Cream form column with a locale toggle; `AuthLayout` now uses it. `Login`/`Register` copy moved to brand voice; the login status message now uses the `success` token instead of a raw `text-green-600`.
- **Light theme is now the default** (`HandleAppearance.php`, `resources/views/app.blade.php`, `useAppearance.ts`): visitors land on the Cloud Cream theme unless they explicitly choose Dark or System.
- **i18n:** EN + AR bundles extended with all marketing/about copy (keys kept at parity; `LocaleTest` still green). Auth page titles/descriptions now translate through `t()`.
- **Brand-review follow-ups:** status colours moved off raw Tailwind palette values onto the `success`/`destructive` tokens (`InputError`, `DeleteUser`, `VerifyEmail`, `ForgotPassword`, `TwoFactorSetupModal`, settings `Profile`); logo lockups given a reliable accessible name (`role="img"`); new `inverse` Button variant (cream-on-Ink) replacing an ad-hoc override; a `tracking-eyebrow` theme token replacing arbitrary eyebrow tracking; `rtl:font-bold` on marketing headings (Tajawal has no 600 weight); one orange accent + one spectrum band per layout; a compact mobile menu in the marketing header.

### Added — 2026-10-28 — Phase 2: Brand design system & app shell (i18n/RTL)

- **Brand tokens in Tailwind v4** (`resources/css/app.css`): the full Alima palette (Deep Teal, Midnight Ink, Cloud Cream, Signal Orange + Electric Blue/Soft Lilac/Acid Lime, each with tints), semantic digital tokens mapped onto the shadcn component variables, brand `radius`/`shadow`/`motion`, and the Poppins / DM Mono / Tajawal type families. Fonts load from Bunny (GDPR-friendly). Lead-with-cream; **Signal Orange is decorative only — never a status colour** (status.* tokens carry state).
- **Dark theme** implemented from `brand-tokens.md` §7.1 — ⚠️ **PENDING brand-owner approval** (the book defines only `bg.inverse` sections); flagged in the CSS and `mvp.md`.
- **Shared token module** `resources/js/shared/tokens.ts` — the single TS source of truth for the palette/type/spacing/motion, ready for the Capacitor mobile app to import.
- **Base component library** (on brand, WCAG AA, keyboard-accessible): rebranded `Button` (pill, Ink primary / Teal `brand` / Teal link) and `Badge` (status variants from status tokens, decorative accent chips); new `Table` set and `EmptyState`; `Input`/`Toast`/`Dialog`/`Card` inherit the tokens. A living `/design` showcase page demonstrates them all.
- **App shell:** Alima wordmark + the approved **snout** mark (real panda master SVG still PENDING, §7.3), brand-coloured team switcher, a **locale toggle** and **appearance toggle** in the top bar and user menu; nav labels translated.
- **i18n (EN/AR + RTL, ADR 0007):** `SetLocale` middleware (cookie → active-team locale → default) on web + API; `Locale` support class; `POST /locale` switcher; `lang/en.json` + `lang/ar.json`; locale + `dir` + the active translation bundle shared via Inertia; `useI18n`, `useLocaleDirection` (keeps `<html lang/dir>` in sync across SPA visits) and `useFormat` (locale/timezone-aware numbers & dates) composables; Tajawal + 10–15% size boost under RTL; components use logical properties.
- **Realtime:** Laravel Echo over Reverb wired on the front end (`resources/js/echo.ts`, `laravel-echo` + `pusher-js`, `window.Echo`) using the existing `VITE_REVERB_*` env.
- **Tests:** `LocaleTest` (8) — default, cookie resolution + RTL, unsupported-locale fallback, team fallback, cookie-overrides-team, switch persists cookie, invalid-locale rejected, options metadata. Full suite green (79).

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
