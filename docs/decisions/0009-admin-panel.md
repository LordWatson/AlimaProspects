# ADR 0009 — Admin panel: Filament (with Inertia fallback)

- **Status:** Proposed (confirm at the Admin phase)
- **Date:** 2026-10-28

## Context
We need an internal super-admin panel (teams, users, plans, AI cost, provider health, failed jobs, impersonation). The spec says Filament if compatible, else a simple Inertia admin.

Packagist check (2026-10-28): **`filament/filament` v5.11.0** is current and requires PHP `^8.2`; Filament v5 targets current Laravel. Looks compatible with Laravel 13 — **to be confirmed by an actual install at the Admin phase.**

## Decision
Plan to use **Filament v5** for the internal admin panel, mounted on an admin guard separate from tenant UI, with all access audited and impersonation logged. If a clean install against Laravel 13 / PHP 8.5 proves problematic at the Admin phase, fall back to a **simple Inertia admin** reusing our design system.

## Consequences
- Fast CRUD + resources for internal ops without building bespoke screens.
- Filament brings its own Livewire/Blade stack alongside our Inertia/Vue app — acceptable for an internal-only panel; keep it isolated.
- Decision is revisited and finalised at the Admin phase (late MVP); until then no Filament dependency is added.
