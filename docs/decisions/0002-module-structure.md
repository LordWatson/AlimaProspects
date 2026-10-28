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

Migrations, factories and seeders stay in the standard Laravel locations but are **named/prefixed by module**. Tests live in `tests/Feature/<Module>` and `tests/Unit/<Module>`. Frontend in `resources/js/Pages/<Module>/` and `resources/js/Components/<Module>/`.

A base **module service provider** auto-registers each module's routes, policies and migration paths. Modules are registered in `bootstrap/providers.php` (or discovered) via the Core base provider.

## Consequences
- Clear ownership and discoverability; features are self-contained.
- Thin controllers calling single-purpose Actions / orchestrating Services (see `CLAUDE.md`).
- Slightly more boilerplate per module, mitigated by a generator/stub later.
