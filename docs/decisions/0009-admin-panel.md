# ADR 0009 — Admin panel: Filament (with Inertia fallback)

- **Status:** **Superseded by [ADR 0014](0014-admin-panel-bespoke-inertia.md)** (2026-10-31)
- **Date:** 2026-10-28

## Context

We need an internal super-admin panel (teams, users, plans, AI cost, provider health, failed jobs, impersonation). The spec says Filament if compatible, else a simple Inertia admin.

Packagist check (2026-10-28): **`filament/filament` v5.11.0** is current and requires PHP `^8.2`; Filament v5 targets current Laravel. Looks compatible with Laravel 13 — **to be confirmed by an actual install at the Admin phase.**

## Decision

Plan to use **Filament v5** for the internal admin panel, mounted on an admin guard separate from tenant UI, with all access audited and impersonation logged. If a clean install against Laravel 13 / PHP 8.5 proves problematic at the Admin phase, fall back to a **simple Inertia admin** reusing our design system.

## Outcome

The decision was revisited at the Admin phase, as this ADR said it would be, and **reversed**: the
panel is bespoke Inertia, under `App\Modules\Admin`. The compatibility question parked here turned
out not to be the deciding one — ADR 0014 records the four reasons that were, the first being that
Filament's generated screens write straight to models, which is the second unvalidated write path
the Action/transaction/audit rules in CLAUDE.md §2 exist to prevent.

The "admin guard separate from tenant UI" below was reversed too. A second guard over a second
table forks 2FA, passkeys, password resets and the audit trail; the platform identity is one column
on `users` and the separation is a middleware stack.

Read ADR 0014 instead of this file. It is kept for the record.

## Consequences

- Fast CRUD + resources for internal ops without building bespoke screens.
- Filament brings its own Livewire/Blade stack alongside our Inertia/Vue app — acceptable for an internal-only panel; keep it isolated.
- Decision is revisited and finalised at the Admin phase (late MVP); until then no Filament dependency is added.
