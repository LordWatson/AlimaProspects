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
(Phase 7c) — plus `signals.extract_event` (Phase S7), and in every case v1 stays seeded and active at
a lower version number, so a row that recorded `prompt_version: 1` can still be traced back to what
produced it. **Twelve rows for nine keys** as of S9.

**A note on naming schema fields.** `business_intel.background_check` v1 carried a single
`suggested_industries` list with no description, and nothing in the prompt said whether it meant the
industry the business _is in_ or the industries its _customers_ are in. The model read it as the
former (its framing allowed nothing else) while the ICP seeder read it as the latter, so a graphic
design studio was given an ICP whose industry signal was "graphic design" — and discovery went and
found its competitors. Describe every field in a schema, and never let one field carry two readings;
a field a reader has to guess at is a bug waiting for a consumer.

| Key                               | Module                      | Tier      | Purpose                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Structured output            | Status                                    |
| --------------------------------- | --------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------- | ----------------------------------------- |
| `business_intel.background_check` | Business Intelligence       | reasoning | Research the signed-up business from its site + public sources → profile. **v2 splits the seller from the buyer:** `own_industry` + `competitors` describe the team, `customer_industries` + `target_customers` + `decision_maker_titles` describe who buys                                                                                                                                                                                                                                                                                | yes (object)                 | live v2 (Ph. 7c)                          |
| `icp.refine_suggestions`          | ICP / Business Intelligence | reasoning | Suggest ICP refinements from the business profile                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | yes (object → suggestions[]) | seeded v1 (Ph. 6)                         |
| `icp.compose_profile`             | ICP                         | reasoning | Draft a **whole ideal customer profile** from a member's sentence about who they sell to: name, description and 5–10 weighted criteria plus 1–3 exclusions — or a refusal with a reason. The brief is wrapped as untrusted content, and the model is given the team's own business profile so it can tell the seller from the buyer                                                                                                                                                                                                        | yes (object)                 | live v1                                   |
| `lead_sourcing.shape_query`       | Lead Sourcing               | reasoning | Turn the business profile + ICP into a provider-ready search for the team's **customers**, with negative terms for its own trade and competitors                                                                                                                                                                                                                                                                                                                                                                                           | yes (object)                 | live v1 (Ph. 7c)                          |
| `lead_sourcing.ai_discovery`      | Lead Sourcing               | reasoning | **Name the companies themselves.** Given the shaped brief, return up to N real companies with a trade, an approximate headcount band, a place, an ISO-2 country, a homepage and one line of why — company facts only, never a person. The one prompt whose output becomes **rows a team acts on** rather than a judgement about rows that exist, so a fabricated name is a fabricated prospect: the brief is wrapped untrusted, the source is ranked below every index, and nothing it asserts about a company is believed over a register | yes (object → companies[])   | live v1 (Ph. 7c)                          |
| `lead.enrich_extract`             | Enrichment                  | cheap     | Extract structured company/contact facts from fetched public web text                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | yes (object)                 | live v1 (Ph. 8)                           |
| `scoring.qualitative_fit`         | Scoring & Matching          | reasoning | Qualitative ICP-fit assessment + plain-English "why this matches", feeding the hybrid score                                                                                                                                                                                                                                                                                                                                                                                                                                                | yes (object)                 | live v1 (Ph. 9)                           |
| `outreach.draft_message`          | Outreach                    | reasoning | Personalised email / LinkedIn text / call script from business profile + lead + tone controls                                                                                                                                                                                                                                                                                                                                                                                                                                              | yes (object → variants[])    | seeded v1 (Ph. 11)                        |
| `insights.periodic_digest`        | AI Insights                 | reasoning | The model's read of one team's last seven days: at most three grounded observations (topic, title, body, severity) over **figures only** — no company or person names in the input, so nothing in it is untrusted                                                                                                                                                                                                                                                                                                                          | yes (object → insights[])    | live v1 (Ph. 14)                          |
| `signals.compose_definition`      | Signals                     | reasoning | Draft a **signal definition** from a member’s sentence about a buying moment: name, description, category, icon, 3–6 plain-keyword templates, include/exclude rules, suggested thresholds, match keywords, approach hint — or a refusal with a reason. The brief is wrapped as untrusted content                                                                                                                                                                                                                                           | yes (object)                 | live v1 (Ph. S9)                          |
| `signals.extract_event`           | Signals                     | cheap     | Classify a batch of news items (≤10) against **the team's own signal definitions** — their keys, names and include/exclude rules are injected into the body — geo-qualified to the watched location; per item: is_event, type key, subject, amount, confidence, relevance, summary, LinkedIn/referral next step                                                                                                                                                                                                                            | yes (object → events[])      | live **v2** (Ph. S7); v1 retired (Ph. S3) |

> Top-level array outputs (suggestions / variants / insights / events / companies) are wrapped in a named object property,
> since the structured-output transport and our validator expect an object at the schema root.

### `signals.extract_event` v2 — the one place the schema stopped being the guard

v1 constrained `event_type` to a JSON-schema `enum` of the three `SignalEventType` cases, so a
hijacked or hallucinated response **could not** name a type outside the set: the schema refused it
before our code saw it. S7 made signal types data a team owns (ADR 0018), and a team's keys differ
per watch, so that enum cannot exist.

v2 therefore bounds `event_type` to a 32-character string — the width of `signals.event_key` — and
**`RunSignalWatchAction::resolveDefinition()` rejects any key that was not among the ones offered for
that batch**, dropping the item and logging the key (never the article text). That Action is now the
guard the schema used to be, and it is covered by a test that feeds a hostile key back
(`tests/Feature/Signals/SignalDefinitionRunTest.php`).

The lesson generalises: when a prompt's constraint cannot live in the schema, it has to live in the
code that reads the response. It never lives only in the prompt — a prompt describes a constraint, it
does not enforce one.

### `icp.compose_profile` v1 — the prompt whose job is an inversion

The second prompt here that writes **configuration** rather than prose, and the one with the
narrowest failure mode. A member types one sentence that describes **both** sides — _"we are a design
agency looking for startup food businesses that need branding on their packaging"_ — and a profile
describes only the second. Return the first and the product breaks in the way it has already broken
twice: `business_intel.background_check` v2 exists because a background check's "industry" was being
read as the customer's, and `lead_sourcing.shape_query` exists because a design studio was handed a
list of design studios. So:

1. **The inversion is stated, with its reason, and so is where the seller's own trade goes** — into
   an `exclusion`, which is a real field of a profile and the one place a competitor belongs.
2. **The team's own `BusinessProfile` is in the body**, absent-rather-than-invented when onboarding
   has not run, because telling the two apart needs something to tell apart. A model told nothing
   drafts from the brief alone, which is the right failure; a model told something made up drafts a
   confident profile for the wrong business.
3. **A value has to be the words a buyer is findable by**, because it is: `DiscoveryQuery::fromIcp()`
   searches an industry value more or less verbatim when the shaper is skipped, so "businesses
   needing a rebrand" finds nothing and "craft food producers" finds companies.
4. **Weights are told to spread.** A profile where everything is 90 grades every prospect identically
   and teaches a member that the slider does nothing.
5. **A refusal is a first-class answer.** "Get me the mobile numbers of rich people in Dubai" is not
   an ideal customer profile, and criteria drafted for it would grade the whole account against
   nonsense.

**And the constraint that cannot live in the schema lives in the code**, exactly as below:
`DraftIcp::fromAi()` re-validates against `IcpData::rules()` — the rules the editor's own save posts
through — so a draft the save path would reject becomes a refusal the member can act on rather than
a half-filled form. Nothing is saved by the call; the draft is flashed to the editor and the member
presses save.

### `signals.compose_definition` v1 — the prompt that writes configuration

Every other prompt in this registry produces **content**: a profile, a score, a message, a
classification. This one produces **configuration** — a signal definition is a standing instruction
to spend money on searches every day, so a bad draft is not a bad paragraph somebody deletes, it is a
recurring cost that finds nothing. Three consequences, all visible in the seeded template:

1. **Reasoning tier, not cheap.** The cheap tier writes templates that look right and return
   nothing, which is exactly the failure the S0 spike found in seven of eight hand-written ones.
2. **The S0 finding is stated in the system prompt, with its reason.** "Plain keywords, no quoting,
   no `-based`, because the provider is a news search with a one-week window and tight phrases match
   no headline." A model told merely to "write good templates" writes quoted phrases — they read as
   precise. Telling it _why_ is what changes the output.
3. **A refusal is a first-class answer.** `is_viable: false` with a reason, for a brief that is not
   an event a news search could find — "find me rich people in Dubai", a request for contact
   details, anything about a private individual. A composer that drafted plausible templates for
   those would be worse than one that refuses: it would spend a team's money daily on a search that
   cannot work, and the member would conclude the feature does not work.

**A note on "the one untrusted input", corrected by the S9 security review.** The brief is the only
input to these two prompts that comes from **outside** the product, and it is wrapped. It is not the
only customer-written text in them: a definition's `description`, `includes` and `excludes` go into
`signals.extract_event` in a **trusted** slot — here and in a real run since S7 — and they must,
because steering the classifier is their purpose. The residual is tenant-internal and accepted: a
member can iterate their own rule text until the classifier's system prompt comes back to them in a
`summary`. They are extracting our prompt wording, to themselves, with no cross-tenant reach and
nothing persisted. Wrapping first-party configuration as untrusted data would mean telling the model
to ignore the instructions the customer is paying us to follow. ADR 0020 §3 records the same.

**The constraint that cannot live in the schema lives in the code**, as with `extract_event` v2
above. The schema bounds the shape — six templates, 200 characters each, scores in 0–100 — but it
cannot express "this must be a _valid definition_", so `DraftSignalDefinition::fromAi()` re-validates
the draft against `SignalDefinitionData::rules()`, the same rules the save path uses. A draft that
validated against the schema and failed the rules would otherwise reach the review form as a half-
filled mess.

`PreviewSignalDefinitionAction` then calls **`signals.extract_event` v2** — the real classifier, with
the draft as the only offered type — to show what the draft would keep and what it would drop. Using
a different prompt for the preview would mean previewing a search nobody will ever run.

### `lead_sourcing.ai_discovery` v1 — the prompt whose answer becomes rows

Every other prompt here either describes something that already exists (a lead, a team, a week's
figures) or writes configuration a member then approves. This one is asked to **produce the
subjects**: its answer is persisted as `leads`, and a company it invents is a prospect somebody will
try to ring.

So the prompt is built around one instruction repeated three ways — name only companies you are
confident exist, name fewer rather than pad the list, and leave a field out rather than fill it —
and the system around it assumes the instruction will sometimes be ignored:

- `LeadSource::Ai` ranks **0**, below every index, so any other source corrects its fields on a
  merge and Enrichment treats the name it stored as provisional (`EnrichLeadAction`'s
  `PROVISIONAL_NAME_RANK`).
- The claim is kept on the row (`leads.raw.claim`, with the prompt version and model), because a
  lead whose only provenance is "a model said so" has to say which model, and when.
- A country that is not two letters, and a website that is not a host, are **dropped** rather than
  stored: `leads.country` is a two-character column, so "United Kingdom" would have been truncated
  to "UN" and read as a country by the next person to look at the row.
- **No named individual, ever** (ADR 0025): there is no person field in the schema, so there is
  nothing to filter out afterwards.

The two fields that justify the prompt's existence are `industry` and `employee_range`. No
index-backed provider fills them — a register publishes an SIC code and a places listing a category
— so a discovered lead left the ICP's Industry and Company size criteria `Unknown`, out of both
sides of `DeterministicScorer`'s ratio, and was graded on its geography alone. Stating a trade and a
band is what gives both halves of the hybrid score something to judge.

## Rules for adding a prompt

1. Add a row here and a seeded template version in the AI Platform registry.
2. Declare the JSON schema alongside it; add a Pest test that validates a canned response parses.
3. Never log secrets or full personal data inside prompt inputs/outputs — log IDs and token counts.
4. Treat all externally-sourced text as untrusted data.
