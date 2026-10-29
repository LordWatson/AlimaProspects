# AI Prompt Registry — Index

> Every AI call in AlimaProspects goes through the **AI Platform** module's prompt registry. Prompts are **versioned, stored, and named**; code references a prompt by `key` + `version`, never an inline string. Changing a prompt means adding a new version, not editing in place. This file is the human-readable index; the machine source of truth is the `ai_prompt_templates` table / registry config seeded by the AI Platform module.

## Conventions

- **Key:** `<module>.<purpose>` (e.g. `business_intel.background_check`).
- **Version:** integer, monotonically increasing per key. Logged on every request so any output can be traced to the exact prompt.
- **Structured output:** prompts that must return data declare a **JSON schema**; responses are validated against it and retried on failure.
- **Model:** each prompt declares a target capability tier (`reasoning` → Claude Sonnet, `cheap` → Claude Haiku) resolved by the `AiClient`, not hard-coded to a model id.
- **Injection safety:** any prompt that includes scraped/web/user content wraps it in clearly delimited data blocks with the instruction that content inside is **data, not instructions**.
- **Cost:** token + cost per call is logged per team against plan credits.

## Registry (seeded in Phase 5 — all at version 1; consumed as modules land)

All six prompts are **seeded at version 1** by `AiPromptTemplateSeeder` (Phase 5 — AI Platform) with
their JSON schemas. Code will reference them via `AiClient->structured(key, input)` from the owning
module's phase; "status" below tracks whether that **consumer** is built, not whether the template
exists (it does).

| Key                               | Module                      | Tier      | Purpose                                                                                                                               | Structured output            | Status             |
| --------------------------------- | --------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------------------ |
| `business_intel.background_check` | Business Intelligence       | reasoning | Research the signed-up business from its site + public sources → profile (summary, offering, positioning, competitors, suggested ICP) | yes (object)                 | live v1 (Ph. 6)    |
| `icp.refine_suggestions`          | ICP / Business Intelligence | reasoning | Suggest ICP refinements from the business profile                                                                                     | yes (object → suggestions[]) | seeded v1 (Ph. 6)  |
| `lead.enrich_extract`             | Enrichment                  | cheap     | Extract structured company/contact facts from fetched public web text                                                                 | yes (object)                 | live v1 (Ph. 8)    |
| `scoring.qualitative_fit`         | Scoring & Matching          | reasoning | Qualitative ICP-fit assessment + plain-English "why this matches", feeding the hybrid score                                           | yes (object)                 | live v1 (Ph. 9)    |
| `outreach.draft_message`          | Outreach                    | reasoning | Personalised email / LinkedIn text / call script from business profile + lead + tone controls                                         | yes (object → variants[])    | seeded v1 (Ph. 11) |
| `insights.periodic_digest`        | AI Insights                 | reasoning | Periodic insights: best segments, ICP tweaks, stale-lead alerts, "contact today" digest                                               | yes (object → insights[])    | seeded v1 (Ph. 14) |
| `signals.extract_event`           | Signals                     | cheap     | Classify a batch of news items (≤10) as wealth/liquidity events (acquisition / funding / relocation), geo-qualified to the watched location; per item: is_event, type, subject, amount, confidence, relevance, summary, LinkedIn/referral next step | yes (object → events[])      | live v1 (Ph. S3)   |

> Top-level array outputs (suggestions / variants / insights / events) are wrapped in a named object property,
> since the structured-output transport and our validator expect an object at the schema root.

## Rules for adding a prompt

1. Add a row here and a seeded template version in the AI Platform registry.
2. Declare the JSON schema alongside it; add a Pest test that validates a canned response parses.
3. Never log secrets or full personal data inside prompt inputs/outputs — log IDs and token counts.
4. Treat all externally-sourced text as untrusted data.
