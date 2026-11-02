# CLAUDE.md — AlimaProspects

Root instructions for Claude Code (and humans). Read this first, every session.

AlimaProspects is a multi-tenant SaaS that helps businesses find, qualify and contact prospects with AI (web + mobile). See `docs/architecture.md` for the system map and `mvp.md` for the phased build plan.

---

## 0. Golden rules

- **Never commit secrets.** Never weaken tenancy scoping, transactions, or tests to make something pass.
- If a requirement is unclear or conflicts with these rules, **stop and ask**.
- A phase is **not complete** until: `composer test` green, `composer analyse` clean (Larastan ≥ level 6; repo is at **7**), frontend `npm run check` + `npm run types:check` + `npm run build` pass, and **docs are updated**.
- **The brand is law (see §2a).** Any UI — web or mobile — must obey the Alima brand defined in the two source PDFs. Do not invent colours, type, spacing, logo treatment or tone.

## 2a. Brand — the source of truth (read before ANY frontend work)

The authoritative brand lives in **two PDFs**, both in **`.claude/docs/brand/`** (originals) and mirrored in **`docs/brand/`**:

- **`Alima — Brand Guidelines.pdf`** — the full 67-page system: logo, colour, typography, elements, voice, digital tokens, print.
- **`Alima.pdf`** — the companion brand file.

Before building or changing any screen, component, email, or mobile view:

1. **Read `docs/brand/brand-tokens.md`** — the engineering extraction of the PDFs (palette with hex, semantic digital tokens, type scale, radius/shadow/motion, spacing/grid, logo rules, voice). If a detail you need isn't there, **open the PDFs** and, if useful, extract more into `brand-tokens.md`.
2. **Build with tokens, never raw hex.** Colours/type/spacing come from the Tailwind `@theme` tokens (encoded in Phase 2 from `brand-tokens.md`), not hand-picked values.
3. **Obey the non-negotiables:** lead with Cloud Cream, one hero accent per layout; **Signal Orange is decorative only — never a status colour** (use `status.*`); Poppins (Latin) / Tajawal (Arabic) / DM Mono (labels); logo clear-space, min-sizes and approved colourways; WCAG AA; **EN/AR + RTL**; brand voice (bright, bold, clear, together) for all copy.
4. **When in doubt:** fewer colours, more cream, a bigger logo — then ask. Anything not covered by the PDFs is flagged for brand-owner approval (see `brand-tokens.md §7`), never silently invented.

Changes to brand interpretation are recorded in `brand-tokens.md`; the PDFs themselves are the fixed reference.

## 1. Environment & commands (Windows + Laravel Herd)

- Local dev is **Laravel Herd**. The machine also has Laragon and **Laragon wins the PATH**, so a bare `php`/`composer` may be the wrong (8.3) version. **Always use Herd's binaries:**
    - PHP 8.5: `C:/Users/alexa/.config/herd/bin/php.bat`
    - Composer: `C:/Users/alexa/.config/herd/bin/composer.bat`
- **Horizon can't run on Windows** (needs `ext-pcntl`/`ext-posix`). It is installed for Linux prod/CI via `config.platform` in `composer.json`. Locally use `php artisan queue:work`; don't run `php artisan horizon` on Windows.

### Composer scripts

| Command             | What it does                                                   |
| ------------------- | -------------------------------------------------------------- |
| `composer dev`      | Runs server + queue + Vite (and Reverb) concurrently           |
| `composer test`     | config:clear → Pint (test) → Larastan → `artisan test` (Pest)  |
| `composer lint`     | Pint (fix)                                                     |
| `composer analyse`  | Larastan (PHPStan)                                             |
| `composer ci:check` | `npm run check` + `npm run types:check` + tests (what CI runs) |

Frontend: `npm run dev` / `build` / `check` (lint+format) / `check:fix` / `types:check` (vue-tsc).

**The mobile app is its own npm project** (`/mobile`, Phase 18) with the same gates and its own
copies of the lint and format config: `cd mobile && npm run check && npm run types:check && npm run test && npm run build`.
The root `npm run check` deliberately skips `mobile/` — not to lint it less, but because the root
pass also reaches its committed native projects (Gradle, Xcode asset catalogues, `project.pbxproj`),
which are not ours to format. To run or test the app, read **`docs/runbooks/mobile-dev.md`** — it
assumes no mobile experience, and it is where the emulator/host-address traps are written down.

## 2. Architecture rules (non-negotiable)

### Structure — domain modules (ADR 0002)

Code lives under `app/Modules/<Module>/` with: `Actions/ Services/ Contracts/ Data/ Enums/ Events/ Listeners/ Jobs/ Models/ Policies/ Support/ Observability/ Http/{Controllers/Web, Controllers/Api/V1, Requests, Resources}/ Exceptions/ routes/{web,api}.php Providers/<Module>ServiceProvider.php`. (`Support/` is small collaborators that are neither an Action nor a Service; **`Observability/`** is code whose job is to report what the module is doing and which could be deleted with the product still working — a Pulse recorder is not a `Listeners/` listener for that reason. Both are explained in ADR 0002.) Migrations/factories/seeders stay in standard Laravel dirs but are **named by module**. Tests in `tests/Feature/<Module>` + `tests/Unit/<Module>`. Frontend in `resources/js/Pages/<Module>/` + `resources/js/Components/<Module>/`. Each module has a `<Module>ServiceProvider` registered via the Core base provider.

### Code rules

- **Thin controllers:** authorise (Policy) → validate (FormRequest) → call **one** Action/Service → return (Inertia page or API Resource). No queries, no business logic, no try/catch soup in controllers.
- **Actions** = single operations (`CreateLeadAction`). **Services** = orchestration / third-party integrations. Pass DTOs in, return models/DTOs out.
- **All writes in `DB::transaction()`** at the Action level. Post-commit work (jobs, events, emails, external calls) uses `DB::afterCommit()` / `afterCommit`. **Never call external APIs inside a transaction.**
- **Multi-tenancy (ADR 0006):** every tenant table has `team_id`. Use the `BelongsToTeam` trait (global scope + auto-assign). **Policies double-check ownership.** Every tenant model needs a test proving cross-tenant access fails.
- **Logging:** structured + contextual (`Log::withContext(['team_id','user_id','request_id'])`); a channel per module where one earns it — `ai`, `lead_sourcing`, `signals`, `outreach`, `notifications`, `insights`, `billing` (add yours to `config/logging.php` and say so in the module doc). **Never log secrets or full personal data — log IDs.**
- **Errors:** domain-specific exceptions per module (extend `App\Modules\Core\Exceptions\DomainException`), rendered centrally in `bootstrap/app.php`. Friendly to users; details to logs/Sentry. External calls use timeouts, retries w/ backoff, circuit-breaker guards.
- **Jobs:** idempotent; `ShouldBeUnique` where duplicates harm; set `$tries`, `$backoff`, `$timeout`; implement `failed()`. Long AI/lead jobs report progress over Reverb.
- **Validation** only in FormRequests/Data. **Authorisation** only in Policies/Gates. **Compliance
  gating is neither** — whether a lead may be approached is `Outreach\Services\OutreachGuard`'s
  answer, not a policy's: authorisation asks whether this member may act, the guard asks whether this
  prospect may be approached, and conflating them means a legal rule enforced by a role check.
- **Never notify directly.** Raising a notification is
  `Notifications\Services\NotificationDispatcher` (`send` / `toTeam` / `toAdmins`) — never Laravel's
  facade, never `$user->notify()`. The dispatcher is what resolves the member's per-team channel
  preferences, stamps `team_id` on the row, drops members who have left, and keeps the stored payload
  free of prose (keys + params, never a sentence). A notification that would fan out per record needs
  **aggregating** first — see `AnnounceTopLeadsJob` for the pattern.
- **Anything that reaches a prospect goes through Outreach.** Suppression, sender identification and
  the opt-out are conditions of sending, not features of a screen (`docs/compliance.md` §3.2–3.3).
- **Enums** for every status/type column. **No magic strings.**
- **Money** = integer minor units. **Timestamps** UTC in DB, displayed in team timezone.
- **Strict types** (`declare(strict_types=1);`) in every PHP file; typed properties + return types everywhere.
- **Eloquent:** `Model::shouldBeStrict()` outside production; avoid N+1 (eager load; test with `preventLazyLoading`).
- **API:** versioned `/api/v1`, **API Resources only** (never return models), consistent error envelope, pagination, per-token rate limiting. Since **Phase 17** each of those is a property of _every_ endpoint, asserted over the whole route table in `tests/Feature/Api/V1/RouteContractTest.php` — a new route must be declared by its own module (`routes/api.php` is deliberately empty), named, behind `auth:sanctum`, scoped with an `ability:` from `Auth\Enums\TokenAbility`, and present in `docs/api/openapi.json`. The error envelope has one definition, `Core\Http\Responses\ApiError`; a module adds a failure mode by extending `DomainException` with its own code, never by inventing a response shape. Scoped **by path** (`api/*`), because the web forms depend on Laravel's native 422 for their error bags. `per_page` comes from `Core\Http\Requests\PaginatedIndexRequest`.
- **Frontend:** **obey the brand (§2a)** — the two PDFs in `.claude/docs/brand/` + `docs/brand/`, via `docs/brand/brand-tokens.md`. Brand tokens as Tailwind theme vars, never raw hex; build reusable base components (`Button`, `Input`, `Card`, `Table`, `Modal`, `Badge`, `EmptyState`, `Toast`) before feature screens; responsive + accessible (WCAG AA, keyboard nav, labels); **EN/AR + RTL** (ADR 0007). Signal Orange is decorative only, never a status colour.
- **Tests:** every Action has a unit/feature test; every endpoint has a feature test covering auth, validation, tenancy and happy path. **Fake external providers** (AI, lead sources, Stripe) in tests.

## 3. Where things live

- `app/Modules/<Module>/` — domain code. `app/Modules/Core/` — tenancy, audit, base classes, logging, exceptions, **the response security headers and the Sentry scrubber** (Phase 20).
- **Phase 20 (Observability & security hardening) is complete** — read **ADR 0017** before touching
  anything in `config/security.php`, `config/observability.php`, `config/sentry.php`,
  `config/pulse.php` or `app/Modules/Core/{Observability,Support/Security}`, and
  **`docs/runbooks/observability.md`** when something is broken in production. Five things about it
  are easy to get wrong. `SecurityHeaders` is **global** middleware, not a group — the headers
  belong to the response, and a group is how the one route nobody thought about becomes the one
  route without a header. The **CSP is nonce-based with no `unsafe-inline`**, which is only
  affordable because this app has no third-party script: a new inline tag needs the nonce
  (`Vite::cspNonce()`), and a new third-party host goes in `SECURITY_CSP_EXTRA_*` rather than into a
  loosened directive — and it is deliberately **not** emitted under `npm run dev`, so test a policy
  change against a built environment. Sentry's **scrubber is aimed at our own code**: the realistic
  leak is a log context becoming a breadcrumb, so log IDs, never prose or payloads, and add a key to
  `observability.sentry.scrub` rather than trusting the vendor's defaults. **A new expected refusal
  belongs in `sentry.ignore_exceptions`** with a comment, because that list is also the list of
  things we have decided not to be told about — but never the base `DomainException`. And `/pulse`
  is behind the admin panel's own four controls plus `AdminAbility::ManageOperations`: it is a
  cross-tenant surface, not a lesser one, and **Pulse is a clock while `/admin` is a tenant** —
  neither answers the other's question.
- `docs/` — knowledge base: `architecture.md`, `modules/<module>.md` (read before working a module), `decisions/` (ADRs), `runbooks/`, `compliance.md`, `ai-prompts.md`, `brand/brand-tokens.md`.
- `mvp.md` — phased plan + status table. **Phases 11 (Outreach), 12 (Notifications), 13 (Reporting & Analytics), 14 (AI Insights), 15 (Compliance & Privacy), 16 (Billing & Plans) and 17 (API v1 hardening) are complete** — read `docs/modules/outreach.md`, `docs/modules/notifications.md`, `docs/modules/reporting-analytics.md`, `docs/modules/ai-insights.md`, `docs/modules/compliance-privacy.md` and `docs/modules/billing-plans.md` before touching any of them. Compliance in particular: it reaches other modules through **three contracts, not by them knowing about it** — `LeadSourcing\Contracts\LeadInterestGate` (no recorded lawful basis, no discovery), `Outreach\Contracts\LeadRestrictionResolver` (how a prospect may be approached) and `Signals\Services\SignalsPersonalDataExporter`. Add a compliance rule by binding one of those, not by importing `App\Modules\Compliance` into another module. The do-not-contact table stays `outreach_suppressions`. Billing works the same way and is the **last** provider registered: it binds the real `AiPlatform\Contracts\CreditSource`, `LeadSourcing\Contracts\LeadQuotaSource` and `Auth\Contracts\SeatLimitSource` over the permissive stubs those modules ship as defaults, so no module imports `App\Modules\Billing` — change a plan limit by changing `config/billing.php` or one of those bindings, never by teaching another module what a plan is. Enforcement lives in the **guards**, not in middleware, because a queued discovery job and a scheduled watch spend a team's allowance with no request behind them; and `usage_meters` is a **record** over the `ai_requests`/`discovery_runs` ledgers, not a counter the app increments. Reporting in particular: every figure is scoped by one window whose **cohort rule** is written down there, and its bounds must reach a query in **UTC** (`DateRange::constrain()`) or a non-UTC team's dashboard silently shifts. `signals-plan.md` — the Signals (trigger-event prospecting) build plan, phases S0–S6, **complete** (companion to `mvp.md`; see `docs/modules/signals.md`, `docs/runbooks/signals-demo.md` + ADR 0011). `signals-v2-plan.md` — the Signals v2 plan, phases **S7–S16**; **S7 and S8 are complete** (signal types are data a team owns — ADR 0018; the geography is plural and the outreach restriction follows the signal's own jurisdiction — ADR 0019), S9–S16 are planned. Four things about Signals that are easy to get wrong after S8: a signal **type** is a row and not an enum (`SignalDefinition`; the four status/cadence/reason enums stay enums, and ADR 0018 §1 is the test for which is which), and `signal_definitions` is the one table holding both tenant rows and **platform presets** — only `PlatformPresetReader` may read a preset, a team **clones** one rather than referencing it, and nobody edits one. The query builder no longer reads `config('signals.templates')`: that key is seed data for the presets. Third, **where a watch searches and which law applies to its leads are two different questions** — `SearchRegion` answers the first, `Market` the second, and ADR 0019 is why `Market` must not grow to cover a region nobody has taken a legal position on. Fourth, the restriction on a converted lead **follows the subject**: the signal's own `resolved_market` first, the watch's `market` as a backstop, its regions able only to add, and an unresolvable place failing _toward_ the restriction — keying it on the watch alone was a permissive compliance bug (`docs/compliance.md` §3.11). `CHANGELOG.md` — notable changes.
- **Phase 19 (Admin panel, 19a–19c) is complete** — read `docs/modules/admin.md` before touching
  `app/Modules/Admin`, and `docs/runbooks/admin-panel.md` to operate it. Four things about it are
  easy to get wrong. It **owns no business rule**: where a module owns an operation the panel calls
  that module's Action, and it reaches other modules by **decorating their seams** (the three
  suspension guards, `StripeSwitch`, `CapabilityGate`) rather than importing them. `PlatformContext`
  removes tenant scoping and refuses to run on a route not named `admin.*`, with
  `AdminRouteContractTest` asserting the whole stack over the whole route table — a per-route test
  only covers the routes somebody remembered to write one for. **Platform reporting is not tenant
  reporting with the scope removed** (ADR 0016): it has its own builders and its own questions over
  `platform_daily_metrics`, a nightly **record over the ledgers** that must stay scheduled _before_
  the nightly sweeps, because a swept period cannot be recomputed. And a **platform suppression is a
  `global`-scoped row in `outreach_suppressions`**, not a second table — `OutreachGuard` is still
  the only thing that answers whether a prospect may be approached.
- **Phase 18 (Mobile app) is complete** — read `docs/modules/mobile-app.md` before touching `/mobile`, and `docs/runbooks/mobile-dev.md` to run it. Three things about it that are easy to get wrong: it shares code with the web app by **Vite alias and never by copy** (`@shared` → `resources/js/shared/`, `@lang` → `lang/`, and the master logo path now lives in `shared/brand/artwork.ts` — a logo that exists twice is a logo that drifts); it is a **field companion, not a second web app**, so settings, discovery, ICPs, the board, billing and compliance stay on the web by decision rather than by omission; and the native artwork is **generated** from the vector path by `npm run artwork`, so changing the logo means rerunning that, not hand-editing a drawable. The only backend work it added is the two-factor token exchange (`Auth\Services\TwoFactorChallenge` — a phone has neither Fortify's session nor its redirect, so the challenge settles in one request, and the password is checked before anything reveals the account has a second factor) and a published `config/cors.php` whose `exposed_headers` is the line that makes `X-Api-Version` and `Retry-After` readable to a cross-origin caller at all.
- `docs/api/` — the **API contract**: `openapi.json` (generated from the routes by `composer api:docs`; dedoc/scramble is a **dev** dependency, so never reference a Scramble class from `config/`) and `README.md` (the version policy, the token scopes, the error envelope, the pagination rule). Read it before adding or changing an `/api/v1` endpoint, then **regenerate the specification** — `RouteContractTest` fails if a route is missing from it.
- Config: `.env.example` documents every key. **Brand PDFs in `.claude/docs/brand/` and `docs/brand/`** (`Alima — Brand Guidelines.pdf`, `Alima.pdf`) → extracted to `docs/brand/brand-tokens.md`. See §2a.

## 4. How to work a phase (ADR + §8 of the kickoff)

1. Read this file, the phase in `mvp.md`, and the relevant `docs/modules/*.md`.
2. Plan briefly, then implement in **small commits** with **conventional commit messages**.
3. Write/update tests alongside the code.
4. Run `composer lint`, `composer analyse`, `composer test`, and the frontend build; fix everything.
5. Update the module doc(s) (Code map, Data model, Key flows, **Changelog row**), `docs/architecture.md` if structure changed, `CHANGELOG.md`, and tick tasks + the status table in `mvp.md`.
6. Summarise what was done, anything deferred, and anything the user must do (keys, accounts, decisions).

**Before working on a module:** read its `docs/modules/<module>.md`. **After finishing:** update that doc and append a Changelog row. New module → create its doc from `docs/modules/_TEMPLATE.md` and add it to `architecture.md` + this file.

## 5. Key decisions (see `docs/decisions/`)

0001 stack · 0002 module structure · 0003 vite-plus lint · 0004 Fortify+Sanctum+spatie · 0005 AI via `laravel/ai` behind `AiClient` · 0006 multi-team tenancy · 0007 UK+UAE markets, bilingual/RTL, compliance · 0008 lead providers (Companies House + web search + Google Places; no paid enrichment in MVP) · 0009 admin (Filament) · 0010 web-search provider (Serper) · 0011 Signals module & Serper News (UAE-only trigger-event prospecting; own module, not a `LeadSourceProvider`) · 0012 billing (the **team** is the billable party; plan limits bite in the guards, not in middleware; allowances run on the calendar month in UTC; `usage_meters` is a derived record, not a counter) · 0013 the **`/api/v1` contract** (one error envelope, scoped by path and not by `Accept`; token scopes in middleware rather than a policy, because a scope asks what a _credential_ may do; a first-party session passes unscoped; the specification is generated and committed; the shared TS types are moved, not generated; a version changes only for a break) · 0014 the **admin panel is bespoke Inertia, not Filament** (supersedes 0009) · 0015 **plan-driven capabilities** behind one `CapabilityGate` decorated three times, plus the schema'd platform settings store · 0016 **platform reporting** (its own builders and its own questions, never Reporting's Actions with the scope removed; `platform_daily_metrics` is a record over the ledgers, not a counter; the rollup runs before the nightly sweeps because a swept period cannot be recomputed). · 0017 **observability & security posture** (Sentry sends an id, a tenant and a stack trace and nothing about a person — `send_default_pii` off plus a scrubber aimed at _our own_ `Log::error` contexts, which is the realistic leak; expected refusals filtered declaratively so the tracker stays readable; a **nonce-based CSP** with no `unsafe-inline`, on global middleware so every response carries the headers, skipped only under the Vite dev server and on the vendor dashboards; HSTS follows the **connection**, not the environment; Pulse and Horizon behind the admin panel's own door and `AdminAbility::ManageOperations` — Pulse is a clock, `/admin` is a tenant; the AI prompt bounded in **characters** by a truncation and a refusal; `security:posture` checks the **machine**, not the repository) · 0018 **signal types are data, not an enum** (Signals S7: a `SignalDefinition` is a row a team owns, so a new kind of trigger event is configuration rather than a deploy; the exception to §2's "enums for every type column" is decided by one test — _does the application branch on the value?_ — which the status, run-status, cadence and dismiss-reason enums pass and the event type never did; `team_id = null` is a **platform preset** and `Signals\Services\PlatformPresetReader` is the one place that removes the tenancy scope to read one; a team **clones** a preset rather than referencing it, so a platform edit never silently changes what they are paying to run; and the AI schema's `event_type` enum could not survive per-team keys, so `RunSignalWatchAction` became the guard the schema used to be) · 0019 **search geography is not the compliance market** (Signals S8: `SearchRegion` answers _where do we look_ and `Market` answers _which rules apply_, and one column was doing both; `Market` deliberately does **not** grow to the new regions, because every case on it asserts a legal position the business has taken, so `SearchRegion::market()` is nullable, a region with no position contributes nothing to a lead's restriction and is **not offered** at all; the outreach restriction follows the **subject** — the signal's own resolved jurisdiction first, the watch's `market` as a backstop, its regions able only to _add_ — and an unresolvable place fails **toward** the restriction, never away from it; "most restrictive" is asked of `OutreachRestriction::allowsDirectMarketing()` rather than hard-coded; see `docs/compliance.md` §3.11 for the general rule every later source is held to)

## 5a. Project agents & skills (`.claude/`)

Use these instead of ad-hoc work; they encode this repo's rules.

- **Skill `new-module`** — scaffold a domain module per ADR 0002 (dirs, provider, routes, doc).
- **Skill `quality-gates`** — run Pint/Larastan/Pest + `vue-tsc`/build with the correct **Herd binaries** (avoids the Laragon PATH trap).
- **Skill `phase-complete`** — the definition-of-done checklist for an `mvp.md` phase.
- **Agent `laravel-module-reviewer`** — reviews backend changes against these rules (tenancy, transactions, layering, types, jobs, API, tests). Run after implementing a phase.
- **Agent `brand-reviewer`** — reviews UI against the brand (§2a). Run after any frontend change.

Also available (built-in): **`dataviz`** (charts for the Reporting phase), **`code-review`** / **`security-review`** (run at Phase 20; its findings and what was accepted are in ADR 0017 and `docs/runbooks/observability.md`), **`claude-api`** (AI Platform phase), **`run`** (launch the app).

## 6. Attribution

Git commit messages end with:

```
Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

PR descriptions end with the Claude Code generated-with line. (Commit/push only when the user asks; branch off the default branch first.)

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:

- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.
