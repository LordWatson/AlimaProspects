---
name: new-module
description: Scaffold a new domain module in AlimaProspects following ADR 0002 — the app/Modules/<Module> structure, its service provider, routes, and the module doc. Use when starting a module that doesn't exist yet (e.g. "create the Lead Sourcing module"). Produces the skeleton only; business logic comes from the phase.
---

# Scaffold a new domain module

Follow `CLAUDE.md §2` (structure) and ADR 0002. Replace `<Module>` with the StudlyCase name (e.g. `LeadSourcing`) and `<module>` with the kebab/snake doc name (e.g. `lead-sourcing`).

## 1. Create the directory skeleton

From the project root (Git Bash):

```bash
mkdir -p app/Modules/<Module>/{Actions,Services,Contracts,Data,Enums,Events,Listeners,Jobs,Models,Policies,Concerns,Support,Exceptions,Providers,Http/Controllers/Web,Http/Controllers/Api/V1,Http/Requests,Http/Resources,Http/Middleware,routes}
mkdir -p tests/Feature/<Module> tests/Unit/<Module>
```

Omit sub-folders the module genuinely won't use — don't create empty noise, but keep the standard shape.

## 2. Service provider

Create `app/Modules/<Module>/Providers/<Module>ServiceProvider.php` extending the base `App\Modules\ModuleServiceProvider` (which auto-registers `routes/web.php` and `routes/api.php` under `/api/v1`, plus any `$policies`). Register bindings in `register()`, policies via the `$policies` array.

```php
<?php

declare(strict_types=1);

namespace App\Modules\<Module>\Providers;

use App\Modules\ModuleServiceProvider;

final class <Module>ServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
```

## 3. Register it

Add `App\Modules\<Module>\Providers\<Module>ServiceProvider::class` to `bootstrap/providers.php` under the "Domain modules" comment.

## 4. Routes

Create `routes/web.php` and/or `routes/api.php` inside the module only if it exposes endpoints. Web → Inertia controllers (`Http/Controllers/Web`); API → `Http/Controllers/Api/V1`. Both call the **same Actions**.

## 5. Conventions (enforced)

- `declare(strict_types=1)` in every file; typed properties/returns.
- Tenant-owned models `use App\Modules\Core\Concerns\BelongsToTeam` and have `team_id`.
- Actions single-purpose + invokable; Services orchestrate/integrate; DTOs (spatie/laravel-data) in/out.
- Writes in `DB::transaction()`; external calls/jobs/events `afterCommit`.
- Enums for every status/type. Domain exceptions extend `App\Modules\Core\Exceptions\DomainException`.
- Migrations in `database/migrations` prefixed by module; factories/seeders standard dirs.

## 6. Docs

If the module is new to the project, create `docs/modules/<module>.md` from `docs/modules/_TEMPLATE.md`, add it to `docs/architecture.md` (table + diagram) and `CLAUDE.md §5` if it introduces a decision. Otherwise update the existing module doc.

## 7. Verify

Run the `quality-gates` skill. A module isn't done until `composer analyse` and `composer test` pass and docs are updated.
