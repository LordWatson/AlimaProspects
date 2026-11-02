# Runbook — Deployment (Hetzner)

> Placeholder — filled in during the **Deployment phase** of `mvp.md`. Target: Hetzner Ubuntu LTS.

## Target topology

- **Nginx** → **PHP-FPM 8.5** (Laravel), TLS via **Let's Encrypt**.
- **MySQL 8** (utf8mb4), **Redis**.
- **Supervisor** runs **Horizon** (queues) and **Reverb** (WebSockets) as long-running processes (both need `ext-pcntl`/`ext-posix`, present on Linux).
- Scheduled **backups** (DB + storage) and the Laravel scheduler (`php artisan schedule:run` via cron).

## To be documented at the deploy phase

- Provisioning (user, firewall, packages, PHP extensions incl. pcntl/posix/redis).
- Deploy flow (zero-downtime, `composer install --no-dev`, `migrate --force`, `config/route/view:cache`, `npm ci && npm run build`, Horizon/Reverb restart).
- Supervisor configs for Horizon + Reverb; Nginx vhost; SSL renewal.
- `.env` for production (queue=redis, cache=redis, broadcast=reverb, session=redis/database).
- Backup schedule + restore test; log shipping.
- Secrets management; CI/CD (GitHub Actions deploy job).

## Already decided, waiting for this phase (Phase 20, ADR 0017)

Four things exist and need wiring into the deploy rather than designing:

- **`php artisan security:posture --strict` is a deploy step**, and a non-zero exit should stop the
  release. It asserts the settings that are correct in `.env.example` and silently wrong on a box
  somebody configured in a hurry — debug mode, the session cookie, the headers, admin 2FA, the
  Sentry DSN. `docs/runbooks/observability.md` §5 has the table of what each row means.
- **`SENTRY_RELEASE` must be set to the deployed commit** by the pipeline. Without it every error in
  Sentry belongs to "no release", and _"did this start with yesterday's deploy?"_ has no answer.
- **`TrustProxies` has to be configured** for the chosen Nginx/TLS topology, or the app will not see
  requests as secure — which silently drops HSTS, and makes `AdminIpAllowlist` and the rate limiters
  key on the proxy's address rather than the caller's.
- **Pulse should ingest through Redis** in production (`PULSE_INGEST_DRIVER=redis` plus a
  `pulse:work` process under Supervisor, beside Horizon and Reverb), so recording does not sit on
  the request. The database driver is fine locally and will not stay fine. `pulse:trim` keeps the
  tables bounded; `PULSE_STORAGE_KEEP` defaults to 7 days.
