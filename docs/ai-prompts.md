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

The prompts are seeded by `AiPromptTemplateSeeder` (Phase 5 — AI Platform) with their JSON schemas.
Code references them via `AiClient->structured(key, input)` from the owning module's phase; "status"
below tracks whether that **consumer** is built, not whether the template exists (it does). Two keys
now have a version 2 — `outreach.draft_message` (Phase 11) and `business_intel.background_check`
(Phase 7c) — and in both cases v1 stays seeded and active at a lower version number, so a row that
recorded `prompt_version: 1` can still be traced back to what produced it.

**A note on naming schema fields.** `business_intel.background_check` v1 carried a single
`suggested_industries` list with no description, and nothing in the prompt said whether it meant the
industry the business _is in_ or the industries its _customers_ are in. The model read it as the
former (its framing allowed nothing else) while the ICP seeder read it as the latter, so a graphic
design studio was given an ICP whose industry signal was "graphic design" — and discovery went and
found its competitors. Describe every field in a schema, and never let one field carry two readings;
a field a reader has to guess at is a bug waiting for a consumer.

| Key                               | Module                      | Tier      | Purpose                                                                                                                                                                                                                                                     | Structured output            | Status             |
| --------------------------------- | --------------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------------------ |
| `business_intel.background_check` | Business Intelligence       | reasoning | Research the signed-up business from its site + public sources → profile. **v2 splits the seller from the buyer:** `own_industry` + `competitors` describe the team, `customer_industries` + `target_customers` + `decision_maker_titles` describe who buys | yes (object)                 | live v2 (Ph. 7c)   |
| `icp.refine_suggestions`          | ICP / Business Intelligence | reasoning | Suggest ICP refinements from the business profile                                                                                                                                                                                                           | yes (object → suggestions[]) | seeded v1 (Ph. 6)  |
| `lead_sourcing.shape_query`       | Lead Sourcing               | reasoning | Turn the business profile + ICP into a provider-ready search for the team's **customers**, with negative terms for its own trade and competitors                                                                                                            | yes (object)                 | live v1 (Ph. 7c)   |
| `lead.enrich_extract`             | Enrichment                  | cheap     | Extract structured company/contact facts from fetched public web text                                                                                                                                                                                       | yes (object)                 | live v1 (Ph. 8)    |
| `scoring.qualitative_fit`         | Scoring & Matching          | reasoning | Qualitative ICP-fit assessment + plain-English "why this matches", feeding the hybrid score                                                                                                                                                                 | yes (object)                 | live v1 (Ph. 9)    |
| `outreach.draft_message`          | Outreach                    | reasoning | Personalised email / LinkedIn text / call script from business profile + lead + tone controls                                                                                                                                                               | yes (object → variants[])    | seeded v1 (Ph. 11) |
| `insights.periodic_digest`        | AI Insights                 | reasoning | Periodic insights: best segments, ICP tweaks, stale-lead alerts, "contact today" digest                                                                                                                                                                     | yes (object → insights[])    | seeded v1 (Ph. 14) |
| `signals.extract_event`           | Signals                     | cheap     | Classify a batch of news items (≤10) as wealth/liquidity events (acquisition / funding / relocation), geo-qualified to the watched location; per item: is_event, type, subject, amount, confidence, relevance, summary, LinkedIn/referral next step         | yes (object → events[])      | live v1 (Ph. S3)   |

> Top-level array outputs (suggestions / variants / insights / events) are wrapped in a named object property,
> since the structured-output transport and our validator expect an object at the schema root.

## Rules for adding a prompt

1. Add a row here and a seeded template version in the AI Platform registry.
2. Declare the JSON schema alongside it; add a Pest test that validates a canned response parses.
3. Never log secrets or full personal data inside prompt inputs/outputs — log IDs and token counts.
4. Treat all externally-sourced text as untrusted data.
