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
- Backup schedule + restore test; log shipping; Sentry + Pulse in prod.
- Secrets management; CI/CD (GitHub Actions deploy job).
