# Runbook — Local setup (Laravel Herd on Windows)

## 0. The PATH gotcha (read first)
This machine has **both Laragon and Herd**, and **Laragon wins on PATH** — a bare `php` is Laragon's **8.3**, not Herd's **8.5**. Either:
- **Recommended:** reorder your PATH so `C:\Users\<you>\.config\herd\bin` comes **before** Laragon's `bin` entries, and (re)open your shell. Verify: `php -v` → 8.5.x.
- Or call Herd's binaries by full path everywhere:
  - PHP: `C:/Users/<you>/.config/herd/bin/php.bat`
  - Composer: `C:/Users/<you>/.config/herd/bin/composer.bat`

## 1. Prerequisites
- **Laravel Herd** (provides PHP 8.5, Nginx, and the `*.test` domain for this folder → `http://alimaprospects.test`).
- **MySQL 8** and **Redis** running locally (via Herd services or Laragon).
- **Node 20+** with a modern npm (the PATH npm may be old 8.x; prefer Herd's nvm Node or `C:\Program Files\nodejs`).

## 2. First-time setup
```bash
# from the project root
composer install              # (Windows: see §0 for the right composer)
cp .env.example .env          # if .env is missing
php artisan key:generate
# create the database (see §3), then:
php artisan migrate
npm install
npm run build
```
`composer setup` bundles most of this.

## 3. Database
`.env` defaults to MySQL `alimaprospects` on `127.0.0.1:3306`, user `root`.
```sql
CREATE DATABASE alimaprospects CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
> ⚠️ **Known issue (2026-10-28):** the local MySQL `root` user rejected an empty password during bootstrap. Set `DB_PASSWORD` in `.env` to your MySQL root password (or create a dedicated `alima` user and use that). The **test suite is unaffected** — it uses SQLite `:memory:` (see `phpunit.xml`).

## 4. Running the app
```bash
composer dev   # server + queue worker + Vite (+ Reverb) together
# or individually:
php artisan serve
php artisan queue:work         # NOT `php artisan horizon` on Windows (see §6)
php artisan reverb:start       # realtime (WebSockets)
npm run dev                    # Vite
```

## 5. Quality gates (run before every commit)
```bash
composer lint        # Pint (fix)
composer analyse     # Larastan (PHPStan) — repo at level 7
composer test        # Pint --test + Larastan + Pest
npm run check        # frontend lint + format
npm run types:check  # vue-tsc
npm run build
```

## 6. Horizon on Windows
**Horizon's supervisor needs `ext-pcntl`/`ext-posix`, which don't exist on Windows.** It is installed for Linux prod/CI (via `config.platform` in `composer.json`), but **do not run `php artisan horizon` locally** — use `php artisan queue:work`. The Horizon **dashboard** (`/horizon`) still renders; only the long-running supervisor is Linux-only.

## 7. Services & keys (later phases)
`.env.example` documents every key. You will need, as phases land: Anthropic API key (AI), Companies House API key, a web-search API key (Serper/Brave/Bing), Google Places key, Stripe keys (Billing), Sentry DSN, Reverb app keys, FCM/APNs creds (mobile push).
