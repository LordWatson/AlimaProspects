# ADR 0002 — Domain-module code structure

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

A broad B2B SaaS with many bounded concerns (tenancy, AI, sourcing, scoring, CRM, billing, compliance…). A flat `app/` would not scale and would blur ownership.

## Decision

Organise code by **domain module** under `app/Modules/<Module>/` with a fixed internal layout:

```
app/Modules/<Module>/
  Actions/ Services/ Contracts/ Data/ Enums/
  Events/ Listeners/ Jobs/ Models/ Policies/
  Http/Controllers/Web/      # Inertia controllers
  Http/Controllers/Api/V1/   # mobile/API controllers
  Http/Requests/ Http/Resources/
  Exceptions/
  routes/web.php routes/api.php
  Providers/<Module>ServiceProvider.php
```

Two directories were added to that list by later phases, and both because the alternative was to file something where nobody would look for it:

- **`Support/`** — small collaborators that are neither an Action nor a Service: `Core/Support/TeamContext`, `Core/Support/AuditContext`, `Core/Support/CircuitBreaker`, `Admin/Support/PlatformWindow`.
- **`Observability/`** (Phase 20, ADR 0017) — code whose job is to tell somebody what the module is doing: `Core/Observability/SentryOptions`, `AiPlatform/Observability/{AiUsageRecorder,AiUsageCard}`. The nearest existing homes were considered and rejected. A Pulse recorder does listen to an event, but `Listeners/` holds listeners that carry out domain work, and filing a metrics recorder there hides the one thing about it that matters: **it can be deleted and the product still works**. A Pulse card is rendered over HTTP, but it is not one of this module's screens and does not belong under `Http/Controllers/Web/` beside them. Keeping observability in its own directory is what makes "nothing in the domain depends on this" visible at a glance.

Migrations, factories and seeders stay in the standard Laravel locations but are **named/prefixed by module**. Tests live in `tests/Feature/<Module>` and `tests/Unit/<Module>`. Frontend in `resources/js/Pages/<Module>/` and `resources/js/Components/<Module>/`.

A base **module service provider** auto-registers each module's routes, policies and migration paths. Modules are registered in `bootstrap/providers.php` (or discovered) via the Core base provider.

## Consequences

- Clear ownership and discoverability; features are self-contained.
- Thin controllers calling single-purpose Actions / orchestrating Services (see `CLAUDE.md`).
- Slightly more boilerplate per module, mitigated by a generator/stub later.
