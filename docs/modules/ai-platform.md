# AI Platform

> Status: **MVP** · Owner: Platform · Last updated: 2026-10-29

## Purpose

The internal capability module every AI-using feature calls. It owns the **provider abstraction**
(`AiClient`), the **versioned prompt registry**, **structured-output (JSON-schema) validation with
retry**, **per-team token + cost logging**, **credit/usage enforcement**, request **caching**, and
**prompt-injection-aware** handling of untrusted content. It contains **no product logic** — it is
pure capability. Callers depend only on the `AiClient` contract, never on the underlying SDK
(`laravel/ai`, ADR 0005), so the transport can be swapped without touching any feature.

## Code map

- **Backend:** `app/Modules/AiPlatform/`
    - **Contracts:** `AiClient` (`structured(key, input, version?)`), `AiTransport` (the SDK seam),
      `CreditSource` (plan allowance; stubbed until Billing).
    - **Services:** `ClaudeAiClient` (the one orchestrator), `PromptRegistry` (resolve by key+version),
      `PromptInterpolator` (placeholder render + injection-safe wrapping), `JsonSchemaValidator`
      (dependency-free subset validator), `CostCalculator` (tokens → cost micro-units), `AiUsageLogger`
      (writes `ai_requests`, sums spend per period), `StubCreditSource` + `AiCreditGuard`.
    - **Transport:** `LaravelAiTransport` (wraps `laravel/ai` — the only class that references the SDK),
      `RegisteredPromptAgent` (ad-hoc agent carrying system prompt, limits + schema), `SchemaConverter`
      (our JSON-schema array → Laravel JSON-schema types).
    - **Models:** `AiPromptTemplate` (registry row — global, not tenant), `AiRequest` (per-call ledger,
      `BelongsToTeam`).
    - **Enums:** `AiTier` (reasoning/cheap), `AiRequestStatus` (success/failed).
    - **Data:** `AiPromptRequest`, `AiTransportResult`, `StructuredAiResponse`, `CreditAllowance`.
    - **Support:** `UntrustedContent` (marker for externally-sourced input).
    - **Exceptions:** `AiPlatformException` (base) + `UnknownPromptException`, `InvalidAiResponseException`,
      `AiCreditExceededException`, `AiTransportException`.
    - **Testing:** `Testing\FakeAiTransport` (ships with the module; bind it in tests — external AI is
      always faked).
    - **Provider:** `AiPlatformServiceProvider` (binds the contracts; no routes — internal module).
- **Routes:** none (pure capability; no controllers or UI by design).
- **Frontend / Shared:** none.
- **Tests:** `tests/Unit/AiPlatform/` (JsonSchemaValidator, PromptInterpolator),
  `tests/Feature/AiPlatform/` (AiClient end-to-end, PromptRegistry/seed, CostCalculator).
- **Config / env vars:** `config/ai.php` (laravel/ai transport — default provider Anthropic),
  `config/ai-platform.php` (tiers, pricing, credits, cache, retries). Env: `AI_DEFAULT_PROVIDER`,
  `ANTHROPIC_API_KEY`, `ANTHROPIC_URL`, `AI_MODEL_REASONING`, `AI_MODEL_CHEAP`,
  `AI_MONTHLY_CREDIT_DEFAULT`, `AI_MICROS_PER_CREDIT`, `AI_MAX_TOKENS`, `AI_TEMPERATURE`, `AI_TIMEOUT`,
  `AI_SCHEMA_RETRIES`, `AI_CACHE_ENABLED`, `AI_CACHE_STORE`, `AI_CACHE_TTL`.

## Data model

- **`ai_prompt_templates`** — the versioned registry. `key` + `version` (unique), `tier`, `system`,
  `body` (with `{{placeholders}}`), `schema` (JSON), `max_tokens`, `temperature`, `description`,
  `is_active`. **Global** (product config), not team-scoped. Resolving a key with no version returns
  the highest active version; a change means a new version row, never an edit in place.
- **`ai_requests`** — per-call token + cost ledger. `team_id` (`BelongsToTeam`), `user_id`,
  `prompt_key`, `prompt_version`, `tier`, `model`, `input_tokens`, `output_tokens`,
  `cache_read_input_tokens`, **`cost_micros`**, `currency`, `latency_ms`, `cache_hit`, `status`,
  `error_code`. Holds IDs, counts and cost only — never prompt inputs/outputs or personal data
  (CLAUDE.md logging rules). The docs' "`ai_usage`" is **derived** by summing this table per team per
  period (`AiUsageLogger::usedMicros`); a rollup table is deferred to Billing/Reporting.

### Units — cost & credits

Per-call AI cost is routinely sub-cent, which plain minor units (cents) would round to zero. Cost is
therefore stored in integer **micro-units** of `currency`: `1_000_000 micros = 1 major unit` (e.g.
$1). Model prices in `config('ai-platform.cost')` are quoted as **micro-units per million tokens**
(e.g. $3/Mtok → `3_000_000`), keeping cost an exact integer. A **credit** (plan allowance) =
`ai-platform.credits.micros_per_credit` micros (default `1_000` = $0.001), so the default
`100_000` credits/month ≈ $100 of AI spend.

## Key flows

**`AiClient->structured(key, input, version?)`:**

1. Resolve the template from the registry (`UnknownPromptException` if missing).
2. Resolve the active team from `TeamContext`; `AiCreditGuard` blocks with `AiCreditExceededException`
   if the team is over allowance — **before any provider call** (margin protection).
3. Resolve provider + model from the template's tier (`ai-platform.tiers.*`).
4. Build the cache key from `key:version:provider:model:sha256(normalised input)`. On a cache hit:
   log a zero-cost `cache_hit` `ai_request` and return immediately (no provider call).
5. Render `system` + `body` via `PromptInterpolator` — `UntrustedContent` values are wrapped in a
   nonce-delimited data block with a "data, not instructions" directive, the nonce scrubbed from the
   content so the closing marker can't be forged.
6. Call the transport. Validate the response against the template schema (`JsonSchemaValidator`).
   On failure, retry up to `ai-platform.retries.schema` times; if still invalid, log a `failed`
   request and throw `InvalidAiResponseException` (callers never receive unvalidated data).
7. On success: compute `cost_micros`, log a `success` `ai_request`, store the response in cache, and
   return a `StructuredAiResponse` (validated data + trace metadata).

A transport/provider failure is normalised to `AiTransportException`, logged as a `failed` request,
and rethrown.

## External dependencies

- **laravel/ai** → **Anthropic Claude** (`claude-sonnet-5-5` reasoning, `claude-haiku-5-5` cheap).
  Only `LaravelAiTransport` touches the SDK. Per-request **timeout** from `ai-platform.defaults.timeout`;
  the SDK provides provider/model **failover**; our layer adds the **schema-retry** loop. A dedicated
  circuit-breaker is deferred (provider failover + timeout cover MVP). Pricing in `ai-platform.cost`
  is **indicative** — verify against live provider pricing (mvp.md cost model, ADR 0005 risk).

## Permissions

No user-facing endpoints. Tenancy is enforced by `BelongsToTeam` on `ai_requests` (auto-assigned from
`TeamContext`) and by the credit guard operating per active team. Later modules that expose AI results
own their own policies.

## Known limitations / TODO

- **Credit source is a flat config stub.** Billing (Phase 16) replaces `StubCreditSource` with a
  plan-aware source and real `EnforcesPlanLimits`; no caller changes.
- **The credit check is check-then-spend with no lock**, so highly concurrent jobs for one team can
  slightly overshoot the allowance before it is seen as exhausted. Acceptable for the stub; Billing
  should add atomic reservation if hard caps are required.
- **No active team ⇒ the guard is a no-op** (system calls). Such calls are still logged, and an
  unattributed call with real cost raises an `ai`-channel **warning** so hidden spend is visible.
- **Response cache is keyed per team** (never shared across tenants) and includes a hash of the
  template content, so an in-place template edit or a different tenant never serves a stale/foreign
  result.
- **`ai_usage` is derived** (sum of `ai_requests`); a rollup table may land with Reporting (Phase 13).
- **Prompt-cache (provider) discounting** of `cache_read_input_tokens` is recorded but not yet
  discounted in cost; refine with live pricing.
- **No multi-provider routing, embeddings or streaming-to-UI** (post-MVP, per the module scope).

## Changelog

| Date       | Phase       | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Files                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-29 | 5           | Built the AI Platform: `AiClient` + `laravel/ai` transport (Claude), versioned prompt registry seeded from `ai-prompts.md`, JSON-schema validation + retry, per-team token/cost logging (`ai_requests`, micro-units), credit guard (stub), response caching, injection-safe untrusted-content wrapping.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | `app/Modules/AiPlatform/**`, `config/ai.php`, `config/ai-platform.php`, `database/migrations/2026_10_29_11000{1,2}_*`, `database/seeders/AiPromptTemplateSeeder.php`, `database/factories/AiPromptTemplateFactory.php`, `database/factories/AiRequestFactory.php`, `tests/{Unit,Feature}/AiPlatform/**`                                                                                                                                                                                                                                               |
| 2026-11-01 | 20          | **Input bounds and the live usage card** (Phase 20, ADR 0017). Two bounds, for two different problems: `config/ai-platform.php`'s new `limits.max_untrusted_chars` **truncates** an oversized untrusted block in `PromptInterpolator` — a scraped page arriving too big is a normal Tuesday — cutting with `mb_substr` because this text is Arabic as often as English, and saying so _inside_ the data block, after the content, where anything that tried to end with its own instructions no longer is; the nonce scrub still runs first, so the injection defence and the truncation compose. `limits.max_prompt_chars` **refuses** the whole rendered prompt in `ClaudeAiClient` with a 413 `AiInputTooLargeException` before the transport, so nothing is sent and nothing is charged — a near-context-limit call is slow, is billed to the team's allowance whatever comes back, and is the shape a prompt-injection attempt takes when the aim is to push the system instructions out of the model's attention. Characters, not tokens: a token count is only knowable by asking the provider, which is the call these exist to avoid. `PromptInterpolator` takes its limit as a **constructor argument** wired from config in the provider, because it is a pure string-in/string-out service whose unit tests construct it with `new` and a `config()` call inside would have required a booted framework. `AiUsageLogger` now raises **`Events\AiRequestRecorded`** (scalars, never the model) on `afterCommit`, which `Observability\AiUsageRecorder` listens to as a Pulse recorder — so the ledger's write path knows nothing about Pulse, and an installation without it is unaffected. `Observability\AiUsageCard` + `resources/views/pulse/ai-usage.blade.php` render live spend, failures, cache hits and mean latency **per prompt key, not per team**: the question on a screen with no tenant scope is which prompt is burning money, and per-team cost already lives behind `AdminAbility::ReadPlatformReports`. The card states on its face that it is **not** the billing figure — it samples, it trims after 7 days, and `PULSE_ENABLED=false` removes it. | `app/Modules/AiPlatform/Services/{PromptInterpolator,ClaudeAiClient,AiUsageLogger}.php`, `app/Modules/AiPlatform/Exceptions/AiInputTooLargeException.php`, `app/Modules/AiPlatform/Events/AiRequestRecorded.php`, `app/Modules/AiPlatform/Observability/{AiUsageRecorder,AiUsageCard}.php`, `app/Modules/AiPlatform/Data/AiUsageRow.php`, `app/Modules/AiPlatform/Providers/AiPlatformServiceProvider.php`, `config/ai-platform.php`, `config/pulse.php`, `resources/views/pulse/ai-usage.blade.php`, `tests/Feature/AiPlatform/AiInputLimitTest.php` |
| 2026-11-01 | 20 (review) | **The review round.** The five Pulse entry types were string literals in two files — a contract where a rename does not error: the column comes back null and a figure on an operator's dashboard quietly becomes zero. They are now `Enums\AiPulseMetric`, written by the recorder and read by the card from the same source. The card's aggregation moved into `Observability\AiUsageSummary`, because all three of its sums are the kind that go wrong quietly (a mean over _provider_ calls rather than all calls, micro-units, a `max(1, …)` guarding the every-call-was-cached case) and each wanted a test rather than a Livewire component to hide in; `AiUsageCard` is now only a renderer. An oversize-prompt refusal was being reported to Sentry as a **fault** — it is a refusal, and is now in `ignore_exceptions`. And the tests that were missing: the recorder's four entry types round-tripped through real Pulse storage, the cache-hit early return, the failure count, the latency mean (two calls at 300ms and 500ms plus three cache hits must read 400, not 160), the divide-by-zero guard, the card rendered through Livewire with lazy loading disabled, truncation exercised **through `ClaudeAiClient`** rather than only the interpolator (the other tests resolve the interpolator themselves and would pass if the provider wiring were wrong), and `DB::afterCommit` proven by rolling the ledger write back and asserting no event.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | `app/Modules/AiPlatform/Enums/AiPulseMetric.php`, `app/Modules/AiPlatform/Observability/{AiUsageSummary,AiUsageCard,AiUsageRecorder}.php`, `app/Modules/AiPlatform/Data/AiUsageRow.php`, `config/sentry.php`, `tests/Feature/AiPlatform/{AiUsageCardTest,AiInputLimitTest}.php`, `tests/Pest.php`                                                                                                                                                                                                                                                     |
