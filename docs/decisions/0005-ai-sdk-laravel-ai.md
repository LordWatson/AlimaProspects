# ADR 0005 — AI transport: Laravel's first-party AI SDK (`laravel/ai`)

- **Status:** Accepted — **installed in Phase 5** (`laravel/ai` ^1.3, wrapped behind `Contracts\AiClient`)
- **Date:** 2026-10-28 (installed 2026-10-29)

## Context

The spec: _"Use Laravel's first-party AI SDK if one ships with / is compatible with the installed Laravel 13; otherwise use Prism PHP. Either way, wrap it behind our own interface so providers/models can be swapped."_

Packagist check (2026-10-28):

- **`laravel/ai` v1.3.0** — requires PHP `^8.3`, `illuminate/* ^12.0|^13.0`. **Compatible with Laravel 13.** ✅ (first-party)
- `prism-php/prism` v0.102.0 — requires PHP `^8.2`, `illuminate/* ^12.61|^13.12`. Also L13-compatible (fallback).

## Decision

Use **`laravel/ai`** as the under-the-hood AI transport, **wrapped behind our own `Contracts\AiClient` interface** in the AI Platform module. Default provider **Anthropic Claude** (Sonnet = `reasoning` tier, Haiku = `cheap` tier). Our interface — not `laravel/ai` types — is what product modules depend on, so the transport can be swapped to Prism or a raw SDK without touching callers.

Install is deferred to the **AI Platform phase** (it is not part of the foundation package set).

## Consequences

- First-party package → best alignment with Laravel 13, and pairs naturally with `laravel/boost` + `laravel/mcp` (both L13-compatible).
- The wrapper adds prompt-registry, JSON-schema validation, cost/credit logging and injection safety on top (see `docs/modules/ai-platform.md`).
- If `laravel/ai` proves limiting, swap to Prism behind the same interface (no caller changes) — record a follow-up ADR.
