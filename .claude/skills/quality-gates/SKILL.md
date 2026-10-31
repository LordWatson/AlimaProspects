---
name: quality-gates
description: Run AlimaProspects' quality gates (Pint, Larastan, Pest, vue-tsc, build — plus the mobile app's own set) using the correct Herd binaries on this Windows machine. Use before completing any phase or whenever verifying that backend + frontend are clean. Explains the Laragon-vs-Herd PATH pitfall.
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

## Mobile (`/mobile`, Phase 18)

Its own npm project, so it has its own gates — the root ones do **not** cover it, and the root lint
and format passes deliberately skip `mobile/` (see `vite.config.ts` for why).

```bash
cd mobile
npm run check         # oxlint --deny-warnings + oxfmt --check
npm run types:check   # vue-tsc --noEmit
npm run test          # vitest
npm run build         # must succeed; `npx cap sync` after, if a native build will follow
cd ..
```

- Same rules and same style as the root project, restated in `mobile/.oxlintrc.json` and
  `mobile/.oxfmtrc.json` because this project drives oxlint and oxfmt directly rather than through
  vite-plus. Two code styles in one repository is one too many.
- `npm run check:fix` fixes what it can.
- If the logo artwork changed, `npm run artwork` regenerates the Android vector drawables from it.
- Running the app, the emulator host-address traps and the iOS/macOS limits are in
  `docs/runbooks/mobile-dev.md`, not here.

## Notes

- Tests run on **SQLite :memory:** (see `phpunit.xml`) — they don't need MySQL. Local MySQL may need `DB_PASSWORD` set.
- **Do not run `php artisan horizon` on Windows** (needs pcntl/posix). Use `php artisan queue:work`. Horizon is installed for Linux prod/CI via `config.platform`.
- Composer prints a harmless `curl_close()` deprecation notice under PHP 8.5 — ignore it.
- A phase/module is done only when the backend gates, all three frontend commands **and** (where `/mobile` was touched) the four mobile commands pass — **and docs are updated**.
- The results are JSON for `pint`/`phpstan`/`pest`; `"result":"passed"` / `"errors":0` means clean. Fix the root cause of any failure — never suppress Larastan errors with ignores/baselines or silence tests.
