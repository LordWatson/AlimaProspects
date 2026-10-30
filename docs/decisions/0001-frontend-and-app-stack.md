# ADR 0001 — Frontend & application stack

- **Status:** Accepted
- **Date:** 2026-10-28
- **Deciders:** Lead engineer (bootstrap session)

## Context

AlimaProspects must ship on web and as a mobile app, sharing as much code and logic as possible, with a fast server-driven web experience and a clean API for mobile.

## Decision

- **Web:** Laravel 13 + **Inertia.js v2 + Vue 3** (Composition API, `<script setup>`) + **TypeScript** + **Tailwind CSS v4**, via the official Laravel **Vue starter kit**.
- **Mobile:** **Capacitor + Ionic Vue + TypeScript** in `/mobile`, consuming a versioned REST API (`/api/v1`) with **Sanctum** token auth.
- **Shared code:** `resources/js/shared/` (types, API client, validation, composables, brand tokens) imported by both web and mobile via a path alias.
- **One source of business logic:** both Inertia (web) and API (mobile) controllers call the **same Actions**.

## Consequences

- No separate SPA API to maintain for the web app; mobile gets a proper versioned API.
- Vue/TS/Tailwind skills, components and composables are reused across web + mobile.
- The starter kit pins the frontend toolchain (see ADR 0003) and auth scaffolding (Fortify, see ADR 0004).
