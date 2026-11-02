# ADR 0014 — The admin panel is bespoke Inertia, not Filament

- **Status:** Accepted
- **Date:** 2026-10-31
- **Supersedes:** [ADR 0009](0009-admin-panel.md)

## Context

ADR 0009 planned to use **Filament v5** for the internal operations panel, with a "simple Inertia admin" as the fallback if a clean install against Laravel 13 / PHP 8.5 proved awkward. The decision was explicitly left to be confirmed at the Admin phase, and no Filament dependency was added in the meantime.

By the time that phase arrived (Phase 19), the requirements had grown well beyond the resource CRUD the original ADR was weighing. The panel now has to:

- read and write **every** resource in the product from **outside team scope**, with every cross-tenant read and write audited;
- **impersonate** a customer, with a hard list of things an impersonated session may not do;
- suspend a tenant so that it stops sourcing, sending and spending — enforced in the guards, not in middleware;
- switch Stripe off globally and per team, grant plans manually, and gate features by plan (Phase 19b);
- report the platform's own numbers, including gross margin per team (Phase 19c);
- provision a new business end to end (Phase 19b).

## Decision

**Build the panel ourselves, as part of the existing Inertia/Vue application**, under a new `App\Modules\Admin` module mounted at `/admin`. No Filament.

The panel is separated from the tenant application by **a middleware stack and a route prefix**, not by a second authentication guard:

- `users.admin_role` carries the platform identity (`super_admin`, `support`, `finance`, `read_only`), and the role-to-ability mapping lives in `config/admin.php`.
- `/admin` is behind `auth` → `EnsurePlatformAdmin` → `AdminIpAllowlist` → `EnsureAdminTwoFactor` → `PlatformContext` → `throttle:admin`, plus exactly one `admin.can:<ability>` per route.
- Resource CRUD is driven by a **declarative registry** (`Admin\Resources\*Resource`, listed in `config/admin.php`) rendered by three generic Inertia pages.

## Why not Filament

Four reasons, in rough order of weight.

**1. Its generated screens write straight to models.** This is the decisive one. CLAUDE.md §2 requires that writes go through an Action, inside `DB::transaction()`, with post-commit work deferred and an audit entry written. Several of this product's invariants live only in those Actions: `RemoveTeamMemberAction` refuses to remove a team's last Owner, `EraseSubjectDataAction` suppresses the erased identity so the next discovery run does not re-source the person who asked to be forgotten, `SwapPlanAction` talks to Stripe and lets the webhook write the row back. A resource panel that saves `$model->fill($data)->save()` is precisely the second, unvalidated write path those rules exist to prevent — and it would be the _default_ behaviour, needing discipline on every resource to avoid rather than discipline once to allow.

Our registry takes the opposite default: `allowsCreate`, `allowsUpdate` and `allowsDelete` are all **false** unless a descriptor says otherwise, only declared fields are written, and where a module owns the operation the panel calls that module's Action.

**2. A second frontend stack to keep brand-compliant.** Filament brings Livewire and Blade alongside Inertia and Vue: a second asset pipeline, a second theming surface, and a second set of components to hold against the brand (CLAUDE.md §2a). The panel is internal, so the bar is lower than a customer screen — but "lower" is not "none", and an operator must be able to tell at a glance that they are in the panel rather than in a tenant's account. Doing that _within_ the brand (a Deep Ink shell, DM Mono labels) is a layout we already had the tokens for.

**3. Tenancy.** `BelongsToTeam`'s global scope is a no-op when no team is active, which means the panel's cross-tenant reads work by _removing_ a constraint rather than adding one. That is a property worth having exactly one implementation of, with exactly one test asserting it over the whole route table (`AdminRouteContractTest`). A second framework resolving its own queries, with its own idea of scopes and its own middleware stack, is a second place that property can quietly fail — and the failure mode is showing one customer's prospect list to another.

**4. The hard parts are bespoke anyway.** Plan-driven feature flags, manual billing with per-team plan grants, provisioning, impersonation with a forbidden-action list, and platform margin reporting are all custom under any framework. Filament's contribution would have been the resource tables, which — given a declarative descriptor and three generic pages — came to a few hundred lines. The CRUD was the cheap part.

## Consequences

**Good.**

- One stack, one auth path, one audit trail, one set of components. Adding a resource is a descriptor plus a line in `config/admin.php`, with no frontend change.
- `config/admin.php` is a single readable answer to the two questions a reviewer asks: what may each operator role do, and what can an operator see and change.
- The panel reuses the product's own primitives rather than reimplementing them: `TeamContext::run()` for a one-tenant read, Reporting's `ColumnFormat` for cells, Pennant for Phase 19b's flags, `DateRange` for Phase 19c's windows.
- No new production dependency, and nothing added to the asset bundle a customer downloads.

**Costs, accepted.**

- We maintain the registry. The mitigation is a hard line, enforced by tests: descriptors return **data**. Anything that needs to decide something gets a bespoke screen — which is what `TeamOperationsController` and `UserOperationsController` are. The failure mode to watch for is a descriptor growing conditionals.
- No relation managers, no bulk actions, no file uploads, no form builder. Each of those would be a deliberate addition, and the honest answer to most of them is a bespoke screen.
- The panel is **English only**, and **web only** (no `/api/v1/admin`). Both are decisions rather than omissions; see `docs/modules/admin.md`.

## Alternatives considered

- **Filament v5 as planned (ADR 0009).** Rejected above. The compatibility question the ADR parked was never the real one.
- **A second auth guard over an `admins` table**, as ADR 0009 implied by "an admin guard separate from tenant UI". Rejected: it forks 2FA, passkeys, password resets and the audit trail, and what you actually want is one person with one credential and one trail. Separation is the middleware stack.
- **Nova.** Paid, and shares Filament's write-straight-to-models default.
- **No panel; `artisan tinker` and SQL.** What we had. It does not audit, it cannot be given to a support engineer, and the operations Phase 19b needs (manual billing, plan grants, provisioning) are not shell one-liners.

## Follow-ups

- **Phase 19b** added the platform settings store, the Stripe switches and plan grants, plan-driven capabilities and `ProvisionTeamAction`. [ADR 0015](0015-plan-capabilities-and-platform-settings.md) records the capability-resolution order — and why it is **not** built on Pennant, which this ADR had assumed.
- **Phase 19c** adds platform reporting over a `platform_daily_metrics` rollup, failed jobs, provider health, and the operator views deferred here from Signals, Compliance and Billing.
- **Phase 20** should assert `ADMIN_REQUIRE_TWO_FACTOR` is on in production and review the `/admin` rate limiter alongside the rest of the hardening pass.
