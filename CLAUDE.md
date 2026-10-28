# CLAUDE.md — AlimaProspects

Root instructions for Claude Code (and humans). Read this first, every session.

AlimaProspects is a multi-tenant SaaS that helps businesses find, qualify and contact prospects with AI (web + mobile). See `docs/architecture.md` for the system map and `mvp.md` for the phased build plan.

---

## 0. Golden rules
- **Never commit secrets.** Never weaken tenancy scoping, transactions, or tests to make something pass.
- If a requirement is unclear or conflicts with these rules, **stop and ask**.
- A phase is **not complete** until: `composer test` green, `composer analyse` clean (Larastan ≥ level 6; repo is at **7**), frontend `npm run check` + `npm run types:check` + `npm run build` pass, and **docs are updated**.

## 1. Environment & commands (Windows + Laravel Herd)
- Local dev is **Laravel Herd**. The machine also has Laragon and **Laragon wins the PATH**, so a bare `php`/`composer` may be the wrong (8.3) version. **Always use Herd's binaries:**
  - PHP 8.5: `C:/Users/alexa/.config/herd/bin/php.bat`
  - Composer: `C:/Users/alexa/.config/herd/bin/composer.bat`
- **Horizon can't run on Windows** (needs `ext-pcntl`/`ext-posix`). It is installed for Linux prod/CI via `config.platform` in `composer.json`. Locally use `php artisan queue:work`; don't run `php artisan horizon` on Windows.

### Composer scripts
| Command | What it does |
|---|---|
| `composer dev` | Runs server + queue + Vite (and Reverb) concurrently |
| `composer test` | config:clear → Pint (test) → Larastan → `artisan test` (Pest) |
| `composer lint` | Pint (fix) |
| `composer analyse` | Larastan (PHPStan) |
| `composer ci:check` | `npm run check` + `npm run types:check` + tests (what CI runs) |

Frontend: `npm run dev` / `build` / `check` (lint+format) / `check:fix` / `types:check` (vue-tsc).

## 2. Architecture rules (non-negotiable)

### Structure — domain modules (ADR 0002)
Code lives under `app/Modules/<Module>/` with: `Actions/ Services/ Contracts/ Data/ Enums/ Events/ Listeners/ Jobs/ Models/ Policies/ Http/{Controllers/Web, Controllers/Api/V1, Requests, Resources}/ Exceptions/ routes/{web,api}.php Providers/<Module>ServiceProvider.php`. Migrations/factories/seeders stay in standard Laravel dirs but are **named by module**. Tests in `tests/Feature/<Module>` + `tests/Unit/<Module>`. Frontend in `resources/js/Pages/<Module>/` + `resources/js/Components/<Module>/`. Each module has a `<Module>ServiceProvider` registered via the Core base provider.

### Code rules
- **Thin controllers:** authorise (Policy) → validate (FormRequest) → call **one** Action/Service → return (Inertia page or API Resource). No queries, no business logic, no try/catch soup in controllers.
- **Actions** = single operations (`CreateLeadAction`). **Services** = orchestration / third-party integrations. Pass DTOs in, return models/DTOs out.
- **All writes in `DB::transaction()`** at the Action level. Post-commit work (jobs, events, emails, external calls) uses `DB::afterCommit()` / `afterCommit`. **Never call external APIs inside a transaction.**
- **Multi-tenancy (ADR 0006):** every tenant table has `team_id`. Use the `BelongsToTeam` trait (global scope + auto-assign). **Policies double-check ownership.** Every tenant model needs a test proving cross-tenant access fails.
- **Logging:** structured + contextual (`Log::withContext(['team_id','user_id','request_id'])`); channels `ai`, `lead_sourcing`, `billing`. **Never log secrets or full personal data — log IDs.**
- **Errors:** domain-specific exceptions per module (extend `App\Modules\Core\Exceptions\DomainException`), rendered centrally in `bootstrap/app.php`. Friendly to users; details to logs/Sentry. External calls use timeouts, retries w/ backoff, circuit-breaker guards.
- **Jobs:** idempotent; `ShouldBeUnique` where duplicates harm; set `$tries`, `$backoff`, `$timeout`; implement `failed()`. Long AI/lead jobs report progress over Reverb.
- **Validation** only in FormRequests/Data. **Authorisation** only in Policies/Gates.
- **Enums** for every status/type column. **No magic strings.**
- **Money** = integer minor units. **Timestamps** UTC in DB, displayed in team timezone.
- **Strict types** (`declare(strict_types=1);`) in every PHP file; typed properties + return types everywhere.
- **Eloquent:** `Model::shouldBeStrict()` outside production; avoid N+1 (eager load; test with `preventLazyLoading`).
- **API:** versioned `/api/v1`, **API Resources only** (never return models), consistent error envelope, pagination, per-token rate limiting.
- **Frontend:** brand tokens as Tailwind theme vars (see `docs/brand/brand-tokens.md`); build reusable base components (`Button`, `Input`, `Card`, `Table`, `Modal`, `Badge`, `EmptyState`, `Toast`) before feature screens; responsive + accessible (WCAG AA, keyboard nav, labels); **EN/AR + RTL** (ADR 0007).
- **Tests:** every Action has a unit/feature test; every endpoint has a feature test covering auth, validation, tenancy and happy path. **Fake external providers** (AI, lead sources, Stripe) in tests.

## 3. Where things live
- `app/Modules/<Module>/` — domain code. `app/Modules/Core/` — tenancy, audit, base classes, logging, exceptions.
- `docs/` — knowledge base: `architecture.md`, `modules/<module>.md` (read before working a module), `decisions/` (ADRs), `runbooks/`, `compliance.md`, `ai-prompts.md`, `brand/brand-tokens.md`.
- `mvp.md` — phased plan + status table. `CHANGELOG.md` — notable changes.
- Config: `.env.example` documents every key. Brand PDFs in `docs/brand/`.

## 4. How to work a phase (ADR + §8 of the kickoff)
1. Read this file, the phase in `mvp.md`, and the relevant `docs/modules/*.md`.
2. Plan briefly, then implement in **small commits** with **conventional commit messages**.
3. Write/update tests alongside the code.
4. Run `composer lint`, `composer analyse`, `composer test`, and the frontend build; fix everything.
5. Update the module doc(s) (Code map, Data model, Key flows, **Changelog row**), `docs/architecture.md` if structure changed, `CHANGELOG.md`, and tick tasks + the status table in `mvp.md`.
6. Summarise what was done, anything deferred, and anything the user must do (keys, accounts, decisions).

**Before working on a module:** read its `docs/modules/<module>.md`. **After finishing:** update that doc and append a Changelog row. New module → create its doc from `docs/modules/_TEMPLATE.md` and add it to `architecture.md` + this file.

## 5. Key decisions (see `docs/decisions/`)
0001 stack · 0002 module structure · 0003 vite-plus lint · 0004 Fortify+Sanctum+spatie · 0005 AI via `laravel/ai` behind `AiClient` · 0006 multi-team tenancy · 0007 UK+UAE markets, bilingual/RTL, compliance · 0008 lead providers (Companies House + web search + Google Places; no paid enrichment in MVP) · 0009 admin (Filament).

## 6. Attribution
Git commit messages end with:
```
Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```
PR descriptions end with the Claude Code generated-with line. (Commit/push only when the user asks; branch off the default branch first.)
