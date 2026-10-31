# Mobile App

> Status: **Post-MVP (fast-follow; API v1 is hardened as of Phase 17, so it can start immediately)** · Last updated: 2026-10-31

## Purpose

The iOS + Android app (Capacitor + Ionic Vue + TypeScript) in `/mobile`, consuming the versioned REST API (`/api/v1`) with Sanctum token auth. It is a focused field companion — **not** a full replica of web settings/billing. It owns the mobile shell and screens; all business logic stays in the shared Actions behind the API.

## Planned scope

- `/mobile` Capacitor + Ionic Vue project importing `resources/js/shared/` via a path alias. **Phase 17 put what it needs there**: `shared/types/` (the API payload types, which the web app also reads through `@/types`), `shared/api/` (the error envelope, pagination shapes and a typed `fetch` client that sends the bearer token, pins the version and parses the envelope), and `shared/tokens.ts` (brand).
- Screens: auth (token login + 2FA), dashboard KPIs, leads list/detail, pipeline stage moves, notes, tasks, log a call/email, AI draft generation, push notifications.
- **Push** via FCM (Android) / APNs (iOS) through Capacitor, wired to the Notifications module.
- Offline-tolerant reads where sensible; writes go through `/api/v1`.
- Reuses brand tokens (shared) with RTL (Arabic) support.

## MVP vs post-MVP

Post-MVP for v1 launch, but **API v1 hardening is MVP** so the app is unblocked. The app itself: auth → leads → pipeline → notes/tasks → AI draft → push.

## What Phase 17 left ready

Read `docs/api/README.md` first — the version policy, the scopes, the error envelope, the pagination rule — and `docs/api/openapi.json` for the generated contract.

- **Every screen's endpoint exists**, including the dashboard (`GET reports/dashboard`, which was the gap: Reporting had no API at all). `tests/Feature/Api/V1/MobileJourneyTest.php` walks the whole journey — sign in, dashboard, list, detail, stage move, note, task, channel decisions, AI draft, logged call, push registration, bell, sign out — over **one** token, so the app is building against a path already proven to compose.
- **Ask for the scopes the app needs and no more** at `POST /auth/token`; that journey test lists the eight it takes. A `403 insufficient_token_ability` names what was missing in `error.details.required_abilities`.
- **One error shape** to handle, and `ApiError` in `shared/api/` already classifies the three that mean "do something other than show the message": sign in again, reissue the token with a wider scope, back off for `retryAfter` seconds.
- **2FA token exchange is still the open item** (`423` with a pointer to the web screen) — it is this phase's work, not Phase 17's.

## Dependencies

API v1 (all relevant modules' `Http/Controllers/Api/V1`), shared frontend code, Auth (Sanctum), Notifications (push). Apple/Google developer accounts + FCM/APNs credentials required.
