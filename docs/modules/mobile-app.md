# Mobile App

> Status: **Post-MVP (fast-follow; API v1 is MVP so it can start immediately)** · Last updated: 2026-10-28

## Purpose
The iOS + Android app (Capacitor + Ionic Vue + TypeScript) in `/mobile`, consuming the versioned REST API (`/api/v1`) with Sanctum token auth. It is a focused field companion — **not** a full replica of web settings/billing. It owns the mobile shell and screens; all business logic stays in the shared Actions behind the API.

## Planned scope
- `/mobile` Capacitor + Ionic Vue project importing `resources/js/shared/` (types, API client, validation, composables, brand tokens) via a path alias.
- Screens: auth (token login + 2FA), dashboard KPIs, leads list/detail, pipeline stage moves, notes, tasks, log a call/email, AI draft generation, push notifications.
- **Push** via FCM (Android) / APNs (iOS) through Capacitor, wired to the Notifications module.
- Offline-tolerant reads where sensible; writes go through `/api/v1`.
- Reuses brand tokens (shared) with RTL (Arabic) support.

## MVP vs post-MVP
Post-MVP for v1 launch, but **API v1 hardening is MVP** so the app is unblocked. The app itself: auth → leads → pipeline → notes/tasks → AI draft → push.

## Dependencies
API v1 (all relevant modules' `Http/Controllers/Api/V1`), shared frontend code, Auth (Sanctum), Notifications (push). Apple/Google developer accounts + FCM/APNs credentials required.
