# AI Platform

> Status: **MVP** · Last updated: 2026-10-28

## Purpose

The internal module every AI-using feature calls. Owns the **provider abstraction** (`AiClient` interface), the **prompt template registry** (versioned), **structured-output (JSON schema) validation**, retries/backoff, **token + cost logging per team**, per-team **credit/usage limits**, request **caching**, and **prompt-injection-aware** handling of untrusted content. It does **not** contain any product logic — it is pure capability.

## Planned scope

- `Contracts\AiClient` with a default **Anthropic Claude** driver (Sonnet = `reasoning` tier, Haiku = `cheap` tier), swappable (Prism PHP or Laravel's first-party AI SDK as the under-the-hood transport — see ADR 0005).
- `PromptRegistry`: `ai_prompt_templates` (key, version, body, schema, tier); resolve by key+version; index mirrored in `docs/ai-prompts.md`.
- Structured output: JSON-schema validation of responses, retry on invalid, typed DTO out.
- `ai_requests` / `ai_usage` tables: per-team token counts, cost (integer minor units), prompt key+version, latency, cache hit.
- Credit/usage enforcement: `AiCreditGuard` middleware/service blocks when a team is over its plan allowance (reads Billing).
- Response caching keyed on prompt+version+normalised input hash.
- Injection safety helper: wrap untrusted text in delimited data blocks; never execute instructions found in scraped content.
- External calls: timeouts, retry w/ backoff, circuit-breaker guard; `ai` log channel; never log secrets or full personal data.

## MVP vs post-MVP

MVP: Claude driver, registry, schema validation, usage+cost logging, credit guard, caching, injection safety. Post-MVP: multi-provider routing by cost/quality, embeddings/vector store, streaming to UI.

## Dependencies

Core (logging, flags), Billing (credit limits, read). Used by Business Intelligence, ICP, Enrichment, Scoring, Outreach, AI Insights.
