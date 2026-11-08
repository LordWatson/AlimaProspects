# Pre-launch recon (PL0)

> Phase: **PL0** · Written: 2026-11-08 · Plan: `pre-launch.md` · Status: **complete, no application code changed**

The purpose of this document is to stop `pre-launch.md` being built twice. The plan was written
against the product, not against this repository, so a good deal of it already exists under another
name — and a few parts of it contradict decisions this codebase has already taken and tested. This
file is the map between the two, and **every later PL phase reads it before writing code**.

Three things to take from it before anything else:

1. **About a third of the plan is already built.** Suppression, opt-out, DSAR, retention, the
   compliance market gate, CSV export with injection protection, a circuit breaker, an SSRF guard,
   AI cost metering and a usage ledger all exist. PL5 and PL6 in particular are _extensions_, not
   new modules, and building them as written would produce a second suppression table and a second
   export path — which is the way a do-not-contact list comes to be half honoured.
2. **The plan's two central nouns already have owners here.** "Evidence" is what `signals` and
   `lead_scores.breakdown` hold, and "usage ledger" is what `usage_meters` plus `ai_requests` /
   `discovery_runs` / `signal_runs` are. PL1 and PL4 must join those, not replace them.
3. **Three of the plan's cross-cutting conventions lose to the repo** (plan §0 says the repo wins):
   named Horizon queues, a second plan config, and `docs/adr/`. See §6.

---

## 1. Inventory — what exists today

### 1.1 Modules

`app/Modules/<Module>` per ADR 0002. Eighteen modules exist:

`Admin` `AiInsights` `AiPlatform` `Auth` `Billing` `BusinessIntelligence` `Compliance` `Core`
`Enrichment` `ICP` `LeadSourcing` `LeadsWorkspace` `Notifications` `Onboarding` `Outreach`
`Reporting` `Scoring` `Signals`

`docs/modules/integrations.md` exists as a **22-line placeholder** marked "Post-MVP"; there is no
`app/Modules/Integrations`. PL7 creates it (`new-module` skill).

### 1.2 The lead and its neighbours

| Table                                                                                                      | Owner                                                  | Notes relevant to the plan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `leads`                                                                                                    | `LeadSourcing\Models\Lead`                             | Company-level only. `team_id`, `discovery_run_id`, `source` (`LeadSource`), `status`, `enrichment_status`, company facts, `locality/region/postal_code/country`, `address` json, `sic_codes` json, `industry`, `employee_range`, `technologies`, `phone`, `website`, `source_url`, `collected_at`, `raw` json, **`field_sources` json (per-field provenance)**, `has_personal_data`, `readiness` + `readiness_at`, `enrichment_cost_micros`, `anonymised_at`, `pipeline_stage_id`, `dedupe_key` unique per team. |
| `lead_contacts`                                                                                            | `Enrichment\Models\LeadContact`                        | `name`, `title`, `email`, `phone`, `email_verification_status`, `email_confidence`, `is_role_based`, `is_personal`, `is_inferred`, `source`, `source_url`, `method`, `collected_at`. Unique `(lead_id, email)`. **This is the plan's `contacts` table.**                                                                                                                                                                                                                                                         |
| `lead_scores`                                                                                              | `Scoring\Models\LeadScore`                             | `score`, `grade`, `deterministic_score`, `ai_score`, `breakdown` json, `explanation`, `strengths`, `concerns`, `excluded`, `cost_micros`. Unique `(lead_id, icp_id)`. **This is the plan's `fit_score`.**                                                                                                                                                                                                                                                                                                        |
| `lead_activities` `lead_notes` `lead_tasks` `lead_tags` `lead_assignments` `pipeline_stages` `saved_views` | `LeadsWorkspace`                                       | The workspace. `RecordLeadActivityAction` + `LeadActivityType` is the timeline every later phase should write to rather than inventing one.                                                                                                                                                                                                                                                                                                                                                                      |
| `signals` `signal_clusters` `signal_definitions` `signal_watches` `signal_runs` + 10 more                  | `Signals`                                              | `signals.heat`, `signals.score_breakdown` (**stored, never recomputed**), `resolved_market`, `matched_lead_id`, `lead_id`, `dedupe_hash`, `article_fetched_at`.                                                                                                                                                                                                                                                                                                                                                  |
| `outreach_suppressions`                                                                                    | `Outreach\Models\OutreachSuppression`                  | **The do-not-contact list already exists.** `scope` (`global`/`team`) + `scope_key`, `type` (`email`/`domain`/`phone`), `value_hash` (sha-256, unsalted, the only form matched), `value_preview`, `reason` (`opt_out`/`bounce`/`complaint`/`manual`/`erasure`), unique `(scope_key, type, value_hash)`, index `(type, value_hash)`.                                                                                                                                                                              |
| `usage_meters`                                                                                             | `Billing`                                              | A **record over** the ledgers, not a counter: `metric` (`leads`/`ai_credits`/`signal_queries`), `period_start/end` in UTC, `used`, `included`, `reported` (Stripe idempotency).                                                                                                                                                                                                                                                                                                                                  |
| `ai_requests`                                                                                              | `AiPlatform\Models\AiRequest`                          | The authoritative AI spend ledger (`cost_micros`), read directly by the guards.                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `lia_records` `retention_policies`                                                                         | `Compliance`                                           | Legitimate-interest assessments and per-team retention.                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `audit_logs`                                                                                               | `Core\Models\AuditLog` via `Core\Services\AuditLogger` | Already used by export, conversion and suppression changes.                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

### 1.3 Signal → Lead today

`Signals\Actions\ConvertSignalToLeadAction` (read its docblock before PL1). It:
locks the signal row; returns the existing lead on a repeat; keys the new lead exactly as discovery
does (`SourcedLead::dedupeKey()`); runs `LeadDeduplicator` for the harder same-company case; may only
**fill gaps** on a survivor (`LeadSource::Signal` ranks bottom); writes the named person as a
`LeadContact` with `email`/`phone` **null**; re-asserts `raw.outreach_restriction` on the winner
(a compliance limit may be added, never dropped); and queues `EnrichLeadJob` + fires `SignalConverted`
from `afterCommit`. It also writes a `LeadActivity` timeline entry and a note inside the transaction.

**PL1's "signal becomes evidence" hooks in here**, in the same transaction as the timeline entry.

### 1.4 Scoring

`Scoring\Actions\ScoreLeadAction` → `DeterministicScorer` (criteria from the team ICP, via
`CriterionScore` / `CriterionMatch`) plus an optional AI fit pass; output is one `lead_scores` row per
`(lead, icp)` with `breakdown`, `explanation`, `strengths`, `concerns`. Triggered by
`Listeners\ScoreEnrichedLead` on `LeadEnriched`, and re-run team-wide by `RescoreTeamOnIcpChange`.
`RecordScoreFeedbackAction` + `score_feedback` already exist — **PL1's evidence feedback should look
like them, not invent a second feedback vocabulary.**

### 1.5 AI

`AiPlatform\Contracts\AiClient` (`ClaudeAiClient`) over `AiTransport` (`LaravelAiTransport`, faked by
`Testing\FakeAiTransport`). Prompts are **registry rows** (`ai_prompt_templates`, `PromptRegistry`,
`PromptInterpolator`), responses schema-validated (`JsonSchemaValidator`), untrusted input wrapped in
`Support\UntrustedContent`, cost computed by `CostCalculator` into `ai_requests.cost_micros`, and the
allowance enforced by `Services\AiCreditGuard` against `Contracts\CreditSource` (stubbed
permissively; Billing binds the real one). `AiInputTooLargeException` bounds the prompt in characters.

**PL1's why-now summary is a new prompt template row plus an Action calling `AiClient` — nothing more.**

### 1.6 Billing and plan limits

Cashier 16 + `config/billing.php`. `PlanKey` (`free`/`starter`/…), per-plan `seats`, `leads`,
`credits`, `overage`, `capabilities` and a `signals` block. Enforcement is in **guards**, not
middleware (ADR 0012), through four seams Billing decorates:
`AiPlatform\Contracts\CreditSource`, `LeadSourcing\Contracts\LeadQuotaSource`,
`Auth\Contracts\SeatLimitSource`, `Signals\Contracts\SignalQuotaSource`.
Entitlement is `Core\Enums\Capability` behind `Core\Contracts\CapabilityGate` (ADR 0015), decorated
three times (plan → operator override → suspension). Cases today: `Signals`, `SignalComposer`,
`AiInsights`, `BusinessIntelligence`, `Enrichment`, `CsvExport`, `ApiAccess`.
`BillingMetric`: `leads`, `ai_credits`, `signal_queries`. Overage multipliers in `config/billing.php`.

`laravel/pennant` **is installed and the `features` table migrated, but no flag is defined or
resolved anywhere in `app/`.** PL1 is the first use.

### 1.7 Compliance (already substantial)

`RecordOptOutAction`, `EraseSubjectDataAction`, `PersonalDataLocator` (the DSAR search across
modules, by `SubjectDataCategory`: contact, lead, message, attempt, signal, watched account, pushed
event, suppression), `DataRetention` + `PurgeExpiredDataCommand`/`Job`, `LeadAnonymiser`,
`LegitimateInterestGate` (bound as `LeadSourcing\Contracts\LeadInterestGate`), `MarketConsentGate`,
`TeamDataArchiver`, `ExportTeamDataAction`. Public surfaces: `GET /privacy` (the notice) and a
**signed-link** unsubscribe (`/unsubscribe/{message}`), throttled. Config: `config/compliance.php`
with `retention`, `lia`, `dsar`, `export`, `privacy_notice`.

`Outreach\Services\OutreachGuard` is the single answer to _may this prospect be approached_, reading
`SuppressionList`, `SenderIdentityResolver`, `Contracts\LeadRestrictionResolver` and
`Contracts\TeamSendingGate`. `Core\Enums\OutreachRestriction` + `Market` carry the jurisdiction rule
(ADR 0019, `docs/compliance.md` §3.11).

### 1.8 Core helpers the plan would otherwise rebuild

| Need in the plan                            | Already exists                                                                                                                                                                                         |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Circuit breaker (PL3)                       | `Core\Support\CircuitBreaker` (used per-source by Signals under `signals:cb`)                                                                                                                          |
| CSV injection protection (PL6)              | `Core\Support\CsvCell`                                                                                                                                                                                 |
| SSRF guard for customer-supplied URLs (PL7) | `Core\Support\Security\SafeUrl` — **shared, never copied** (ADR 0023); it returns the addresses it approved so a caller can pin them                                                                   |
| `robots.txt` + web fetch (PL10)             | `Core\Contracts\WebContentFetcher` / `HttpWebContentFetcher`, `Core\Support\RobotsPolicy`, `Testing\FakeWebContentFetcher`                                                                             |
| Tenancy                                     | `Core\Concerns\BelongsToTeam` + `Core\Support\TeamContext`. **`'team_id' => null` is not enough to write a platform row** (ADR 0018 §9)                                                                |
| Audit (PL5 §8)                              | `Core\Services\AuditLogger` + `Support\AuditContext`                                                                                                                                                   |
| API envelope / pagination / versioning      | `Core\Http\Responses\ApiError`, `Core\Http\Requests\PaginatedIndexRequest`, `Http\Middleware\ApiVersion`; contract asserted over the whole route table by `tests/Feature/Api/V1/RouteContractTest.php` |
| Security headers + nonce CSP                | `Core\Http\Middleware\SecurityHeaders` (global), `Support\Security\ContentSecurityPolicy`                                                                                                              |

### 1.9 Export today

`LeadsWorkspace\Actions\ExportLeadsAction` + `Services\LeadCsvExporter`: a **streamed** CSV of the
team's leads as currently filtered, chunked (`leads-workspace.csv.export_chunk`), UTF-8 **BOM**,
`fputcsv(escape: '')`, cells passed through `CsvCell`, audited without copying the data, and gated by
`Capability::CsvExport`. Its column set deliberately matches `LeadCsvImporter` so an export can be
round-tripped through a spreadsheet.

### 1.10 Frontend, mobile, queues, observability, tests

- **Web:** Inertia + Vue 3 + Tailwind v4 `@theme` brand tokens (`docs/brand/brand-tokens.md`).
  Leads screens: `resources/js/pages/leads/{Index,Show,Pipeline,Create,Import}.vue` and
  `resources/js/components/leads/*` (`LeadFilterBar`, `LeadWorkPanel`, `ActivityTimeline`,
  `BulkActionBar`, `SavedViewBar`, `LeadNoticeRow`, `LeadNotes`, `LeadTasks`). Layout resolution is
  via `app.ts` `defaultLayout`.
- **Mobile:** `/mobile` is its **own npm project** with its own gates; shares code by Vite alias
  (`@shared` → `resources/js/shared/`, `@lang` → `lang/`) and **never by copy**. Views:
  `LeadsView`, `LeadDetailView`, `LeadDraftView`, `LeadLogView`, `PlaybookInboxView`,
  `NotificationsView`, `DashboardView`, `SettingsView`, `SignInView`, `TabsShell`. It is a **field
  companion**: settings, discovery, ICPs, the board, billing and compliance stay on the web **by
  decision**.
- **Queues:** Horizon has **one supervisor on `default`**, and **no job in `app/` calls `onQueue()`
  or sets `$queue`**. Reverb channels: exactly two — `App.Models.User.{id}` and `teams.{teamId}`.
- **Observability:** Sentry (scrubber aimed at our own log contexts), Pulse behind the admin panel,
  per-module log channels in `config/logging.php` (`ai`, `lead_sourcing`, `signals`, `outreach`,
  `notifications`, `insights`, `billing`).
- **Tests:** Pest 5 on PHP 8.5 / Laravel 13. `tests/Feature/<Module>` + `tests/Unit/<Module>`.
  In-memory SQLite, **enforced at runtime** by `AppServiceProvider::configureDefaults()`.
  Existing fakes: `FakeAiTransport`, `FakeWebContentFetcher`, `FakeDnsResolver`.

---

## 2. Gap table — plan vs codebase

| Phase                           | Verdict                    | What already exists                                                                                                                                                                                                                      | What is genuinely new                                                                                                                                                                                                                                                                                     |
| ------------------------------- | -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **PL0**                         | —                          | —                                                                                                                                                                                                                                        | This file, ADR 0026, ADR 0027, `progress.md`                                                                                                                                                                                                                                                              |
| **PL1** evidence / why-now      | **Extend**                 | `signals` (+ `score_breakdown`, `heat`, `resolved_market`), `leads.field_sources`, `lead_scores` (fit), `ConvertSignalToLeadAction`, `RecordLeadActivityAction`, `score_feedback` as the feedback pattern, `AiClient` + prompt registry  | `lead_evidence` + `lead_evidence_feedback`, `EvidenceKind`/`EvidenceSource`, confidence + timing maths, `config/evidence.php`, `evidence:backfill`, why-now prompt row + job, evidence UI, the first Pennant flag                                                                                         |
| **PL2** contacts + contracts    | **Extend**                 | `lead_contacts` **is** the plan's `contacts`; `EmailVerificationStatus`, `EmailVerifier`, `EmailPatternInferrer`, `SourcedValue` provenance, `config/enrichment.php`                                                                     | Person fields (`first/last/seniority/department/linkedin_url`), `email_hash`/`phone_e164`/`phone_hash`/`phone_type`, reveal stamps, masking accessors, `EmailFinder`/`PhoneFinder`/`PersonFinder` contracts + DTOs + registry + fakes + one real adapter                                                  |
| **PL3** waterfall               | **New, on existing rails** | `EnrichLeadAction`/`Job`, `LeadEnriched`, `Core\Support\CircuitBreaker`, the `teams.{teamId}` channel, the admin provider-health page                                                                                                    | `enrichment_runs` / `enrichment_attempts`, the waterfall job, per-provider rate limiting, bulk `Bus::batch`, reveal UI, provider stats                                                                                                                                                                    |
| **PL4** usage ledger            | **Extend, do not replace** | `usage_meters` (record-over-ledger), `BillingMetric`, the `ai_requests` ledger, `AiCreditGuard` + `CreditSource`, `UsageLedger`, overage + Stripe reporting, `config/billing.php` plans                                                  | Reveal/verification/export meters, **reservation** semantics (today's meters have no reserve/commit/release), top-up prices, the margin guard, usage UI                                                                                                                                                   |
| **PL5** compliance core         | **Mostly exists — extend** | `outreach_suppressions` (**is** `suppression_entries`), `SuppressionList`, `OutreachGuard`, `RecordOptOutAction`, `EraseSubjectDataAction`, `PersonalDataLocator`, `DataRetention`, `MarketConsentGate`, `LegitimateInterestGate`, audit | Public **unauthenticated DSAR/opt-out form** (today's is signed-link only), `data_subject_requests`, TPS/CTPS provider + `tps_checks`, PECR subscriber classification, `company_number`/`person_name_company` suppression types, team CSV suppression upload, UAE DNCR placeholder, the architecture test |
| **PL6** CSV export              | **Mostly exists — extend** | `ExportLeadsAction`, `LeadCsvExporter` (BOM, `CsvCell`, chunked, audited), `Capability::CsvExport`, the importer round-trip                                                                                                              | Presets, evidence/confidence/TPS/PECR columns, the revealed-only rule, suppression exclusion + count, an `exports` table, queued above N rows, signed URL + 7-day prune, `export_rows` metering                                                                                                           |
| **PL7** integrations + webhooks | **New module**             | `SafeUrl`, `CircuitBreaker`, `AuditLogger`, the API resources to reuse as payloads, 14 domain events, `signal_inbound_events` as the **inbound** precedent                                                                               | `app/Modules/Integrations`: `integrations`, `integration_links`, `integration_sync_logs`, `webhook_endpoints`, `webhook_deliveries`, `CrmConnector`, OAuth state, token refresh, HMAC signing, retry/auto-disable, settings UI                                                                            |
| **PL8/PL9** Pipedrive / HubSpot | **New**                    | PL7's framework                                                                                                                                                                                                                          | Two connectors + fixtures + settings pickers                                                                                                                                                                                                                                                              |
| **PL10** sample report          | **New, heavy reuse**       | Onboarding background check, `LeadSourcing` discovery, `Signals`, `Scoring`, brand tokens, `SafeUrl`, `WebContentFetcher`                                                                                                                | `/sample` public funnel, abuse + budget caps, the isolated sample context (**see ADR 0018 §9 warning**), report page, claim-on-register, funnel events, admin view                                                                                                                                        |
| **PL11** trust centre           | **Partly exists**          | `GET /privacy` + `config/compliance.php` `privacy_notice`, `docs/compliance.md`, `security:posture`                                                                                                                                      | `/trust/*` + `/legal/*` from markdown, `license_summary` per source + its test (ADR 0025 already recorded the licences), `config/subprocessors.php`, responsible-use acknowledgement, Article 14 notice + optional notifier, terms/privacy acceptance versioning                                          |
| **PL12** hardening              | **Extend**                 | `RouteContractTest`, `AdminRouteContractTest`, `SignalsMysqlDialectTest`, `security:posture`, Sentry/Pulse, the per-phase tenancy tests, the runbooks                                                                                    | Consolidated tenancy suite, authorisation audit test, rate limits on the new routes, load test, E2E smoke, demo seeder, `go-no-go.md`                                                                                                                                                                     |

---

## 3. Per-phase "adapt" notes

These are binding on the phase that follows. Where the plan names a table or class that exists here
under another name, **the repo's name wins**.

- **PL1.** `lead_evidence` is new; `fit_score` is **not** — it lives on `lead_scores.score` and must
  be read from there, not duplicated onto `leads` (two copies of a score is two scores). Add
  `timing_score`, `confidence`, `why_now_summary`, `why_now_generated_at`, `why_now_evidence_hash`,
  `suppressed_low_confidence` to `leads`. `EvidenceSource` must not become a second copy of
  `LeadSource` + `SignalSourceKind`; derive it from those (a source is a class, a type is a row —
  ADR 0018 §1). `confidence` is **nullable where nothing was judged**, following `signals.heat`: an
  unjudged component is left out of both sides of an average, never scored zero (ADR 0022). The
  why-now summary is a prompt **row** via `PromptRegistry`, wrapped in `UntrustedContent`,
  schema-validated and billed through `ai_requests` — not a hand-rolled AI call. Evidence feedback
  follows `RecordScoreFeedbackAction`/`score_feedback`. Write the signal's evidence row **inside**
  `ConvertSignalToLeadAction`'s transaction, where the timeline entry already goes.
- **PL2.** Extend `lead_contacts`; do not create `contacts`. Keep `EmailVerificationStatus` and map
  vendor statuses onto it rather than adding `EmailStatus`. Store `email` plain plus `email_hash`,
  and use the **same normalisation** `outreach_suppressions.value_hash` uses (an unsalted sha-256 of
  the normalised value) or a suppressed address will not match. `is_inferred` already distinguishes
  a guessed address; a vendor-found one is not inferred.
- **PL3.** One `default` queue (see §6.1). Reuse `Core\Support\CircuitBreaker` with its own key
  namespace and give each provider a matching `Admin\Enums\PlatformProvider` case, or an open breaker
  is invisible to operations (ADR 0023). Broadcast on the existing `teams.{teamId}` channel. A
  provider's **health** and a **query's validity** are different questions — the Signals ADR 0023 §4
  lesson applies verbatim to vendor 4xx. Record cost in **micros**, matching
  `leads.enrichment_cost_micros`, not the plan's millicredits/pence pair.
- **PL4.** `usage_meters` is a **record over the ledgers**, and that rule stays. A reveal is a
  discrete chargeable act with no prior ledger, so PL4 adds the ledger (`usage_ledger`) for those
  meters and keeps `usage_meters` as the period snapshot over it. Plan limits go in
  `config/billing.php` under the existing per-plan shape, **not** a new `config/plans.php`. New
  entitlement goes through `Capability` only if it is actually enforced (that enum's rule), and a
  per-plan numeric limit goes through a **new quota seam** Billing decorates — the fifth, after
  credit / lead / seat / signal. Enforce in the guard, never in middleware (ADR 0012).
  `BILLING_ENFORCE_PLANS=false` must not remove a cost floor.
- **PL5.** `outreach_suppressions` **stays** the one do-not-contact table (CLAUDE.md §3 and ADR 0016
  both say so). PL5 adds `SuppressionType` cases and the public request flow; it does not add
  `suppression_entries`. `OutreachGuard` remains the only answer to _may this prospect be
  approached_; a reveal, export or sync asks it (or a sibling guard reading the same list), never its
  own copy. `data_subject_requests` is a **platform** table — `'team_id' => null` is not enough, the
  row needs the platform-write mechanism. `tps_checks` is platform reference data keyed by phone
  hash: ADR 0025's "platform reference dataset" shape applies.
- **PL6.** Extend `LeadCsvExporter`; the preset that round-trips into `LeadCsvImporter` must keep
  working. Suppression exclusion goes through PL5's checker. Row counts are metered under PL4.
- **PL7.** New module via the `new-module` skill. `SafeUrl` is shared, not copied. The inbound
  precedent is `signal_inbound_events` + `POST /api/v1/signal-events` — read it before designing
  signing, replay protection and rate limits, and note that its secret is **encrypted, not hashed**,
  because verifying an HMAC means recomputing it. Any new `/api/v1` route must be declared by its own
  module, named, behind `auth:sanctum`, carry a `TokenAbility`, and appear in `docs/api/openapi.json`
  (`composer api:docs`) or `RouteContractTest` fails. A **cache key is a tenancy decision**
  (ADR 0023 §4a): per-endpoint poll floors and ETags are keyed by team, not by URL.
- **PL8/PL9.** Nothing to adapt beyond PL7's framework.
- **PL10.** The sample tenant is the hard part: a report generated "in a sandbox team context" must
  not become a real team's data, and `BelongsToTeam`'s `creating` hook fills a null `team_id` from
  the ambient `TeamContext`. Follow `SignalDefinition::$isPlatformPreset` — the mechanism for a
  platform write already exists; **removing a read scope and removing a write's auto-assignment are
  two different jobs** (ADR 0018 §9). An **eager load re-applies the scope**, which is how the admin
  library queue came back with a null definition on every row.
- **PL11.** The licence words the plan wants generated already exist: ADR 0025 and
  `docs/modules/lead-sourcing.md` → _Free sources — spike findings (L0)_. `license_summary` belongs on
  the source **registry entry**, so the test can assert it over the registry rather than over a
  hand-kept list. `/privacy` already exists and must keep its route name.
- **PL12.** `RouteContractTest` and `AdminRouteContractTest` are the pattern for the authorisation
  audit — assert over the **whole route table**, because a per-route test only covers the routes
  somebody remembered to write one for.

---

## 4. Things only Alex can do (plan §2) — unchanged

Vendor selection and written confirmation that embedding is allowed, vendor keys and per-call costs,
the TPS/CTPS licence, UAE DNCR/PDPL advice, the Pipedrive + HubSpot apps, Turnstile keys, Stripe
top-up prices, legal review, the Hetzner region. **PL2 and PL3 are blocked on the first of these only
for the _real_ adapter**; the contracts, fakes and orchestration are not blocked (ADR 0027).

---

## 5. Pennant flags

The plan's nine flags (Appendix A) are all new; Pennant is installed and unused. A flag gates
**visibility of unfinished work**; `Capability` gates **entitlement**. They answer different
questions and must not be conflated — ADR 0026 §4.

---

## 6. Where the plan contradicts the codebase

Plan §0 says the repo wins and the conflict is recorded. Four conflicts, all in ADR 0026:

1. **Named queues (plan §0.7, Appendix B).** This repo runs one `default` queue and no job names
   one. Six supervisors for pre-launch would be an operational change nobody asked for, on a
   single-operator deployment. **Decision: keep `default`**; revisit in PL12 with real queue-depth
   numbers, which is also where the plan puts Horizon tuning.
2. **`docs/adr/NNNN-*.md` (plan §0.9).** ADRs live in `docs/decisions/` here and are referenced by
   number from `CLAUDE.md`. **Decision: `docs/decisions/`**, continuing from 0026.
3. **`config/plans.php` (Appendix C).** Plan limits already live in `config/billing.php` keyed by
   `PlanKey`, and ADR 0012/0015 put enforcement in guards reading that. A second plan config is how
   two answers to "what is included" come to disagree. **Decision: merge into `config/billing.php`.**
4. **New `suppression_entries` / `contacts` tables (PL5, PL2).** Both exist under other names
   (`outreach_suppressions`, `lead_contacts`) and are load-bearing. **Decision: extend.**

Two softer mismatches, flagged now rather than mid-phase:

- **PL4's millicredits/pence vs this repo's micros.** Everything monetary here is integer **micros**
  (`cost_micros`, `enrichment_cost_micros`, `search_cost_micros`). PL2–PL4 use micros.
- **PL1's `fit_score` on `leads`.** It is `lead_scores.score`. Copying it onto `leads` creates a
  second score that will drift from the one the grades and the pipeline are built on.
