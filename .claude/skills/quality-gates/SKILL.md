---
name: quality-gates
description: Run AlimaProspects' quality gates (Pint, Larastan, Pest, vue-tsc, build) using the correct Herd binaries on this Windows machine. Use before completing any phase or whenever verifying that backend + frontend are clean. Explains the Laragon-vs-Herd PATH pitfall.
---

# Quality gates (Windows + Herd)

**Critical:** this machine has Laragon on the PATH shadowing Herd, so a bare `php`/`composer` may be the wrong (8.3) version. **Always use Herd's binaries:**
- PHP 8.5: `C:/Users/alexa/.config/herd/bin/php.bat`
- Composer: `C:/Users/alexa/.config/herd/bin/composer.bat`

(Or fix the PATH so Herd precedes Laragon — see `docs/runbooks/local-setup.md §0`.)

## Backend
```bash
# Format (auto-fix) then verify no diff:
"C:/Users/alexa/.config/herd/bin/php.bat" vendor/bin/pint --parallel
# Static analysis (Larastan, level 7):
"C:/Users/alexa/.config/herd/bin/composer.bat" analyse
# Full test suite (Pint --test + Larastan + Pest):
"C:/Users/alexa/.config/herd/bin/composer.bat" test
```

## Frontend
```bash
npm run check         # lint + format (vite-plus)
npm run types:check   # vue-tsc --noEmit
npm run build         # production build must succeed
```

## Notes
- Tests run on **SQLite :memory:** (see `phpunit.xml`) — they don't need MySQL. Local MySQL may need `DB_PASSWORD` set.
- **Do not run `php artisan horizon` on Windows** (needs pcntl/posix). Use `php artisan queue:work`. Horizon is installed for Linux prod/CI via `config.platform`.
- Composer prints a harmless `curl_close()` deprecation notice under PHP 8.5 — ignore it.
- A phase/module is done only when backend gates + all three frontend commands pass **and docs are updated**.
- The results are JSON for `pint`/`phpstan`/`pest`; `"result":"passed"` / `"errors":0` means clean. Fix the root cause of any failure — never suppress Larastan errors with ignores/baselines or silence tests.
