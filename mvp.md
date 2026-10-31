# AlimaProspects — MVP Build Plan

> The phased plan to take AlimaProspects from the bootstrapped foundation to a launchable MVP. Each phase is sized for **one Claude Code session**. Read `CLAUDE.md` and the named `docs/modules/*.md` before starting any phase. A phase is done only when: `composer test` green, `composer analyse` clean (Larastan ≥ level 6; repo at 7), `npm run check` + `npm run types:check` + `npm run build` pass, **and docs updated** (module doc Changelog row, `architecture.md` if structure changed, `CHANGELOG.md`, this status table).

## Status

| Phase | Title                                         | Status  | Completed  |
| ----- | --------------------------------------------- | ------- | ---------- |
| 0     | Foundation, docs & knowledge base             | ✅ Done | 2026-10-28 |
| 1     | Auth, teams, roles & invitations              | ✅ Done | 2026-10-28 |
| 2     | Brand design system & app shell (i18n/RTL)    | ✅ Done | 2026-10-28 |
| 3     | Onboarding & Business Profile                 | ✅ Done | 2026-10-29 |
| 4     | Ideal Customer Profile (ICP)                  | ✅ Done | 2026-10-29 |
| 5     | AI Platform                                   | ✅ Done | 2026-10-29 |
| 6     | Business Intelligence (AI background check)   | ✅ Done | 2026-10-29 |
| 7     | Lead Sourcing — framework + Companies House   | ✅ Done | 2026-10-29 |
| 7b    | Lead Sourcing — Google Places + web search    | ✅ Done | 2026-10-29 |
| 7c    | Lead Sourcing — search for customers          | ✅ Done | 2026-10-30 |
| 8     | Enrichment & Data Quality                     | ✅ Done | 2026-10-29 |
| 9     | Scoring & Matching                            | ✅ Done | 2026-10-29 |
| 10    | Leads Workspace — list, detail, contacts      | ✅ Done | 2026-10-29 |
| 10b   | Leads Workspace — pipeline, notes, tasks, CSV | ✅ Done | 2026-10-29 |
| 10c   | Leads Workspace — add a lead by hand          | ✅ Done | 2026-10-30 |
| 11    | Outreach (MVP)                                | ✅ Done | 2026-10-30 |
| 12    | Notifications                                 | ✅ Done | 2026-10-30 |
| 13    | Reporting & Analytics                         | ✅ Done | 2026-10-30 |
| 14    | AI Insights (light)                           | ✅ Done | 2026-10-30 |
| 15    | Compliance & Privacy features                 | ✅ Done | 2026-10-30 |
| 16    | Billing & Plans                               | ✅ Done | 2026-10-30 |
| 17    | API v1 hardening                              | ✅ Done | 2026-10-31 |
| 18    | Mobile app (Capacitor + Ionic Vue)            | ✅ Done | 2026-10-31 |
| 19a   | Admin panel — foundation, identity & CRUD     | ✅ Done | 2026-10-31 |
| 19b   | Admin panel — controls, Stripe & provisioning | ✅      | 2026-10-31 |
| 19c   | Admin panel — platform reporting & ops        | ⬜      | —          |
| 20    | Observability & security hardening            | ⬜      | —          |
| 21    | Hetzner deployment & CI/CD                    | ⬜      | —          |
| 22    | Launch checklist                              | ⬜      | —          |

Legend: ⬜ not started · 🟡 in progress · ✅ done · ⛔ blocked.

> **Signals** (trigger-event prospecting, UAE v1) is planned separately in **`signals-plan.md`**, phases **S0–S6**, as a companion to this plan. **S0–S6 ✅ complete 2026-10-29** — the module, the Serper News client, AI event extraction, convert-to-Lead (with the UAE prior-consent outreach flag), the bilingual feed and watch settings, and API v1 + retention + the DSAR hook. **Both of its open dependencies on later phases are now closed.** Outreach (Phase 11) honours `leads.raw.outreach_restriction` at draft time as well as send time, and reports it per channel over the API (see `docs/modules/outreach.md`); and Compliance (Phase 15) is now the caller of **`SignalsPersonalDataExporter`** — which also turned up a `LIKE ... ESCAPE` backslash in it that would have failed on MySQL, corrected in that phase. Phase 15's retention sweep additionally deletes the signals attached to a lead it removes, closing a leak the signals sweep could not reach: a converted signal is never pruned, so one whose lead was deleted would have outlived it with a named individual still on it. A read-only admin view of `signal_runs` is deferred to **Phase 19**. See `docs/modules/signals.md` and ADR 0011. It sits after Leads Workspace (10/10b) and before/alongside Outreach (11); its own status table lives in `signals-plan.md`.

---

## What the MVP is (and isn't)

**In scope (v1 launch):** a UK/UAE team can sign up, be guided through onboarding, get an AI background check, define a weighted ICP, run AI-assisted lead discovery (Companies House + Google Places + web search), have leads enriched/deduped/scored (A–D with explanation), work them in a CRM-lite workspace (list, detail, pipeline, notes, tasks, assignment, CSV), generate AI-personalised outreach drafts and log contact attempts (with suppression/opt-out enforced), see a reporting dashboard, receive notifications and a daily "contact today" digest, pay via Stripe with plan-based lead/AI credit limits, and exercise core compliance (suppression, opt-out, retention, team export). Bilingual EN/AR + RTL. A hardened `/api/v1` and a focused mobile app follow. **Internal operations moved into MVP scope at Phase 19** (ADR 0014): a bespoke admin panel with cross-tenant CRUD, platform reporting, business provisioning, Stripe switches and plan-driven feature flags — built in-house rather than with Filament.

**Explicitly out (post-MVP backlog at the bottom):** paid B2B enrichment providers, multi-step outreach sequences + mailbox OAuth + open/reply tracking, full AI-insight suite with one-click apply, CRM integrations/webhooks/Zapier, calendar booking, SSO/SCIM, SMS/Slack notifications, custom report builder, and advanced scoring (learning-to-rank).

---

## Cost model & margin protection

AI and data-provider calls are the variable cost of a "lead search". Protect margins with **per-plan credit allowances enforced before work runs** (Billing → AI Platform / Lead Sourcing guards).

**Indicative cost per discovery run** (≈50 candidate companies, order-of-magnitude — verify with live pricing at the AI/providers phases):

| Item                                            | Unit                        | ~Cost         | Per 50-lead run                            |
| ----------------------------------------------- | --------------------------- | ------------- | ------------------------------------------ |
| Companies House                                 | per request                 | free          | £0                                         |
| Google Places                                   | per detail request          | ~$0.017       | ~$0.85                                     |
| Web search API (Serper/Brave)                   | per query                   | ~$0.001–0.005 | ~$0.10–0.25                                |
| AI enrichment extract (Claude Haiku)            | per lead, ~2k in/0.5k out   | ~$0.002       | ~$0.10                                     |
| AI qualitative scoring (Claude Sonnet)          | per lead, ~1.5k in/0.5k out | ~$0.012       | ~$0.60                                     |
| AI background check (Sonnet, once per business) | per run                     | ~$0.05        | n/a per-run                                |
| **Total variable**                              |                             |               | **≈ $1.65–1.80 / 50 leads (~$0.035/lead)** |

**Controls:** (1) a **credit** = a unit of metered AI+data spend; each plan grants a monthly allowance; (2) `AiCreditGuard` + `EnforcesPlanLimits` block runs/calls over allowance (upsell to overage); (3) **response caching** on identical AI inputs; (4) cheap-tier (Haiku) for classification/extraction, reasoning-tier (Sonnet) only for scoring/background check; (5) per-team cost logged to `ai_usage` and surfaced in Reporting + Admin. Target a healthy markup of list price over variable cost at each plan tier (set in Billing phase).

---

## Phases

Each phase below: **Goal · Depends on · Modules & docs · Tasks · Data model · Tests · Acceptance · Dispatch prompt · Risks**.

---

### Phase 0 — Foundation, docs & knowledge base ✅

**Goal:** A working Laravel 13/PHP 8.5 app with the full package set, the Core module (tenancy, audit, logging, exceptions), CI, and the complete docs/knowledge-base + this plan.
**Status:** Done 2026-10-28. See `CHANGELOG.md`. Delivered the scaffold, Core module, ADRs 0001–0009, all module docs, brand tokens, compliance, ai-prompts index, runbooks, and `mvp.md`.

---

### Phase 1 — Auth, teams, roles & invitations

**Goal:** A user can register, verify, log in with 2FA, create/switch teams, invite teammates with roles, and get API tokens.
**Depends on:** 0.
**Modules & docs:** Auth & Users (`docs/modules/auth-users.md`), Core (`core.md`).
**Tasks:**

1. [x] Enable **spatie permission teams mode** (config `teams => true`, team foreign key); adjust/replace the published permission migration; seed the four `TeamRole` roles.
2. [x] Resolve the active team into `TeamContext` + spatie's `setPermissionsTeamId()` in `LogContext`/a dedicated middleware early in the request.
3. [x] `CreateTeamAction`, team switch endpoint (web + `/api/v1`), guard by membership.
4. [x] Team **invitations**: `team_invitations` table, invite/accept/resend/revoke Actions, signed accept URL, mailable; invited user joins with a role.
5. [x] Verify Fortify flows (register, verify email, reset, **2FA enable/confirm/recovery**) work with the starter kit; wire registration to create a personal team + Owner role.
6. [x] Sanctum token issue/revoke endpoints for mobile; per-token abilities; rate-limit `/api/v1`.
7. [x] Policies for team management; audit log on create/switch/invite/role-change.
       **Data model:** `team_invitations`; permission tables (team-scoped); role on `team_user`.
       **Tests:** registration creates team+owner; 2FA challenge; invite→accept joins team; switch-team denied for non-member; cross-tenant team access fails; token auth on a sample `/api/v1` route.
       **Acceptance:** all auth + team flows pass; tenancy tests green; Larastan clean; docs updated.
       **Dispatch prompt:** _"Execute Phase 1 of mvp.md (Auth, teams, roles & invitations). Read CLAUDE.md, docs/modules/auth-users.md and core.md first. Enable spatie teams mode, wire team creation/switching/invitations and roles, verify Fortify 2FA, add Sanctum token endpoints, write the tests, and update docs."_
       **Risks:** spatie teams + global scope interaction (set permission team id consistently); Fortify 2FA UI already in starter kit — confirm before rebuilding.

---

### Phase 2 — Brand design system & app shell (i18n/RTL)

**Goal:** Brand tokens in Tailwind v4, the reusable base component library, the authenticated app shell (nav, team switcher, locale switch), and EN/AR + RTL.
**Depends on:** 1.
**Modules & docs:** Core; `docs/brand/brand-tokens.md`; ADR 0007.
**Tasks:**

1. [x] Encode brand tokens as Tailwind v4 `@theme` vars in `resources/css/app.css` (colours, semantic tokens, radius, shadow, motion, fonts Poppins/DM Mono/Tajawal); export a shared token module in `resources/js/shared/tokens.ts` for mobile.
2. [x] Build base components (`Button`, `Input`, `Card`, `Table`, `Modal`, `Badge`, `EmptyState`, `Toast`, form controls) on the brand, WCAG AA, keyboard-accessible, with a `/design` showcase page.
3. [x] App shell: top bar (logo, team switcher, user menu, locale toggle + appearance toggle), responsive nav, toast host; one primary CTA per view convention.
4. [x] **i18n**: Laravel + front-end locale files (en, ar), `dir`/RTL handling (`SetLocale` middleware), Tajawal for Arabic, number/date formatting per team locale/timezone (`useI18n`/`useFormat`).
5. [x] Dark-mode tokens implemented from `brand-tokens.md` §7.1 — ⚠️ **still PENDING brand-owner approval** (flagged below & in the CSS).
6. [x] Wire Laravel Echo + Reverb on the front end (`resources/js/echo.ts`, `laravel-echo` + `pusher-js`).
       **Data model:** none.
       **Tests:** component render/accessibility smoke (vue-tsc + build); locale switch flips `dir`; a Pest test for locale middleware.
       **Acceptance:** base components exist and are used by the shell; EN/AR + RTL works; build + types pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 2 of mvp.md (Brand design system & app shell). Read brand-tokens.md and ADR 0007. Encode tokens into Tailwind v4, build the base component library and app shell, add EN/AR + RTL, and wire Echo/Reverb. Flag dark-mode values for approval."_
       **Risks:** dark-mode palette pending approval; Tailwind v4 `@theme` specifics; RTL correctness across components.

---

### Phase 3 — Onboarding & Business Profile

**Goal:** A resumable onboarding wizard that captures the business profile and triggers the background check.
**Depends on:** 1, 2 (5 optional — can stub the trigger).
**Modules & docs:** Onboarding & Business Profile (`onboarding-business-profile.md`).
**Tasks:**

1. [x] `business_profiles` + `onboarding_progress` migrations/models (team-scoped, `BelongsToTeam`).
2. [x] Wizard steps (company, website, industry, size, locations, regions UK/UAE, products/services, value prop, pricing range, customer examples) with per-step validation (FormRequests/Data).
3. [x] `SaveOnboardingStepAction` (resumable), `CompleteOnboardingAction` (dispatches background-check job `afterCommit` — **stubbed** `RequestBackgroundCheckJob`, Phase 6 implements it).
4. [x] Inertia wizard pages + progress indicator; market selection sets compliance defaults (writes `teams.market`).
5. [x] `/api/v1` read of profile + progress (`GET /api/v1/onboarding`).
       **Data model:** `business_profiles`, `onboarding_progress`.
       **Tests:** step save/resume; completion dispatches job; tenancy; validation.
       **Acceptance:** wizard saves/resumes, profile stored, completion hook fires; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 3 of mvp.md (Onboarding & Business Profile). Read the module doc. Build the resumable wizard, business_profiles model, save/complete Actions (stub the BI trigger if needed), Inertia pages, and tests."_
       **Risks:** scope creep in fields; keep it to the doc's list.

---

### Phase 4 — Ideal Customer Profile (ICP)

**Goal:** Teams define weighted ICPs with criteria and exclusions; changes emit a re-score event.
**Depends on:** 3.
**Modules & docs:** ICP (`icp.md`).
**Tasks:**

1. [x] `icps` + `icp_criteria` migrations/models (team-scoped, weights, exclusions).
2. [x] `CreateIcpAction`, `UpdateIcpAction`, `SetActiveIcpAction` (+ `DeleteIcpAction`); emit `IcpUpdated` event (consumed later by Scoring).
3. [x] Criteria types enum (industry, size, geo, title/seniority, technographic, buying-signal, exclusion).
4. [x] Inertia ICP editor with weight sliders + exclusions; `/api/v1` CRUD + resources.
5. [ ] (If Phase 5 done) accept AI-suggested ICP from Business Intelligence. — _AI Platform is built and the `icp.refine_suggestions` prompt is seeded (v1); the consumer that applies suggestions lands with Business Intelligence (Phase 6)._
       **Data model:** `icps`, `icp_criteria`.
       **Tests:** CRUD; weights persist; active-ICP switch; `IcpUpdated` dispatched; tenancy; validation.
       **Acceptance:** ICPs editable with weights/exclusions; event fires; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 4 of mvp.md (ICP). Read icp.md. Build icps/icp_criteria, the weighted editor, CRUD Actions + API, the IcpUpdated event, and tests."_
       **Risks:** criteria schema must be flexible enough for scoring without over-engineering.

---

### Phase 5 — AI Platform

**Goal:** A production-grade `AiClient` (Anthropic Claude via `laravel/ai`) with prompt registry, JSON-schema validation, retries, caching, and per-team cost/credit logging.
**Depends on:** 1 (Billing optional — stub credit source).
**Modules & docs:** AI Platform (`ai-platform.md`), `docs/ai-prompts.md`, ADR 0005.
**Tasks:**

1. [x] `composer require laravel/ai`; configure Anthropic driver; env keys (`ANTHROPIC_API_KEY`, models). _(config/ai.php + config/ai-platform.php; default provider Anthropic.)_
2. [x] `Contracts\AiClient` + `ClaudeAiClient` (reasoning=Sonnet, cheap=Haiku); a fake for tests. _(`AiTransport` seam + `Testing\FakeAiTransport`; tier→model via config.)_
3. [x] `PromptRegistry` + `ai_prompt_templates` (key, version, body, schema, tier); seed initial prompts from `ai-prompts.md`. _(All 6 seeded at v1.)_
4. [x] Structured output: JSON-schema validation + retry on invalid → typed DTO. _(`JsonSchemaValidator` + `StructuredAiResponse`.)_
5. [x] `ai_requests` logging (tokens, cost, latency, cache hit) per team; `ai` log channel. _(Cost in integer **micro-units** — sub-cent precision; `ai_usage` derived by summing per period.)_
6. [x] `AiCreditGuard` (reads plan allowance — **stub** until Billing); response cache keyed on team+prompt+version+template-hash+input hash.
7. [x] **Injection safety** helper: wrap untrusted content as delimited data; never execute embedded instructions. _(`UntrustedContent` + nonce-delimited block.)_
       **Data model:** `ai_prompt_templates`, `ai_requests`/`ai_usage`.
       **Tests:** fake client returns schema-valid output; invalid output retried then errors; usage logged; cache hit avoids a 2nd call; injection wrapper; **external AI always faked**.
       **Acceptance:** any module can call `AiClient->structured(key, input, schema)`; cost logged; credits enforced (stub ok); gates pass; docs + `ai-prompts.md` updated.
       **Dispatch prompt:** _"Execute Phase 5 of mvp.md (AI Platform). Read ai-platform.md, ai-prompts.md, ADR 0005. Install laravel/ai, build AiClient + Claude driver + fake, prompt registry, schema validation, usage/cost logging, credit guard (stub), caching, injection safety, and tests. Never hit a real API in tests."_
       **Risks:** `laravel/ai` API surface; model ids/pricing drift (verify live); keep our interface stable regardless of transport.

---

### Phase 6 — Business Intelligence (AI background check)

**Goal:** Re-runnable, versioned AI background check on the signed-up business, with review/accept.
**Depends on:** 3, 5, 7 (web fetch — can use web-search provider; if 7 not done, add a minimal fetch).
**Modules & docs:** Business Intelligence (`business-intelligence.md`).
**Tasks:**

1. [x] `business_intel_reports` (versioned, status) model/migration.
2. [x] `RunBackgroundCheckJob` (idempotent, `ShouldBeUnique`): fetch site/public pages, call `business_intel.background_check`, validate schema, store, **progress over Reverb**. _(Minimal SSRF-safe `WebContentFetcher` seam since Phase 7 isn't done.)_
3. [x] `AcceptBackgroundCheckAction` (merge into Business Profile + optionally seed ICP), `RerunBackgroundCheckAction`.
4. [x] Inertia review screen (diff vs the business's own profile); accept/edit; `/api/v1` read.
       **Data model:** `business_intel_reports`.
       **Tests:** job produces a report (faked AI); accept merges; rerun versions; injection-safe handling; tenancy.
       **Acceptance:** background check runs, is reviewable/acceptable, versioned; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 6 of mvp.md (Business Intelligence). Read the module doc. Build business_intel_reports, the RunBackgroundCheckJob with Reverb progress, accept/rerun Actions, review UI, and tests with faked AI."_
       **Risks:** fetching arbitrary sites (timeouts, size limits, ToS); treat content strictly as data.

---

### Phase 7 — Lead Sourcing: framework + Companies House

**Goal:** The `LeadSourceProvider` contract, queued/tracked discovery runs with progress and limits, and the Companies House provider.
**Depends on:** 4, 5.
**Modules & docs:** Lead Sourcing (`lead-sourcing.md`), ADR 0008.
**Tasks:**

1. [x] `Contracts\LeadSourceProvider`; provider registry/factory; `leads` (company-level) + `discovery_runs` migrations (team-scoped, source attribution).
2. [x] `CompaniesHouseProvider` (official API, free) with timeout/retry/circuit-breaker; env key.
3. [x] `DiscoverLeadsJob` (idempotent, `$tries/$backoff/$timeout/failed()`, progress over Reverb); `StartDiscoveryRunAction` enforces per-plan limits (stub until Billing).
4. [x] Inertia "new discovery" + run-progress screens; `/api/v1` start/list runs.
5. [x] Respect ToS/robots; attach `source`/`source_url`/`collected_at` to every field; LIA gate check (stub until Compliance). _(Companies House official API used within terms; lead-level attribution — per-field `SourcedValue` lands in Phase 8; dedupe is a per-team `dedupe_key` placeholder.)_
       **Data model:** `leads`, `discovery_runs`.
       **Tests:** faked provider yields leads; run tracked + progress; limit blocks over-quota (stub); dedupe placeholder; tenancy.
       **Acceptance:** a discovery run against Companies House (faked in tests) creates attributed leads with progress; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 7 of mvp.md (Lead Sourcing framework + Companies House). Read lead-sourcing.md and ADR 0008. Build the LeadSourceProvider contract, discovery_runs + leads, the Companies House provider, DiscoverLeadsJob with Reverb progress + limits, UI, and tests with a faked provider."_
       **Risks:** Companies House rate limits; mapping registry data to our lead shape.

---

### Phase 7b — Lead Sourcing: Google Places + web search

**Goal:** Add the Google Places and web-search providers (UAE + general discovery).
**Depends on:** 7.
**Modules & docs:** Lead Sourcing, ADR 0008.
**Tasks:**

1. [x] `GooglePlacesProvider` (location + category; strong for UAE); env key; cost logged.
2. [x] `WebSearchProvider` (Serper/Brave/Bing abstraction — Serper is the MVP driver, ADR 0010); used for discovery + feeding BI/enrichment.
3. [x] Provider selection in the discovery UI; per-provider cost surfaced; dedupe across providers. _(All three providers selectable; `cost_micros` surfaced per run; source-agnostic `dedupe_key` collapses shared-key-type leads across providers.)_
       **Data model:** none new (reuse `leads`/`discovery_runs`; add `provider` metadata).
       **Tests:** each provider faked; multi-provider run dedupes; cost recorded.
       **Acceptance:** three providers selectable; costs tracked; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 7b of mvp.md (Google Places + web search providers). Read lead-sourcing.md. Add both providers behind LeadSourceProvider, surface per-provider cost, dedupe across providers, and test with fakes."_
       **Risks:** Google Places pricing/quotas; web-search provider choice (pick in this phase, record ADR).

---

### Phase 7c — Lead Sourcing: search for customers, not competitors

**Goal:** Stop discovery returning companies like the team. Searches were built from the active ICP's industry values, and those values were the team's **own** trade, so a graphic design studio was shown graphic design studios — its competition, dressed as its pipeline.
**Depends on:** 5 (AI Platform), 6 (Business Intelligence), 7, 7b.
**Modules & docs:** Lead Sourcing, Business Intelligence, ICP, `docs/ai-prompts.md`.
**Tasks:**

1. [x] **Name the two sides in the background check.** `business_intel.background_check` **v2**: `own_industry` + `competitors` describe the seller; `customer_industries` + `target_customers` + `decision_maker_titles` describe the buyer. Every field described, and the system prompt states that the business being read is the seller. v1 stays seeded so existing reports remain traceable.
2. [x] **Stop the ICP seeder crossing them.** `AcceptBackgroundCheckAction` seeds `Industry` signals from the customer side only, `JobTitle` from decision-maker titles, and puts the team's own trade + named competitors in as `Exclusion` criteria. With no customer-side signal it seeds nothing — a wrong ICP is worse than none, because discovery and scoring act on it silently.
3. [x] **Add the AI query-shaping step Phase 7 deferred.** `lead_sourcing.shape_query` + `DiscoveryQueryShaper`: invert seller into buyer, once per source, before the fan-out. Respects terms the member typed, skipped without a business profile or by config, and **degrades** to the deterministic `DiscoveryQuery::fromIcp()` on any AI error (including exhausted credits) rather than failing the run.
4. [x] **Make exclusions bite at discovery, not just at scoring.** `DiscoveryQuery` carries `excludeTerms`; `WebSearchProvider` pushes them to Serper as `-"term"`; `LeadCandidateFilter` drops matching candidates for every provider **before** persistence, so an excluded company is never enriched, never counted against the allowance and never read.
5. [x] Show the team what was searched: the run screen states who it looked for (`customer_description`) and what it left out.
       **Data model:** none new — `discovery_runs.params` gains `exclude_terms`, `customer_description` and `shaped`; a run written before this phase reads back with the new keys absent.
       **Tests:** the design-studio case end to end (shaped query excludes the own trade); explicit terms skip the model; AI failure degrades to the deterministic query; an empty shaped result refuses rather than searching the own trade; the filter never persists an excluded candidate; cost is still metered on what the provider returned.
       **Acceptance:** a design studio's run returns its customers, not studios; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 7c of mvp.md (search for customers, not competitors). Read lead-sourcing.md, business-intelligence.md and ai-prompts.md. Split the background check's seller/buyer fields, fix the ICP seeding, add the query-shaping prompt with a deterministic fallback, and make exclusions apply at discovery."_
       **Risks:** one reasoning-tier call per source per run (cost); blunt substring exclusion matching can drop a good lead whose name contains the team's trade word; an ICP seeded before this phase still carries the wrong industry signal and nothing retro-fixes it.

---

### Phase 8 — Enrichment & Data Quality

**Goal:** Clean, deduped, source-attributed leads with contacts, free email verification, and freshness — no paid provider.
**Depends on:** 7, 5.
**Modules & docs:** Enrichment & Data Quality (`enrichment-data-quality.md`), ADR 0008, compliance.
**Tasks:**

1. [x] `lead_contacts` + enrichment columns on `leads`; `SourcedValue` DTO + `SourcedValueMap` cast (source/url/collected_at/method per field).
2. [x] `EnrichLeadJob` (idempotent, unique per lead) + `EnrichLeadAction`: normalise company data; **AI extraction** of facts from fetched public text (`lead.enrich_extract`, cheap tier, injection-safe); triggered by `DiscoveryRunCompleted`.
3. [x] **Email verification** (syntax + MX/DNS + disposable-domain) behind a faked `DnsResolver`; confidence stored, never asserted (no SMTP probe).
4. [x] **De-duplication** by company number, domain, same-domain email; merge keeping best source per field + promoting bookkeeping, deadlock-safe group locking, scraped-content false-merge guards.
5. [x] Prefer business/role over personal data; flag personal-looking data (`has_personal_data` + `is_personal`) for compliance.
       **Data model:** `lead_contacts`, enrichment fields on `leads`.
       **Tests:** extraction (faked AI); MX check (faked resolver); dedupe merges; source attribution enforced; tenancy.
       **Acceptance:** leads enriched + deduped with attribution + freshness; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 8 of mvp.md (Enrichment & Data Quality). Read the module doc + ADR 0008. Build lead_contacts, SourcedValue, EnrichLeadJob with AI extraction, MX/DNS email verification, dedupe, and tests with fakes."_
       **Risks:** SMTP probing etiquette (prefer MX-only); AI extraction accuracy; dedupe false-merges.

---

### Phase 9 — Scoring & Matching

**Goal:** Hybrid ICP-fit score (deterministic + AI) with grade A–D, breakdown, explanation, re-scoring and feedback.
**Depends on:** 4, 8, 5.
**Modules & docs:** Scoring & Matching (`scoring-matching.md`).
**Tasks:**

1. [x] `DeterministicScorer` over `icp_criteria` weights vs enriched fields → fit score + breakdown. _(Weights normalised over what could be **assessed**; a criterion the lead has no data for is `Unknown` and leaves both sides of the ratio, so the score measures fit, not enrichment coverage. Company size by numeric band overlap; country aliases; absolute exclusions.)_
2. [x] `ScoreLeadAction` combining deterministic + `scoring.qualitative_fit` (AI) → score, grade, explanation; `lead_scores` table. _(AI outside the transaction; the model's own grade is discarded so one scale governs; degrades to either half, and writes nothing when neither has anything to say.)_
3. [x] Re-score on `IcpUpdated` and on enrichment update (queued, idempotent). _(New `Enrichment\Events\LeadEnriched`; the ICP-change listener is itself queued and chunks the team's leads. No active ICP → nothing queued; old scores stay as history.)_
4. [x] `score_feedback` (good/bad) capture; surface grade/breakdown/explanation (Inertia + `/api/v1`). _(Verdict snapshots the score it judged. Score screen + a Fit column on the discovery run table; the leads index/detail arrives in Phase 10.)_
       **Data model:** `lead_scores`, `score_feedback`.
       **Tests:** deterministic math; hybrid produces grade+explanation (faked AI); re-score on ICP change; feedback stored; tenancy.
       **Acceptance:** leads graded with explanation; re-scoring works; feedback captured; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 9 of mvp.md (Scoring & Matching). Read the module doc. Build the deterministic scorer, hybrid ScoreLeadAction, lead_scores, re-score listeners, feedback, UI, and tests with faked AI."_
       **Risks:** weight normalisation; keeping explanations grounded (no hallucinated facts).

---

### Phase 10 — Leads Workspace: list, detail, contacts

**Goal:** The leads index (filters/search/saved views), lead detail page with contacts, and the activity timeline.
**Depends on:** 9.
**Modules & docs:** Leads Workspace (`leads-workspace.md`).
**Tasks:**

1. [x] Leads index: server-driven table, filters (grade, source, stage, tags), search, pagination; **saved views** per user. _(Grade, source, enrichment state, country and free text now; **stage and tags arrive with their tables in 10b**, which is when the `LeadFilters` shape grows. Sorting by newest/company/fit/freshness, always tie-broken on `id`; filters ride every page link.)_
2. [x] Lead detail: company + contacts, score panel, source attribution, data freshness. _(Plus a compliance note when a contact looks like a named person rather than a role.)_
3. [x] `lead_activities` timeline (polymorphic) aggregating discovery/enrichment/scoring events. _(Three queued listeners; `properties` stores values, never a sentence, so the line is composed EN/AR at render time. Scoring gained a `LeadScored` event to listen to.)_
4. [x] `/api/v1` list/detail with resources + pagination. _(Detail is one `LeadDetailResource` carrying lead + score + timeline, so a phone fills the screen in one round trip.)_
       **Data model:** `lead_activities`, `saved_views`.
       **Tests:** filter/search/pagination; detail loads contacts+score; timeline; tenancy; no N+1 (preventLazyLoading).
       **Acceptance:** list + detail usable; saved views work; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 10 of mvp.md (Leads Workspace list/detail). Read the module doc. Build the leads index with filters/search/saved-views, the detail page with contacts+score+timeline, the API, and tests."_
       **Risks:** N+1s on list; filter performance (index columns).

---

### Phase 10b — Leads Workspace: pipeline, notes, tasks, bulk, CSV

**Goal:** Customisable kanban pipeline, tags, notes, tasks/reminders, assignment, bulk actions, and CSV import/export.
**Depends on:** 10.
**Modules & docs:** Leads Workspace.
**Tasks:**

1. [x] `pipeline_stages` (team-customisable), `lead_tags`, `lead_notes`, `lead_tasks` (due/reminder), `lead_assignments`. _(Plus `leads.pipeline_stage_id`, nullable — **null means the default column**, so no backfill and no lead left off the board; the fold lives in `LeadQueryBuilder`, not in each caller.)_
2. [x] Kanban board (drag between stages), tag/note/task/assign UI; bulk actions (tag, assign, move, suppress, add to outreach). _(Drag **and** an equivalent pick-then-Move control, since a mouse-only board is unusable for half a team. **`suppress` and `add to outreach` are deliberately deferred** to Compliance (15) and Outreach (11) — a button that silently did nothing would be worse than not offering it.)_
3. [x] **CSV import** (column mapping, dedupe on import) and **export**. _(BOM/Windows-1252/delimiter/ragged rows handled; de-dupe fills gaps and never overwrites; a new `LeadSource::Import` outranks every provider so discovery can't rename what the team typed. Export streams by keyset and neutralises formula cells; its columns are the importer's, so a round trip is lossless.)_
4. [x] Task reminders raise notifications (Phase 12); stage moves write to timeline + audit. _(Stage moves write both in one transaction. Reminders were stored but unsent until **Phase 12 closed this**: `notifications:task-reminders` sweeps `remind_at` hourly and stamps `reminded_at` before raising, so a reminder cannot go twice.)_
       **Data model:** `pipeline_stages`, `lead_tags`, `lead_notes`, `lead_tasks`, `lead_assignments`.
       **Tests:** stage move; note/task CRUD; assignment; bulk action; CSV round-trip + dedupe; tenancy.
       **Acceptance:** full CRM-lite collaboration works; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 10b of mvp.md (Leads Workspace pipeline/notes/tasks/CSV). Read the module doc. Build pipeline stages, tags/notes/tasks/assignment, bulk actions, CSV import/export, and tests."_
       **Risks:** CSV edge cases/encoding; kanban perf with many leads.

---

### Phase 10c — Leads Workspace: add a lead by hand

**Goal:** A member can add one company themselves — met at an event, referred, or found somewhere Alima doesn't look — and it joins the normal Enrichment → Scoring → Workspace pipeline.
**Depends on:** 10b.
**Modules & docs:** Leads Workspace.
**Tasks:**

1. [x] `LeadSource::Manual` + `LeadActivityType::Created`, and `OwnLeadFields` extracted from the CSV importer. _(Manual is not a provider and ranks **with** `Import`, not above it — equal means the older record wins a tie, so adding a company by hand can never displace the team's existing one. The extraction is the point of the phase as much as the form: both ways a team supplies a company now share one identity key, so a lead typed in at a stand and the same lead in a spreadsheet collapse into one.)_
2. [x] `CreateLeadAction` + `CreateLeadData`: attach-or-create, gaps filled and nothing overwritten, contact with email/phone stored as given and never as verified, tags unioned, note, timeline, audit, `EnrichLeadJob` from `afterCommit`. _(Deliberately **not** `LeadDeduplicator` — it deletes the loser, and a hand-added lead outranks every provider, so it could take an established lead's notes and tasks with it. Recorded in the module doc.)_
3. [x] `LeadPolicy::create` (any member but Read-only), 2 web routes and `POST /api/v1/leads` — 201 created, 200 merged. _(With `work` rather than `import`: one lead at a time is ordinary work, a team's whole list at once is not. The API endpoint is where this mostly happens — the member with the business card is on a phone.)_
4. [x] The `leads/Create` screen, entry points on the leads hero and empty state, EN/AR at parity. _(One required field, first on the page; the team's existing tags offered; a non-dismissible note about whose details may be typed in; a link across to the CSV import for a whole list.)_
       **Data model:** none — no migration. `leads.source` is `string(32)` and `lead_activities.type` likewise, so both new enum cases fit.
       **Tests:** happy path (fields, stage, tags, note, timeline, audit, queued enrichment); merge into an existing lead; a hand-added company and the same company from a CSV collapsing into one; contact flags for a named person vs a role mailbox; validation; Read-only refused and a plain member allowed; another team's stage refused; API 201/200.
       **Acceptance:** a member can add a lead in seconds and it enriches like any other; gates pass; docs updated.
       **Risks:** a silent merge confusing the member (answered by the toast and the timeline's `merged` flag); duplicates keyed differently, which only enrichment catches.

---

### Phase 11 — Outreach (MVP)

**Goal:** AI-personalised drafts (email/LinkedIn text/call script), templates, tone controls, manual logging, and compliant email send — suppression/opt-out enforced.
**Depends on:** 10b, 5, 6 (profile). 15 recommended for full suppression; stub the check if before 15.
**Modules & docs:** Outreach (`outreach.md`), compliance.
**Tasks:**

1. [x] `outreach_templates`, `outreach_messages`, `contact_attempts` migrations — plus **`outreach_suppressions`**. _(The do-not-contact list is a real table rather than the stub task 4 allowed, because a stub that returns "not suppressed" is an opt-out silently ignored. Compliance (Phase 15) takes ownership of it. Hash + masked preview only: no column holds an address in the clear.)_
2. [x] `DraftMessageAction` → `outreach.draft_message` **v2** (channel + tone + language) → `MessageDraft` (variants); injection-safe. _(v2 adds the channel's required shape, the grounding rules, and the team's own brief as untrusted content. The grounding rules are the substance: an invented mutual connection in outreach gets **sent**, so the prompt names what is unknown rather than omitting it and forbids inventing a referral, a funding round, a metric or a case study. v1 stays seeded so a message recording `prompt_version: 1` is still traceable.)_
3. [x] Draft UI: generate, edit, **copy** (LinkedIn/call), manual **log**; **send email** via **Resend**. _(Not the team's own SMTP/mailbox: the envelope sender is the platform's verified address, named for the team — "Acme Ltd via Alima" — with the member as Reply-To. Sending as a domain we hold no SPF/DKIM for is how a tenant's mail lands in spam; per-tenant sending domains are post-MVP. **Copy is a first-class action, equal to Send** — for LinkedIn and call scripts it is the only one, and for a team with no verified sender it is how the work still gets done.)_
4. [x] **Suppression + opt-out + sender-ID checks at send time**, and the restriction at **draft** time. _(`OutreachGuard` is one gate asked twice — when the screen renders, and again the moment before transmission, because days may pass and a reply saying "stop" may arrive in between. Compliance blocks and configuration blocks are split: a prior-consent restriction or an opt-out stops a draft, while a missing sender or an unchosen recipient does not — a member can still write the message and copy it out. Logging "Asked us to stop" suppresses the contact's email **and** phone globally, in the same transaction.)_
5. [x] Bilingual drafting; log attempts to timeline + audit. _(One `Contacted` timeline case for all three channels and both routes, subjected to the attempt — so recording the outcome days later updates that entry rather than stacking a second one. Audit carries ids and enums only, never an address or a body.)_
       **Data model:** `outreach_templates`, `outreach_messages`, `contact_attempts`, `outreach_suppressions`.
       **Tests:** 86 feature + 25 unit. Draft generated (faked AI); **no credit spent on a draft the guard refuses**; send blocked when suppressed, when the domain is suppressed, when another team logged a global opt-out, when the lead is restricted, when there is no sender; a spent draft refused twice; a failed send left re-sendable; **two requests racing for the same draft sending once**; a claimed-but-unfinished send stranded rather than retried; the sent mail's sender ID + opt-out + `List-Unsubscribe`; tenancy throughout, including every API endpoint.
       **Acceptance:** ✅ drafts + copy + log + compliant send work; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 11 of mvp.md (Outreach MVP). Read outreach.md + compliance.md. Build templates/messages/attempts, DraftMessageAction, the draft UI with copy/log/send, suppression+opt-out+sender-ID enforcement, and tests with faked AI."_
       **Risks:** _(Both addressed. Deliverability is documented as a deployment concern — the sending domain must be verified in Resend or every send is rejected at the provider — and a `List-Unsubscribe` header is sent as well as the footer opt-out, which protects every other team's deliverability, not just the sender's. Suppression matches the address **and** its domain against the global list and the team's own in one indexed query, stores only a SHA-256 hash, exposes no hash over the API and offers **no lookup endpoint** — which would be an enumeration oracle over exactly the people entitled to be left alone.)_

---

### Phase 12 — Notifications

**Goal:** In-app (Reverb) + email notifications with per-user preferences and daily/weekly digests.
**Depends on:** 1, 2; consumers 7/9/10b/11.
**Modules & docs:** Notifications (`notifications.md`).
**Tasks:**

1. [x] Notifications table + preferences; broadcast via Reverb; mail channel; the bell in the app shell. _(**`team_id` on every row** — a notification belongs to one user but is about work in one team, and a member of three must not see the other two's. `data` holds structured values, never prose, so the sentence is composed at render time in EN or AR. The bell is a **shared Inertia prop**: it is shell, and a controller that forgot to load it would show a member zero unread while they had five.)_
2. [x] Notification types: discovery complete, **new A-grade leads (aggregated)**, task due, assignment, new signals, AI credit low. _(The aggregation is the feature: a discovery run can grade fifty leads a minute, and one notification per A grade would mean fifty emails and a member who switches the category off before lunch. `AnnounceTopLeadsJob` is `ShouldBeUnique` per team, so a burst collapses into one notification with a count and a couple of names. `credit_low` was built here **without a producer** — "running low" needs a usage figure, and `AiCreditGuard` only knows exhausted or not. **Phase 16 wired it**: Billing's `UsageAlerter` sends it at the configured thresholds, de-duplicated per team/metric/period/threshold, and the copy is now metric-aware so it covers the lead allowance too.)_
3. [x] Preference UI; digest scheduler (daily/weekly) — **plus push (FCM) and a mute**. _(Push was asked for and is built end to end: device registration, FCM **HTTP v1** with a service account, and deletion of tokens the provider permanently rejects — a kept dead token is a notification that silently goes nowhere, which nobody notices because nothing fails. The digest runs **hourly** and converts into each team's timezone, because the member picks an hour and the team picks a clock. The mute silences email and push and leaves the bell alone.)_
       **Data model:** `notifications`, `notification_preferences`, `notification_settings`, `push_devices`.
       **Tests:** 71 feature + 23 unit. Dispatched + stored with keys rather than prose; a preference suppresses a channel and an unset one falls back to the type's defaults; the mute leaves the bell alone; an admin-only type never reaches a plain member however their preferences read; a member who has left stops being notified; the aggregation collapses a burst; task reminders send once and are stamped; the digest fires on the team's clock, skips Tuesday for a weekly, won't send twice in a window, and sends nothing when nothing happened; a rejected push token is deleted; **the broadcast event name, payload and private channel**; tenancy throughout, including every API endpoint and the per-team isolation of preferences, settings, the digest builder, the aggregating job and the prune sweep.
       **Acceptance:** ✅ in-app + email + **push** + prefs + digest work; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 12 of mvp.md (Notifications). Read notifications.md. Build notifications + preferences + Reverb broadcast + email + digests, wire the key producers, and test."_
       **Risks:** _(Both addressed. Reverb uses Laravel's own per-user channel (`App.Models.User.{id}`), which `routes/channels.php` already authorises — a notification is addressed to one person, and `teams.{id}` would show one member's reminders to everybody. Timezone correctness is the digest command's central design: hourly sweep, converted per team before the hour is compared, with a half-window idempotency gap for a retry or a DST fold.)_

---

### Phase 13 — Reporting & Analytics

**Goal:** Dashboard KPIs, funnel, source/ICP performance, team activity, AI usage, date filters, CSV export.
**Depends on:** 9, 10b, 11, 5.
**Modules & docs:** Reporting & Analytics (`reporting-analytics.md`), `dataviz` skill.
**Tasks:**

1. [x] Aggregation services (optional rollup tables) over leads/scores/pipeline/outreach/AI usage. _(2026-10-30: `DashboardReportBuilder` + **11** aggregation Actions reading leads/scores/pipeline/signals/outreach/the lead timeline/AI usage live. **No rollup tables** — live aggregation is fine at MVP volumes and `report_rollups` stays the documented escape hatch; nothing above the builder would change to add it.)_
2. [x] Dashboard: KPIs, funnel by stage, source performance, ICP performance, team activity, AI credits. _(2026-10-30: KPIs, the funnel — now with its **contacted and replied** halves off `contact_attempts` — an adaptive sourced-vs-qualified trend, the A–D fit mix, breakdowns by pipeline stage / lead source / signal event type, and four tabular reports in a tabbed Performance section: **ICP performance**, **outreach performance** (by channel, with reply rates), **team activity** (per member, off the lead timeline) and **AI & data usage** (per prompt, with cache-hit rate and cost).)_
3. [x] Date-range filters; **CSV export** of any report; charts per the `dataviz` guidance. _(2026-10-30: one date filter scoping the whole screen — seven presets plus a custom pair, cut in the team's timezone, server-driven so a shared URL reproduces what the sender saw; the trend's bucket width follows the window (daily/weekly/monthly). CSV export of **all eleven** reports through one writer, because `ReportKey` is a closed set and every report must project to a `ReportTable` — audited, rate-limited, BOM'd for Excel, formula-neutralised, translated.)_
       **Data model:** optional `report_rollups` — **not added** (see task 1).
       **Tests:** aggregation correctness; date filter; CSV export; tenancy. _(36 Reporting tests: window resolution, DST and the previous-window comparison, bucket clamping, the cohort rule, contacted-counts-leads-not-attempts, an opt-out counting as a reply, a null rate never rendering as zero, every report exporting, the audit entry, Read-only access, Arabic headers, and cross-tenant isolation per report.)_
       **Acceptance:** ✅ dashboard accurate + exportable; gates pass (Pint, Larastan 7, 1162 Pest tests, `npm run check` + `types:check` + `build`); docs updated.
       **Dispatch prompt:** _"Execute Phase 13 of mvp.md (Reporting & Analytics). Read the module doc; load the dataviz skill for charts. Build the aggregation + dashboard + filters + CSV export, and tests."_
       **Risks:** _(Both addressed. **Aggregation performance:** an export deliberately runs the whole dashboard so the download cannot disagree with the screen, which makes it the first thing that will feel slow — it is metered by a per-member `reports-export` limiter, and `report_rollups` is the documented next step. **Chart accessibility:** every chart keeps its screen-reader table, the new report tables use a real `<th scope="row">` per row, the trend is keyboard-navigable, and the filter marks its active preset with a tick as well as a highlight, so nothing is colour-alone.)_
       **One judgement worth recording:** the window selects a **cohort of leads**, and every
       lead-derived figure reports on that cohort (so "contacted" is _of the prospects sourced in this
       window, how many have we approached_, and the funnel stays monotonic). Activity reports — AI
       usage, team activity, outreach performance — are scoped by their own event time instead. One
       rule with its exceptions written down, because a dashboard whose charts disagree is worse than
       no dashboard. See `docs/modules/reporting-analytics.md` → Key flows.

---

### Phase 14 — AI Insights (light)

**Goal:** Stored, dismissable insights — stale-lead alerts and a daily "leads to contact today" digest.
**Depends on:** 13, 9, 5.
**Modules & docs:** AI Insights (`ai-insights.md`).
**Tasks:**

1. [x] `insights` table; `GeneratePeriodicInsightsJob` (scheduled) → `insights.periodic_digest`. _(Plus
       **`insight_runs`**, which the plan didn't ask for and the feature doesn't work without: the
       sweep has to know a team has been done even on a day that produced no insights, or it re-runs
       — and re-pays for the aggregates and the AI call — every hour until midnight. `insights.periodic_digest`
       v1 was refined in place and is now live; it had been seeded a phase early with no caller.)_
2. [x] MVP insight types: stale-lead alert, "contact today" list; feed into Notifications. _(A lead
       goes stale only once the team **started** it — approached, assigned, or moved off the default
       column — because "any lead with no recent activity" makes four hundred alerts out of one
       week's sourcing. "Contact today" asks `OutreachGuard` per candidate and takes the answer as it
       comes: a suppressed or prior-consent company stays on the list marked introduction-only rather
       than disappearing or being offered an email. `NotificationType::InsightsReady`, one per run
       with the counts, plus a section in the notification digest.)_
3. [x] Insights feed UI (dismiss); (one-click ICP apply deferred to post-MVP). _(EN/AR, Open and
       Dismissed tabs, severity-first ordering, and `/api/v1/insights` for the mobile app. The
       `applied` status is **not** in the enum: nothing can reach it until the apply action exists.)_
       **Data model:** `insights`, `insight_runs`.
       **Tests:** job generates insights (faked AI); dismiss; digest notification; tenancy. _(88 tests:
       plus the policy asked directly per role and across teams, the staleness boundary an hour either
       side of a fortnight, a forced run that doesn't pay the model twice, the quiet-week skip and the
       collapsed-week case that must not be skipped, and the retention sweep.)_
       **Acceptance:** daily digest + stale alerts delivered and dismissable; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 14 of mvp.md (AI Insights light). Read the module doc. Build insights + the scheduled digest job + feed UI, wire Notifications, and test with faked AI."_
       **Risks:** keep AI cost bounded (scheduled, cached); avoid noisy insights. _(Both landed on real
       decisions. **Cost:** one call per team per day at most, skipped outright for a quiet week, skipped
       again when the day already has a digest, capped at three items, and switchable off
       (`INSIGHTS_AI_ENABLED`) without losing the feature — the deterministic insights cost nothing and
       cannot fail, so a provider outage costs the feed its commentary, not its contents. **Noise:** the
       stale rule excludes unworked and closed leads, the call list is five and compliance-gated, and a
       dismissal is never resurrected by the next run.)_

---

### Phase 15 — Compliance & Privacy features

**Goal:** System-enforced suppression (global+team), opt-out, retention purge, LIA record, team export, privacy notice, basic DSAR.
**Depends on:** 7, 8, 10b, 11.
**Modules & docs:** Compliance & Privacy (`compliance-privacy.md`), `docs/compliance.md`.
**Tasks:**

1. [x] `suppressions` (global+team, identity hash) checked by Outreach; opt-out writes **global**. _(**No new table.** Phase 11 had already built `outreach_suppressions` with both scopes, the hash-only storage and the send-time checks, and `docs/compliance.md` §3.2 recorded that this phase would "take ownership of the table". A second do-not-contact list would be a split list, and a split list is an opt-out silently ignored — so ownership here means the guarantees, not a physical move. What Phase 15 added over it: the **hosted one-click opt-out** (below), the `erasure` reason, and the rule that **the reason as well as the scope decides whether an entry may be lifted** — a team-scoped erasure safeguard that a team could remove with a button would be no safeguard.)_
2. [x] `retention_policies` + `PurgeExpiredDataJob` (scheduled, audited). _(Nightly at 03:40, after the per-module sweeps, queueing **one job per team** so one backlog cannot hold up everybody else's promise. **Two branches, and the split is the policy:** an untouched lead is deleted, a worked-and-quiet one is anonymised — the personal data goes, the company and one row per approach stay, because that residue is the team's own evidence of who they contacted. The two are exact negations, so no lead falls between them and is kept for ever while nothing fails. `leads.anonymised_at` makes it idempotent; `--dry-run` and the screen's counts come from the same rule the sweep applies, because retention is irreversible.)_
3. [x] **LIA record** gating discovery; market-aware outreach consent gate (UK LI vs UAE prior-consent). _(The gate **refuses** rather than warning: the assessment is the basis on which a sourced lead is allowed to exist, and the Phase 7 contract meant swapping a binding rather than changing the Action. One record per (team, market), signed off by a named person, **expiring** after twelve months. The consent gate went **wider than the task's wording** on purpose — it reads the prospect's own `country` as well as the team's market, because the recorded flag is only ever set by the module that created the lead, so a UAE company sourced by Lead Sourcing carried none and could have been cold-emailed. Strictest wins and the answer can only get stricter; a known UK country never lifts a restriction.)_
4. [x] **Team data export**; hosted **privacy notice**; DSAR locate+erase (MVP-light, audited). _(Export: a ZIP of twelve CSVs, BOM'd and formula-neutralised, do-not-contact masked, audited **before** the file is sent. Notice: public and bilingual, written for somebody who has just been cold-emailed, and linked automatically when none is configured. DSAR: per team, exact on an address or number and **candidates on a name** — the erasure takes only the rows the member ticked — and it **suppresses the identity in the same transaction**, which is the part that makes an erasure stick. **Plus the hosted one-click unsubscribe** `docs/compliance.md` §3.3 had parked here: RFC 8058 with `List-Unsubscribe-Post`, a signed URL, and a GET that deliberately does nothing because scanners fetch every link in an email.)_
       **Data model:** `retention_policies`, `lia_records`, `leads.anonymised_at`. `suppressions` is Outreach's `outreach_suppressions` — see task 1.
       **Tests:** 107 feature + 4 unit. The gate refused for a missing, draft, expired and withdrawn assessment and keyed on the team's own market; the two retention branches including what each one _keeps_, the preserved restriction, the deleted signals, idempotence, the dry run, a disabled policy, a team on the defaults, and **a sweep run with another team's context active** (the delete branch's subqueries would otherwise inherit it); DSAR by address, number and name, the retained suppression entry, a name containing a `LIKE` wildcard, erasure confined to the confirmed rows, and the suppression written inside the transaction; the export's contents, masking and metering; the signed unsubscribe, the inert GET, the global suppression of email **and** phone, and the already-anonymised case; the market gate across jurisdictions; tenancy per model and per screen.
       **Acceptance:** ✅ compliance enforced by the system; gates pass (Pint, Larastan 7, 1369 Pest tests, `npm run check` + `types:check` + `build`); docs updated. ⚖️ legal review flagged — see below.
       **Dispatch prompt:** _"Execute Phase 15 of mvp.md (Compliance & Privacy). Read compliance-privacy.md + docs/compliance.md. Build suppression (global+team), opt-out, retention purge, LIA gate, team export, privacy notice, and light DSAR, with tests. Flag items needing legal review."_
       **Risks:** _(Both landed on real decisions. **Legal correctness** is the one thing this phase cannot finish: the privacy-notice wording, the retention defaults, whether an erasure's suppression should bind every team or only the controller it was made to, DSAR identity verification for people who are not our users, and what evidence could lift a UAE prior-consent restriction per lead are all ⚖️ and listed in `docs/modules/compliance-privacy.md`. The notice particulars and the retention defaults are in `config/compliance.php` precisely so counsel can change them without a deployment touching code. **Retro-fitting the gates** cost nothing, because the hooks did exist: `LeadInterestGate` was built in Phase 7 as a contract with a permit-everything binding, and this phase swapped it. The one that did **not** exist was the outreach restriction — Phase 11 read `leads.raw` directly — so a `LeadRestrictionResolver` seam was added to Outreach with its own default binding, keeping that module standing on its own.)_
       **One judgement worth recording:** an **erasure writes a suppression**, and a **do-not-contact entry is never erased**. Both look backwards at first glance and are the same point: the register exists to protect the person, holds a one-way hash rather than an address, and removing it is what would harm them. Without the first, the next discovery run re-sources the same company and the person who asked to be forgotten hears from us again.

---

### Phase 16 — Billing & Plans

**Goal:** Stripe/Cashier plans with trials, seats, lead + AI credit allowances, overage, portal, and enforcement.
**Depends on:** 1, 5, 7.
**Modules & docs:** Billing & Plans (`billing-plans.md`).
**Tasks:**

1. [x] `composer require laravel/cashier`; Cashier wiring; plans config (tiers, prices, allowances, seats). _(Cashier `^16.9`, with the **team** as the billable party — its customer columns are on `teams` and `Cashier::useCustomerModel()` points at `Core\Models\Team`. One person may own two teams on two plans; a `stripe_id` on `users` breaks the moment that happens, ADR 0006. Five plans in `config/billing.php`: a **Free** fallback the plan didn't list — a team whose trial lapses has to land somewhere, and "locked out until you pay" loses the customer who was about to — Starter £49/1 seat, Team £129/3, Scale £379/10, and Enterprise on request. **The Stripe products don't exist yet**: every price id is a readable placeholder, overridable per environment, and the whole module is tested against a fake gateway.)_
2. [x] `UsageMeter` (leads + AI credits per period); replace the stubbed guards in AI Platform + Lead Sourcing with real `EnforcesPlanLimits`. _(**`usage_meters` is a record, not a counter.** The authoritative numbers already exist — `ai_requests.cost_micros` and `discovery_runs.leads_created` — and the guards read those directly, so a limit can never be wrong because a worker died between doing the work and incrementing a row. The table is for the two jobs the ledgers can't do: remembering what a closed period cost after Phase 15's retention sweep has pruned it, and remembering in `reported` how much overage Stripe has already been told about. **`EnforcesPlanLimits` is a concern, not middleware**, deliberately: three of the four places a limit has to bite — a queued discovery job, a scheduled signals watch, the nightly insights — have no request behind them, so a middleware would guard the one case that's cheapest to guard and miss the three that run up a bill. It is consulted by the Phase 5 and Phase 7 guards instead, and **neither guard nor any caller of them changed** — which is what those seams were built for, and why the tests go through the guards rather than through Billing's own services.)_
3. [x] Trials; metered **overage**; Stripe customer portal; webhooks sync. _(A 14-day card-less trial of the Team plan's entitlements, started by a `Team::created` listener in Billing's provider rather than a line in `Auth\Actions\CreateTeamAction` — the length of a trial is a billing decision, and a team created by a seeder or a factory should start on the same footing. Overage is **claimed before it is reported**: `usage_meters.reported` is advanced in a transaction, then Stripe is called, then the claim is released on failure. A crash between the two loses the revenue for those units and leaves a loud discrepancy; the other order would bill a customer twice, and only one of those takes money from somebody who didn't agree to it. A **hard ceiling** at twice the allowance, because an unbounded metered plan is a way to hand somebody an invoice whose first warning is the invoice. Webhooks are handled by **listening to Cashier's `WebhookHandled`** rather than subclassing its controller, so it keeps its route, its signature verification and its handlers.)_
4. [x] Billing settings UI (web only); invoices. _(Top-level `/billing` in the sidebar's Account group beside Compliance, not in the settings pane: a price list of five plans beside two usage meters doesn't fit a 36rem column built for forms, and the plan is **every member's to read** — when discovery stops on a Tuesday afternoon, the person it stopped for should see that the month's allowance is gone rather than file a bug. Spending money stays with Owners and Admins. Invoice PDFs are Stripe's own signed links; the fetch **degrades** if Stripe is unreachable, so an Owner looking for their usage doesn't get a payment-provider error instead. `NotificationType::CreditLow`, built in Phase 12 with no producer, is now wired and metric-aware — "new searches stop" and "scoring and drafting stop" are different pieces of news.)_
       **Data model:** Cashier tables (keyed on `team_id`); `usage_meters`; four columns on `teams`.
       **Tests:** plan limits block over-quota; trial; webhook sync (faked Stripe); seat enforcement on invite; tenancy.
       **Acceptance:** ✅ subscriptions + credits + enforcement work with Stripe faked (79 feature tests against `FakeBillingGateway`); gates pass (Pint, Larastan 7, 1,448 tests, `npm run check` + `types:check` + `build`); docs updated.
       **Dispatch prompt:** _"Execute Phase 16 of mvp.md (Billing & Plans). Read billing-plans.md. Install Cashier, define plans/allowances/seats, build UsageMeter + real EnforcesPlanLimits, overage, portal, webhooks, UI, and tests with Stripe faked."_
       **Risks:** Stripe test-mode setup; VAT (UK/UAE) deferred (⚖️); credit→cost mapping to protect margin.
       **Outcome:** Seats turned out to be the subtlest part. The pricing is a flat price _including_ N seats rather than a per-seat rate, so the subscription quantity stays 1 and a team needing a fourth seat changes plan — which meant the seat rule is a licence check with no overage, unlike leads and credits. It needed a **new seam in Auth** (`Contracts\SeatLimitSource` + an unlimited default), because Auth must not know what a plan is, and `Services\SeatCounter` on Auth's side to answer who occupies a seat. Two things fell out of that: **a pending invitation holds a seat** (counting only accepted members would let a three-seat team send ten invitations and surface the problem to the tenth person, who didn't cause it and can't fix it), and **the check runs again on acceptance** — an invitation is valid for a week, and in that week the team may have downgraded or filled the seat. The invitation is refused rather than consumed, so it still works once a seat is freed. Allowances are measured over the **calendar month in UTC**, not the Stripe billing cycle: anchoring them to the invoice date would move the reset day whenever a plan changed and put a round trip to Stripe behind every usage query. **The review earned its keep.** `laravel-module-reviewer` found that the overage claim was an unconditional `increment()` computed from a stale read (two reporters could both send the same units), that Cashier stamps a fresh UUID on every meter event (so a timeout after Stripe accepted one would double-bill on retry), that releasing the claim on _any_ failure made that worse, that re-syncing a closed month would overwrite it from pruned ledgers and the current plan's allowance, and that a singleton `PlanResolver` would hold a stale plan for the life of a queue worker. All five are fixed and covered; the fixes are the parts of §6 worth reading. `brand-reviewer` found a sub-3:1 hairline, a destructive-variant button on a _fix_ action, five filled CTAs where the book allows one, a pending `info` token used as a status, and jargon in the copy. **⚖️/🔑 outstanding:** the Stripe products and prices must be created and the `STRIPE_PRICE_*` keys set before a real payment is possible (the API keys and the webhook secret are already in `.env`, in test mode), the figures above need signing off, and VAT stays deferred — all three are listed in `billing-plans.md`.

---

### Phase 17 — API v1 hardening

**Goal:** A consistent, documented, rate-limited `/api/v1` ready for mobile.
**Depends on:** all feature phases that expose endpoints.
**Modules & docs:** all; `docs/api/README.md`; ADR 0001, **ADR 0013**.
**Tasks:**

1. [x] Audit every `/api/v1` route: API Resources only, consistent error envelope, pagination, token abilities, rate limits. _(**The audit is a test, not a document** — `tests/Feature/Api/V1/RouteContractTest.php` asserts over the whole route table that every endpoint is versioned, named, behind Sanctum, throttled, version-stamped, scoped with an ability the enum defines, handled by a controller rather than a closure, and present in the committed specification. A per-endpoint test only covers the endpoints somebody remembered to write one for. What it found and this phase fixed: the error envelope was the contract for **domain exceptions only** — everything else leaked Laravel's defaults, so a client had three shapes to parse and which one it got depended on which layer refused; token **abilities had been stored since Phase 1 and never checked**, so "read-only integration" was a promise the API could not keep; `per_page` meant three different things and two genuinely unbounded lists returned whole (discovery runs, the do-not-contact list); the global limiter keyed on the **user** rather than the token, so a script's retry loop could exhaust the allowance the phone in someone's pocket was using; and `GET /api/user` was still there from the starter kit, unversioned and returning the raw model. **Also found: Reporting had no API at all**, which would have stopped the mobile home screen dead, so `GET reports/dashboard` was built here — the endpoint `DashboardRequest` and `DashboardReportBuilder` were both written in anticipation of.)_
2. [x] OpenAPI/Scramble docs; version header policy; shared TS types in `resources/js/shared/`. _(The specification is **generated, not described** — `docs/api/openapi.json` from the routes, FormRequests and Resources themselves (`composer api:docs`), with `docs/api/README.md` as the prose beside it. Scramble is a **dev** dependency: the document is a build artefact, so production has neither the generator nor the `/docs/api` route, and `config/scramble.php` references no Scramble class because every config file is loaded on boot. A transformer writes in what the generator cannot see: the error envelope, which lives in middleware rather than in any return type, and a stable relative server URL. **The shared TS types were moved, not generated.** `resources/js/types/*` already mirrored the resources and carries what a specification cannot express — which `label` fields are translation keys (ADR 0007), which unions are discriminated — so generating a second set would have meant two sets that drift; the payload types moved to `resources/js/shared/types/` with `@/types` re-exporting them, so no web import changed, and `resources/js/shared/api/` adds the envelope, the pagination shapes and a typed `fetch` client beside them.)_
3. [x] Contract tests for the endpoints the mobile app needs. _(`tests/Feature/Api/V1/` — the envelope for every status, the scopes with **real bearer tokens** (`Sanctum::actingAs()` sends no credential, so a scope test written with it passes without checking anything), the version header and pin, the pagination shapes and ceilings, and `MobileJourneyTest`, which walks Phase 18's whole journey — sign in, dashboard, list, detail, stage move, note, task, channel decisions, AI draft, logged call, push, bell, sign out — over **one** token. Every step is covered in its own module's suite already; what the journey pins down is that they **compose**, which is the thing a mobile build otherwise discovers the hard way.)_
       **Data model:** none.
       **Tests:** auth/validation/tenancy/pagination per endpoint; error envelope shape.
       **Acceptance:** API consistent + documented + covered; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 17 of mvp.md (API v1 hardening). Audit all /api/v1 endpoints for resources/errors/pagination/rate-limits/abilities, generate API docs, export shared TS types, and add contract tests."_
       **Risks:** drift between web and API behaviour — both must call the same Actions.

---

### Phase 18 — Mobile app (Capacitor + Ionic Vue)

**Goal:** The `/mobile` app: auth, dashboard, leads list/detail, pipeline moves, notes/tasks, log call/email, AI draft, push.
**Depends on:** 17, 12.
**Modules & docs:** Mobile App (`mobile-app.md`).
**Tasks:**

1. [x] Scaffold `/mobile` (Capacitor + Ionic Vue + TS) importing `resources/js/shared/` via alias. _(Its own npm project — Capacitor resolves platform packages from the nearest `package.json`, and nothing here is served by Laravel. Two Vite aliases do the sharing: `@shared` → `resources/js/shared/` and `@lang` → `lang/`. The master logo path **moved** to `shared/brand/artwork.ts` so one mark serves both apps; the Arabic bundle is lazily imported and `en.json` is not imported at all, because it is an identity map and `t()` falls through to the key.)_
2. [x] Token auth (+2FA), dashboard KPIs, leads list/detail, pipeline move, notes/tasks, log attempt, AI draft. _(The 2FA exchange was the open backend item and is this phase's only server-side work: `POST /auth/token` now settles a two-factor account in **one** request — `423 auth.two_factor_required`, then the same credentials plus `code` or `recovery_code` — because a phone has neither Fortify's session nor its redirect. The password is checked before anything reveals that the account has a second factor, so the 423 is never a way to probe which accounts do. Also found and fixed on the way: `exposed_headers` was empty in the framework's CORS defaults, so cross-origin the shared client could not read `X-Api-Version` or a 429's `Retry-After` — unreadable rather than missing, and only ever on a non-same-origin caller.)_
3. [x] **Push** via FCM/APNs wired to Notifications; RTL/Arabic. _(The server end was already built in Phase 12, so this is the handshake: permission on a deliberate tap in Settings, never at launch; the token posted from the OS's own async `registration` listener, so a rotated token is sent up too; deregistration best-effort at sign-out, because a handset with no signal must still be able to sign out of itself. RTL is `dir` on the root element, which is what flips Ionic's chrome and not just the text — with the logo forced LTR and identifiers isolated inside the Arabic layout.)_
4. [x] iOS + Android build configs. _(Both native projects committed. **Android runs**: brand colours, the launch screen, the adaptive launcher icon and the notification icon are vector drawables generated from the master logo path by `npm run artwork`, plus the `POST_NOTIFICATIONS` permission and a **debug-only** manifest for cleartext HTTP, so a release build cannot send a token over plain HTTP however the URL is set. **iOS is as far as Windows goes**: the Xcode project, the two APNs callbacks in `AppDelegate.swift` that Capacitor cannot add for you, `en`+`ar` in `Info.plist`, and a brand launch screen — `pod install`, the Push Notifications capability and the signing team all need a Mac, step by step in `docs/runbooks/mobile-dev.md` §6.)_
       **Data model:** none (consumes API).
       **Tests:** shared code unit tests; API contract reuse; smoke e2e where feasible. _(30 vitest tests in `mobile/src/lib/`: the shared API client's contract — every case mirroring a sentence in `docs/api/README.md` — the formatting layer, and the language layer. The client had no tests before; Phase 18 is the first thing to depend on it. Server-side, the 2FA exchange and the CORS headers are covered, and `MobileJourneyTest` already walked the whole journey over one token.)_
       **Acceptance:** app runs on both platforms against `/api/v1`; push works; gates pass; docs updated. _(**Partly deferred, and knowingly:** Android runs; iOS cannot be built from Windows, which is Xcode's licence rather than a configuration problem. Push is wired end to end but needs a Firebase project to exercise — the credentials are the user's to create. And the icon rasters are still Capacitor's placeholders: everything derivable from the vector path is generated, but a store submission needs a rasterised export of the signature, which is a design deliverable. All three are recorded in `docs/modules/mobile-app.md`.)_
       **Dispatch prompt:** _"Execute Phase 18 of mvp.md (Mobile app). Read mobile-app.md. Scaffold /mobile with Capacitor + Ionic Vue reusing shared code, build the core screens against /api/v1, wire push, and set up iOS/Android builds."_
       **Risks:** FCM/APNs credentials + Apple/Google accounts; Capacitor native build on Windows (may need macOS for iOS).

---

### Phase 19a — Admin panel: foundation, identity & cross-tenant CRUD

**Goal:** A bespoke `/admin` Inertia panel on a platform-admin identity that can read and write **every** resource in the product from outside team scope — with every cross-tenant read and write audited, and impersonation that cannot do a customer harm.
**Depends on:** 1 (teams/roles), 13 (reporting primitives), 15 (compliance actions), 16 (billing). **No longer 20:** this phase builds its own failed-jobs and provider-health views rather than waiting for Horizon/Pulse, which cannot run on Windows at all (CLAUDE.md §1) — so a Horizon link would be a dead link in the one environment it is developed in.
**Modules & docs:** new **Admin** module (`docs/modules/admin.md`, rewritten from `_TEMPLATE.md`), **ADR 0014** (supersedes ADR 0009).
**Tasks:**

1. [x] **ADR 0014 — bespoke Inertia admin; mark ADR 0009 `Superseded by 0014`.** No Filament, and the reasons are worth writing down rather than inheriting: it brings a Livewire/Blade stack alongside Inertia/Vue, which is a second asset pipeline, a second theming surface to keep brand-compliant (§2a) and a second place tenancy can leak; its generated screens **write straight to models**, which is precisely the second, unvalidated write path the Action/transaction/audit rules in §2 exist to prevent; and the four requirements that actually make this panel hard — plan-driven feature flags, manual billing, provisioning, platform margin reporting — are bespoke under any framework. The free CRUD was the cheap part.
2. [x] **The platform identity.** `users.admin_role`, nullable, cast to `Admin\Enums\AdminRole`: `super_admin`, `support`, `finance`, `read_only`.
    - **Not a `TeamRole`** — those are team-scoped by definition and a platform operator belongs to no tenant. **Not a second guard over a second table** either, which is what ADR 0009 implied: it would fork 2FA, passkeys, password resets and the audit trail, and what you want is one person, one credential, one trail.
    - **Four roles, not a boolean**, because reading a tenant's prospect list, impersonating a customer, granting a plan and turning off Stripe are different powers. An `Admin\Enums\AdminAbility` enum with a gate per ability; each role maps to an ability set in `config/admin.php`.
    - **A platform admin must have confirmed 2FA.** `EnsureAdminTwoFactor` sends an operator without it to enrolment and nowhere else. One super-admin credential reads every tenant's data — a password alone is the wrong lock, and this is cheaper to enforce now than to retrofit.
    - Optional `ADMIN_IP_ALLOWLIST`; `/admin` gets its own rate limiter, separate from the app's.
3. [x] **Crossing the tenancy boundary deliberately.** The property that makes this phase dangerous: `BelongsToTeam`'s global scope is a **no-op when no team is active**, so "just don't set a team" silently unscopes every tenant model in the product. That is exactly what an admin index needs and exactly what must never happen anywhere else.
    - `Admin\Http\Middleware\PlatformContext` clears `TeamContext` for the request and asserts the route is an admin route. Drilling into one tenant uses the existing `TeamContext::run($team, …)`, so a per-team admin screen sees exactly what that tenant sees — same scope, same policies, no bespoke second query path to keep in sync.
    - **The audit is a route-table test, not a document** (Phase 17's precedent, `RouteContractTest`): `AdminRouteContractTest` asserts over the whole table that every route named `admin.*` is behind `auth`, the admin-ability middleware, the 2FA middleware and `PlatformContext`, is named, and is handled by a controller rather than a closure — **and that no route outside `admin.*` uses `PlatformContext`.** One forgotten middleware on one route is the entire vulnerability, and a per-route test only covers the routes somebody remembered.
    - **Admin is web-only, by decision:** no `/api/v1/admin`. A token that can read every tenant is a liability no MVP ops task needs, and it would have to be scoped, versioned and specified like everything else in ADR 0013.
4. [x] **Cross-tenant reads are audited.** `AuditLogger` records writes today; an operator opening a tenant's leads is a **privacy event** and must leave a row (`admin.resource.viewed` — resource, filters, target team, result count; ids and counts only, never a name or an address).
    - **The subtle bug to get right first:** `AuditLogger` stamps `team_id` from `TeamContext`, which this panel has deliberately cleared. Without an explicit target team on the admin path every admin entry lands with `team_id = null` and the per-tenant audit view — the one a customer's support ticket is answered from — shows nothing.
5. [x] **The resource registry — CRUD over everything, without 45 bespoke screens.** One declarative `Admin\Resources\<Name>Resource` descriptor per model: model, labels, columns (reusing Reporting's `ColumnFormat`), searchable fields, filters, default sort, eager loads, form fields with rules, which actions are permitted, which `AdminAbility` each needs, and whether it is tenant-owned or platform-wide. Generic `Index`/`Show`/`Create`/`Edit` Inertia pages render from the descriptor.
    - Descriptors live in **Admin**, not in each module. The inverse scatters an operator-tooling concern across eighteen modules and makes "what can an operator see and change?" unanswerable without reading all of them. Admin is the one module whose job is to know everything — but it knows **models and Actions**, never another module's internals.
    - **The rule that keeps it from becoming a second write path:** where a module owns the operation, the admin calls that **Action**. Plan change → `Billing\Actions\SwapPlanAction`. Member removal → `Auth\Actions\RemoveTeamMemberAction`. Erasure → Compliance's. Suppression → Outreach's. Generic create/update covers the rest, and **generic delete is opt-in per descriptor and off by default**: a tenant row deleted behind the Actions bypasses the transaction, the cascade and the audit entry the product depends on.
    - Coverage, so "all resources" is a checklist and not an aspiration: teams + members + invitations, users, business profiles, ICPs + criteria, discovery runs, leads + contacts/scores/notes/tasks/activities/tags/assignments, saved views, pipeline stages, signals + watches + runs, outreach templates/messages/attempts/suppressions, notifications + preferences + push devices, insights + runs, AI prompt templates, AI requests, BI reports, retention policies, LIA records, subscriptions + usage meters, audit logs — plus 19b's platform settings, plan grants, capability overrides and announcements.
    - Every index paginated and `preventLazyLoading`-clean. A cross-tenant index over `leads` is the **only** query in this product with no `team_id` in it, so the indexes it needs are checked here — and MySQL is where that bites, not SQLite.
6. [x] **User & team operations.** Reset password (email a link — never set one for them), force-reset 2FA, revoke sessions, revoke API tokens, verify email, transfer team ownership, remove a member, anonymise or delete a user **through Compliance** rather than a raw delete.
    - **Team suspension** — `teams.suspended_at` + `suspension_reason`. A suspended team's members can still sign in and read their own data; they cannot source, send or spend. Suspension is an abuse and non-payment lever, and locking people out of data they own is both the wrong lever and a compliance problem.
    - Enforced in the **guards**, not middleware — ADR 0012's argument unchanged: a queued discovery job and a scheduled signal watch spend a team's allowance with no request behind them.
7. [x] **Impersonation.** `Admin\Services\Impersonator`; start/stop; the session carries the operator id; a brand-compliant banner on **every** tenant screen (`status.*` — Signal Orange is decorative only, §2a); time-limited (`ADMIN_IMPERSONATION_TTL`, default 60 minutes) and ended by sign-out; **an admin may never impersonate another admin**; start and stop audited, and every write during the session carries the operator id in `properties`.
    - **The hard rule:** an impersonated session may not do anything that leaves the building or that the customer cannot undo — no outreach send, no checkout or plan change, no erasure, no token issue, no password or email change. An operator in a customer's seat emailing a prospect is a message the customer did not write, from their domain, to a real person — and it would land in their suppression and consent record as though they had.
    - `super_admin` and `support` only; `finance` and `read_only` never.
8. [x] **Admin chrome, inside the brand.** Its own `AdminLayout` and nav, deliberately distinct so an operator never mistakes it for a tenant screen — achieved **within** the brand (Deep Ink chrome, DM Mono labels, cream content), never by inventing a colour. Reuses the existing base components. **English-only by decision**: an internal tool carries no AR/RTL obligation, and saying so is the difference between a decision and an omission. Run the `brand-reviewer` agent on it anyway.
9. [x] **The super-admin seeder.** `SuperAdminSeeder` — `super@admin.com` / `password`, `AdminRole::SuperAdmin`, email verified, and **no team membership and no `current_team_id`**, so the panel is exercised the way an operator actually holds it. Idempotent: `firstOrCreate` on the email, refreshes the role, and never resets a password somebody has changed.
    - **It refuses to run when `app()->isProduction()`** unless both `--force` and an explicit `ADMIN_SEED_ALLOW_PRODUCTION=true` are given. A seeder that plants a known password on a public host is not a convenience, it is the breach.
    - Registered in `DatabaseSeeder` ahead of `DemoSeeder`. The seeded account has no 2FA, so its first `/admin` visit is the enrolment screen — that is the correct local experience, and it belongs in the runbook rather than as an exception in the middleware.
      **Data model:** `users.admin_role`; `teams.suspended_at`, `teams.suspension_reason`. No new tables. _(Two seams were added that the plan did not anticipate, both in other modules and both for the same reason — the thing Admin needed could not be reached from Admin. **Core gains `Support\AuditContext`**, an ambient property bag `AuditLogger` merges in, because an impersonated session's writes go through the owning module's Action and there is no layer between that Action and the logger to annotate; teaching eighteen modules about impersonation was the alternative. **Outreach gains `Contracts\TeamSendingGate`** with a permissive default, because binding over `LeadRestrictionResolver` — the one-line shortcut — would have had the guard tell a member that *this prospect* may not be approached, which is a false statement about somebody else's legal position written into the team's compliance record.)_
      **Tests:** unauthenticated, non-admin and each **lower** admin role blocked from every admin route and every forbidden action; the route-table contract test; a cross-tenant index returns rows from more than one team **and** a per-team drill-down returns exactly one team's; admin reads and writes audited with the **target** team id; impersonation audited start and stop, banner rendered, the forbidden-action list refused, admin-on-admin refused, expiry ends the session; suspension blocks sourcing, sending and spending — including from a queued job — but not sign-in or read; generic delete refused where the descriptor forbids it; the seeder is idempotent and refuses production.
      **Acceptance:** an operator can find any record in the product, change what their role permits, and impersonate with a trail; nothing in the panel bypasses an Action that already exists; gates pass; docs updated. _(**Met, with three things recorded rather than glossed.** (1) **Create and delete are narrower than “opt-in per descriptor” suggested**, and the rule turned out to be sharper than the plan's: create is permitted only where **every column the table requires is a declared field**, which admits `icps` and `outreach-templates` and excludes `lead-tags`, whose `slug` is derived by `LeadTag::normalise()` — and deriving a column is logic a declarative descriptor must not grow. Delete is on three resources (`lead-tags`, `saved-views`, `push-devices`), and the contract test pins that list so changing it is a failing test rather than a quiet diff. (2) **Platform-user deletion is deferred**, deliberately: the plan said “anonymise or delete a user through Compliance”, but Compliance's erasure is about a *data subject* (a prospect) and has nothing to say about a platform account — and deleting a user who is a team's last Owner needs a product decision (delete the team, transfer it, or refuse?). The panel can already remove a member, suspend a tenant and erase a data subject, so nothing is blocked. (3) **Reviewed, and eight real defects fixed** before the phase was called done — the two worst being that the registry's write routes never consulted a descriptor's own ability (so `support` could rewrite the platform-wide AI prompt registry) and that `Auth::logout()` during an impersonation cycles the *customer's* remember token (so an expiring session signed a real customer out of their own devices). The full list is in `CHANGELOG.md` and `docs/modules/admin.md`; the others were an account operation that let a lower role weaken a higher one, the raw search term landing in `audit_logs`, a `DatabaseSeeder` that aborted a production seed, a seeder that would promote a pre-registered `super@admin.com`, a singleton `AuditContext` that could misattribute a later request's entries, and five gaps in the impersonation blocked-route list. (4) **Two route-shape deviations**: the stack has a fifth layer, `EnsurePlatformAdmin`, so a non-operator meets a 404 before the 2FA middleware can redirect them to a security page; and **`impersonation.stop` is deliberately not an `admin.*` route**, because by the time an operator wants out `Auth::user()` is the customer and an admin-gated stop would 404 the only way back.)_
      **Dispatch prompt:** _"Execute Phase 19a of mvp.md (Admin panel — foundation). Write ADR 0014 superseding 0009, scaffold the Admin module, add the platform-admin identity with enforced 2FA, the deliberate cross-tenant context plus its route-table contract test, the resource registry with Action-backed writes, user/team operations and suspension, impersonation with its forbidden-action list, the admin chrome, and the super@admin.com seeder."_
      **Risks:** the registry is the piece that can sprawl — descriptors stay declarative and anything needing bespoke logic gets a bespoke screen rather than another flag; a query with no `team_id` is both a privacy and a performance risk, and only MySQL shows the second half; this is the one place in the product where a tenancy mistake shows the wrong customer's data, so the contract test is not optional.

---

### Phase 19b — Platform controls: settings, Stripe switches, plan features & provisioning

**Goal:** The levers: a settings store an operator can reach from a browser, Stripe switchable globally and per team without demoting anybody, features gated by plan with per-team overrides, and onboarding a new business from the panel.
**Depends on:** 19a, 16 (Billing), 12 (Notifications), 3 (Onboarding).
**Modules & docs:** Admin, Billing (`billing-plans.md`), Auth, Onboarding; **ADR 0015** (plan-driven capabilities + the platform settings store), ADR 0012.
**Tasks:**

1. [x] **A platform settings store.** `platform_settings` (key, JSON value, `updated_by`, timestamps) read through `Admin\Services\PlatformSettings`, with **`config/admin.php` as both the fallback and the schema**: every key is declared there with its type, default and whether it is an operational switch; the table holds only overrides. Cached, busted on write, every change audited with before and after.
    - Not `.env`, because an operator has to be able to turn Stripe off at 2am from a browser and a deploy is not a control surface. Not the table alone, because an undeclared key with no type is how a settings table becomes a junk drawer.
    - **Done, and the schema earns its keep twice.** `PlatformSettings` is `scoped`; both `get()`
      and `set()` throw on an undeclared key (the plan only said `set()` — null for a typo'd read
      turned out to be the worse failure: `bool('billing.stripe_enable')` reads as false, which
      switches Stripe off and looks exactly like a working screen). Writing the declared default
      **deletes** the row, so the table stays a record of deviations rather than a snapshot that
      silently stops tracking `.env`. A declaration can carry an `allowed` list, which is what
      stops `signal` being saved as a kill switch that kills nothing. The screen is generated
      from the declarations, shows the pending changes in words, takes a second press before
      switching anything off platform-wide, and saves the page in **one** transaction.
    - **A trap worth knowing:** a setting name contains dots, so a rule keyed
      `settings.billing.stripe_enabled` is read by Laravel as nesting the payload does not have —
      `data_get()` returns null, `sometimes` skips the rule, and **every rule on the form was a
      no-op** until review caught it. Validated under a dot-free alias now, errors mapped back.
2. [x] **Stripe off, globally.** A `billing.stripe_enabled` setting. The seam already exists, so the switch is a binding: off binds a `DisabledBillingGateway` whose every method throws `BillingException` with a `billing.disabled` code, and the Cashier webhook route refuses. `billing:sync-usage` keeps running — `usage_meters` is a record over the `ai_requests`/`discovery_runs` ledgers and has nothing to do with Stripe (ADR 0012) — while `billing:report-overage` no-ops with a `billing` log line, exactly as it already does when the meter prices are unset.
    - **The rule the tests exist to pin: turning Stripe off must not turn entitlement off.** A team on Scale stays on Scale with its allowances intact and keeps working; what disappears is checkout, the portal, plan swaps and metering. The failure this prevents is an operator flipping one switch and demoting every paying customer to Free — which is what `PlanResolver` would do today if "no live subscription" resolved to the default plan.
    - The UI must not offer a button that will throw: a `billing.capabilities` payload drives the billing screens and rides the API to the mobile app, so "Stripe is off" reads as a product state rather than an error.
    - **Done, and the rule holds:** `StripeSwitchTest` pins that a team on Scale stays on Scale
      with its allowances intact. Three places answer, not one: the container binds
      `DisabledBillingGateway`, the screen does not render a button that would throw, and
      `SyncSubscriptionFromStripe` **ignores a webhook that arrives anyway** — the middleware
      that 404s the route is defence in depth, but a misconfigured proxy or a route cached
      before the switch flipped must not change entitlements while an operator believes Stripe
      is not being listened to. It returns rather than throws, because Cashier has already
      answered 200.
    - The middleware had to be rewritten: global middleware runs **before** routing, so
      `$request->route()` is null and the original route-name match never fired. It matches on
      the path from `config('cashier.path')` instead.
3. [x] **Stripe off, or never on, per team.** `teams.billing_mode` → `Admin\Enums\BillingMode`: `stripe` (self-serve, today's behaviour), `manual` (invoiced outside the product — a plan **granted** by an operator, no checkout, no card, no metering: the Enterprise case `config/billing.php` already anticipates with its null price), `disabled` (no billing at all — internal, demo, pilot and partner teams).
    - `manual` needs a **plan grant**: `team_plan_grants` (team_id, plan key, nullable seat/lead/credit overrides, `starts_at`, nullable `ends_at`, note, `granted_by`). `PlanResolver` consults the grant **above** the Stripe subscription — which is also how Enterprise finally gets bespoke allowances, because a signed contract says 25 seats and 40,000 leads, not "unlimited".
    - Precedence, written down once and tested: **grant (inside its window) → live Stripe subscription → trial → `default_plan`.** A grant is how an operator fixes a billing problem without touching Stripe, and its `ends_at` is why a goodwill upgrade does not quietly become permanent.
    - **Done.** Grant precedence is `PlanGrantTest`'s subject, window expiry and bespoke
      allowances included, and granted teams get their own `SubscriptionState::Granted` because
      the billing screen has no card to change and nothing to cancel.
    - **One correction to the plan:** `BillingMode` lives in `Billing\Enums`, not `Admin\Enums`.
      The panel sets it, but it is Billing's concept and `PlanResolver` and `OverageReporter`
      both read it — putting it in Admin would have made two modules import the panel.
    - **And one thing the plan got wrong:** the migration's first draft claimed a `manual` or
      `disabled` team was "fully entitled". It is not. `billing_mode` grants nothing, so a team
      moved off Stripe without a grant is on the **default** plan — and if it was trialling it
      drops there quietly when the trial lapses. The dry run and the mode-change toast now say
      so, for `disabled` as well as `manual`.
4. [x] **Features enabled and disabled by plan.** A `Core\Enums\Capability` enum of the gateable capabilities — signals, AI insights, business intelligence, enrichment, outreach by email, outreach by LinkedIn, API access, mobile push, CSV export, bulk discovery, multiple ICPs, saved views — and `config/billing.php` gains `plans.*.capabilities`.
    - **Name trap, stated loudly:** `plans.*.features` already exists and is **marketing copy for the pricing page**. The gateable set is `capabilities`, a list of enum values. Overloading the existing key would turn a sentence into a permission.
    - Resolution order: **global kill switch → per-team override → plan → the capability's own default.** The kill switch wins because it exists for the afternoon a provider is down or a feature ships broken, and an operator must not have to hunt down every team override to use it. The override sits above the plan so a pilot customer can have Signals on Starter — and it is a row (`team_capability_overrides`: team, capability, enabled, reason, `expires_at`, `set_by`) rather than an edit to a plan, so the reason and the expiry travel with the exception.
    - **Pennant is already installed and entirely unused** — `config/pennant.php` and the `features` table have been there since Phase 0. It is the **store and the cache** for resolved values, giving `Feature::for($team)` and scoped resolution; the resolution _order_ above lives in Admin's resolver, which Pennant resolves through. Pennant is the cache, not the policy.
    - **Where it bites: the guards, not middleware** — ADR 0012's argument again and for the same reason, that a queued discovery job and a scheduled signal watch have no request behind them. So a `CapabilityGate` the existing module seams consult, **plus** a route-level `capability:` middleware so a disabled web screen refuses politely instead of half-rendering, **plus** a shared Inertia `capabilities` prop so the nav never advertises what the plan does not include.
    - Composition order, because three things now gate the same action: **suspension beats the kill switch beats the override beats the plan.** A suspended team is not a feature question.
    - **ADR 0015** records all of it, including why the settings store is schema'd in config.
    - **Done, with two deliberate departures from the plan, both recorded in ADR 0015.**
    - **Not Pennant.** The plan had it as the store and cache. It is the wrong tool here and the
      ADR says why at length: Pennant's model is a value that should be _stable_ per scope once
      resolved, and ours changes the moment a webhook, a grant window, an override expiry or a
      suspension changes — so using it would have meant inventing a cache-invalidation problem.
      It also has no ordering story, and the reason and expiry on an override are first-class
      here where a flag value of `true` carries neither. A paid entitlement is a term of a
      contract, not a feature flag. Pennant stays available for rollouts and experiments.
    - **Six capabilities, not thirteen.** `signals`, `ai_insights`, `business_intelligence`,
      `enrichment`, `csv_export`, `api_access`. The plan's longer list would have invented
      pricing the price list does not promise — gating outreach channels, multiple ICPs or saved
      views means deciding they cost money, which is a commercial decision and not a build task.
      Only `ai_insights` (Starter+) and `signals` (Team+) are actually restricted, matching the
      published copy; the rest are declared, enforced and on for everybody, so turning one into
      a paid feature is a line in `config/billing.php`.
    - Resolution order is **suspension → kill switch → override → plan → permissive default**,
      asserted layer by layer and in collision. A plan declaring **no** capability list falls
      through to permissive: a missing configuration is not a product decision, and the
      alternative would have switched six features off for everybody the day this shipped.
    - `CapabilityExplainer` reports **which layer decided**, which is the condition ADR 0015
      accepts four layers on.
    - **Two false claims found by writing the test the enum's docblock asked for.** Nothing under
      `/api/v1` consulted the gate — so the kill switch could not stop API traffic and a token
      outlived the plan that justified it — and the insights API had no check although the web
      screen did. `capability:api_access` now guards the whole api group,
      `capability:ai_insights` the insights API, and `IssueApiTokenAction` checks before issuing,
      because the mobile two-factor exchange has no session for middleware to hang on.
5. [x] **Onboarding a business from the panel.** `Admin\Actions\ProvisionTeamAction` — one Action, one `DB::transaction()`, every external call after commit: create the team (name, market, timezone, locale), create or attach the owner, assign Owner, set `billing_mode` and optionally a plan grant with bespoke allowances, set or suppress the trial, pre-fill the business profile from what sales already knows so the owner lands on a half-done onboarding rather than a blank one, optionally seed a first ICP, apply capability overrides, and **after commit** send a set-password invitation through the existing invitation mail.
    - **An operator never chooses a customer's password.** The invitation is the only path in.
    - It **calls** `Auth\Actions\CreateTeamAction` and `InviteTeamMemberAction` rather than reimplementing them, so a team provisioned by sales is indistinguishable from one that signed up — including the trial model event in `BillingServiceProvider`, which means a custom trial is set _after_ creation or suppressed deliberately, not fought.
    - A **dry run** that shows exactly what will be created before anything is, because the first real use of this screen is somebody typing a customer's name wrong. Audited; idempotent on the owner's email; and it must never silently add an existing user to a second team without saying so on screen.
    - **Done, and the invitation half of the plan was wrong.** `InviteTeamMemberAction` exists to
      invite somebody to a team they are _not_ in and refuses an existing member — correctly. But
      `CreateTeamAction` has just made this person the Owner, so the invitation was refused as
      `alreadyMember` and took the whole provisioning down with it. Writing the invitation row by
      hand would have bypassed the seat check and the audit entry that belong with it, so
      provisioning calls 19a's own `SendUserPasswordResetAction` after commit instead: the
      account genuinely exists and genuinely needs a credential, and a provisioned account's
      first way in now leaves the same audit trail as an operator pressing "send reset link" on
      day ninety. The rule is unchanged — an operator never chooses a password, and there is no
      field for one.
    - **A privilege escalation closed in review.** This screen is behind `teams.manage`, which
      `support` holds, and it calls `GrantPlanAction` and `SetCapabilityOverrideAction`, whose own
      routes need `billing.manage` and `settings.manage` — so a support operator could POST
      `grant_plan=enterprise` with `capabilities_on[]=signals` and hand a tenant an unlimited plan
      through a route that never mentions billing. `ProvisionTeamRequest` refuses those fields
      without the ability rather than stripping them: a team provisioned without the plan the form
      showed is worse than an error naming the ability needed.
6. [x] **Announcements and the two notifications nobody owns yet.** Platform-wide announcements: scheduled, dismissible, targetable by plan or market, **EN/AR** because tenants see them (unlike the panel itself), with an optional notification fan-out that goes through `NotificationDispatcher` and is **aggregated** — one per team, in the `AnnounceTopLeadsJob` shape, never one row per member per team.
    - And the two `NotificationType`s both module docs park here: **`payment_failed`** (`billing-plans.md` — today Stripe's dunning email is the only signal) and **`assessment_review_due`** (`compliance-privacy.md` — today the gate closes and sourcing stops before anybody is told).
      **Data model:** `platform_settings`, `team_plan_grants`, `team_capability_overrides`, `announcements` + `announcement_dismissals`; `teams.billing_mode`; `plans.*.capabilities` in `config/billing.php`; Pennant's `features` table finally in use.
      **Tests:** settings read-through, config fallback and audited change; Stripe off refuses every gateway method and the webhook **while entitlement, allowances and usage sync are unchanged** (the one that matters most), and the capabilities payload drives the UI; `manual` mode offers no checkout, takes entitlement from the grant and reports no overage; grant precedence including window expiry and bespoke allowances; capability resolution across all four layers in order, with the kill switch beating an override and suspension beating both; a **queued job** refused for a disabled capability with no request in play; provisioning builds a working team in one transaction with the invitation sent after commit, the dry run writes nothing, a duplicate owner email is handled visibly; announcement fan-out is aggregated; both new notification types fire once per team per period.
      **Acceptance:** Stripe can be switched off globally and per team with no customer demoted; a plan's feature set is data, not code; a sales person can onboard a business end to end from the panel; gates pass; docs updated.
      **Dispatch prompt:** _"Execute Phase 19b of mvp.md (Admin panel — platform controls). Write ADR 0015. Add the config-schema'd platform settings store, the global and per-team Stripe switches with plan grants (entitlement must survive Stripe being off), plan-driven capabilities over Pennant enforced in the guards, ProvisionTeamAction with a dry run, announcements, and the payment_failed and assessment_review_due notifications."_
      **Risks:** "Stripe off" reading as "no plan" is the one bug here that costs money and customers; capability checks drifting between the guard, the route middleware and the Inertia prop — one resolver, three callers; and plan `features` vs `capabilities` being confused by the next person, which is why the key is named differently and the docs say so twice.
    - **Done.** The fan-out is aggregated (one per team through `toTeam()`), and it needed a
      **third** guard the plan did not anticipate: `ShouldBeUnique` stops a double-saved form and
      `notified_at` stops a retry of a job that _finished_, but this job walks every tenant, so a
      crash at team 3,000 of 4,000 re-notified the first 2,999. `announcement_notifications` is a
      per-tenant claim with a unique index, taken **before** the send — a crash between the two
      loses one notification, where the other order sends one twice.
    - The stored payload carries resolved prose, which is the **one** documented exception to the
      keys-and-params rule; `notifications.md` says why, and that the cost is a locale frozen at
      send time.
    - `assessment_review_due` fired only on the exact day the threshold was crossed, so a morning
      the scheduler missed lost the notice entirely. `lia_records.review_notified_at` widens the
      window to the whole fortnight while still sending exactly two notices per cycle.

---

### Phase 19c — Platform reporting & operations

**Goal:** The business's own numbers — including whether each team is profitable — and the operational views that make the platform runnable: failed jobs, queues, provider health, audit, and the operator views three earlier phases deferred to here.
**Depends on:** 19a, 19b; 13 (Reporting primitives), 5 (AI Platform ledger), 15 (Compliance), Signals S0–S6.
**Modules & docs:** Admin, Reporting (`reporting-analytics.md`), Signals (`signals.md`), Compliance (`compliance-privacy.md`), Billing (`billing-plans.md`); `docs/runbooks/admin-panel.md` (new).
**Tasks:**

1. [ ] **Platform reporting — not tenant reporting with the scope removed.** Reporting's builders are **scoped by design** and each figure's cohort rule is written for one team's window (`reporting-analytics.md`); reusing them across tenants would silently answer a different question. Platform reporting asks its own questions and reuses `DateRange` and its **UTC discipline** (`DateRange::constrain()`) — not its Actions.
    - Growth and funnel: signups, activations, and the **onboarding funnel** (signed up → profile → ICP → first discovery → first lead worked → first outreach), which is the single report that says whether the product's first hour works; trial starts, conversions and which day they convert on.
    - Revenue: MRR and ARR by plan with movement broken out (new, expansion, contraction, churn, reactivation); retention cohorts; failed payments and dunning state; overage revenue; seat utilisation.
    - Pricing signal: allowance consumption distribution — how many teams hit the ceiling, and on which metric. That distribution is what tells you a plan is priced or sized wrong.
    - Cost: provider spend by provider, AI spend by model and by work type, lead volume and cost per lead, outreach volume and reply rate.
    - **The number the business lives on: gross margin per team.** What a team pays against what its AI and data calls cost — `ai_requests` and `discovery_runs` already carry cost micro-units, so the figure is computable today and nothing in the product surfaces it. A team can be on Scale and still be unprofitable. Per team, per plan, and per signup cohort.
2. [ ] **Rollups, not live scans.** `platform_daily_metrics` (date, metric, dimension, value) written by a nightly command from the ledgers. A cross-tenant aggregate over `ai_requests` is the one query in this product with no `team_id` to narrow it, and it will not stay fast. The table is a **record over the ledgers**, exactly as `usage_meters` is (ADR 0012): recomputable, backfillable for a window, never a counter the app increments.
    - **Ordering trap:** Compliance's retention sweep prunes what these figures are computed from, so the rollup must be written before the sweep can reach a period — the same constraint `billing:sync-usage` runs hourly for. Schedule and test it accordingly.
    - Charts through the `dataviz` skill and the existing `components/charts`; CSV export reusing `ReportCsvExporter` and `Core\Support\CsvCell`.
3. [ ] **Failed jobs, queues and schedule health.** Read `failed_jobs` directly with retry, retry-all and delete (each audited), surfacing the job class **and the team id from the payload** — which is the thing an operator actually needs and the thing Horizon's queue view does not know. Queue depth per queue, oldest pending job, last run per scheduled command. Pulse cards appear where Pulse is running; Phase 20 wires Pulse properly and this does not wait for it.
4. [ ] **Provider health.** `CircuitBreaker` state per provider (Companies House, Google Places, Serper, Serper News, Claude, Resend, Stripe), recent error and latency rates from `ai_requests` and `discovery_runs`, and a **reset breaker** action (audited). The breaker is cache-backed and advisory by design, so the panel reports it as advisory and never as truth.
5. [ ] **Audit log viewer** across tenants — filterable by action, actor, team, subject and window, with impersonation sessions and admin reads first-class rather than buried among writes. Append-only: the viewer has no edit or delete, and the descriptor forbids both.
6. [ ] **The three deferred operator views land here.**
    - **`signal_runs`** (`signals.md`, `signals-plan.md` task 6): cost, failures and durations per run across tenants, read-only.
    - **Compliance activity** (`compliance-privacy.md`): retention runs, exports, erasures and LIA records, read-only.
    - **Subscriptions, MRR and failed payments** (`billing-plans.md`): covered by tasks 1 and 3 above — the pointer in that doc gets closed out.
7. [ ] **Outreach oversight.** Platform send volume, bounce and complaint rates, and the global do-not-contact list — because a shared sending domain means one tenant's behaviour is every tenant's deliverability. A platform-level suppression (an address nobody may contact) stays in **`outreach_suppressions` with a null `team_id`**, not a second table: the do-not-contact table stays `outreach_suppressions` (CLAUDE.md §3), and `OutreachGuard` is still the only thing that answers whether a prospect may be approached.
8. [ ] **Docs, ADRs and closing the loop.** Rewrite `docs/modules/admin.md` from `_TEMPLATE.md`; `docs/runbooks/admin-panel.md` written for someone who has not read the code (onboard a business, grant a plan, suspend a team, turn Stripe off, reset a breaker, retry a job, impersonate safely); `architecture.md`; `CHANGELOG.md`; and close the Phase 19 pointers in `signals.md`, `signals-plan.md`, `compliance-privacy.md`, `billing-plans.md` and the Signals note at the top of this plan. The out-of-scope list and the status table at the top of this plan were already updated when this phase was rewritten; check they still read true.
       **Data model:** `platform_daily_metrics`. Nothing else.
       **Tests:** the rollup command is idempotent, backfills a window, and runs ahead of the retention sweep; margin maths on fixtures with known costs and a known plan price; the onboarding funnel's cohort rule; UTC bounds hold for an operator in a non-UTC timezone (the `DateRange::constrain()` trap); failed-job retry and delete audited; breaker reset audited; the three deferred views are genuinely read-only (no write route exists); a platform suppression blocks a send in **every** tenant.
       **Acceptance:** the panel answers "is this business working, and is this customer profitable"; an operator can diagnose and clear a stuck queue, a failing provider and a mis-sent campaign without a shell; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 19c of mvp.md (Admin panel — platform reporting & operations). Build platform reporting on its own builders over a platform_daily_metrics rollup (reusing DateRange, not Reporting's Actions), including gross margin per team; add failed jobs, queue/schedule health, provider health with breaker reset, the cross-tenant audit viewer, the deferred signal_runs and compliance views, outreach oversight with platform suppression; then write admin.md, the admin-panel runbook, and close every Phase 19 pointer in the docs."_
       **Risks:** platform aggregates quietly answering a tenant-shaped question — each figure's definition is written down beside it, as Reporting's are; rollups drifting from the ledgers, which recomputability and a backfill test are the answer to; and a margin number that looks authoritative while a provider's cost is unrecorded, so unattributed cost is shown as unattributed rather than as zero.

---

### Phase 20 — Observability & security hardening

**Goal:** Sentry (BE/FE), Pulse dashboards, security headers, rate limits, dependency/secret scanning, and a security review.
**Depends on:** most phases.
**Modules & docs:** Core; ADR 0001.
**Tasks:**

1. [ ] Sentry DSNs (backend + frontend + mobile) with release/trace sampling; Pulse cards for queues/slow queries/AI usage.
2. [ ] Security headers, CSRF/CORS review, login/2FA throttling, token abilities audit, input size limits on AI/fetch.
3. [ ] CI: dependency audit, secret scanning; run the `security-review` skill on the codebase.
       **Data model:** none.
       **Tests:** headers present; rate limits enforced; Sentry captures a thrown error (faked transport).
       **Acceptance:** monitoring live, security review actioned; gates pass; docs updated.
       **Dispatch prompt:** _"Execute Phase 20 of mvp.md (Observability & security hardening). Wire Sentry + Pulse, add security headers/rate-limits, set up dependency/secret scanning in CI, and run a security review; fix findings."_
       **Risks:** false sense of security — ensure the review is real; secret handling in CI.

---

### Phase 21 — Hetzner deployment & CI/CD

**Goal:** Production on Hetzner Ubuntu with Nginx, PHP-FPM 8.5, MySQL, Redis, Supervisor (Horizon + Reverb), TLS, backups, and a CD pipeline.
**Depends on:** 20.
**Modules & docs:** `docs/runbooks/deployment.md`.
**Tasks:**

1. [ ] Provision server (users, firewall, PHP 8.5 + ext pcntl/posix/redis, Nginx, MySQL 8, Redis).
2. [ ] Deploy flow (zero-downtime, `composer install --no-dev`, `migrate --force`, caches, `npm ci && build`, restart Horizon/Reverb); Supervisor + Nginx + Let's Encrypt configs.
3. [ ] Scheduled backups + restore test; prod `.env` (redis queue/cache, reverb broadcast); GitHub Actions deploy job.
4. [ ] Fill in `docs/runbooks/deployment.md`.
       **Data model:** none.
       **Tests:** CI deploy to staging; smoke checks post-deploy.
       **Acceptance:** app serves over HTTPS; queues + realtime run; backups verified; CD works; runbook complete.
       **Dispatch prompt:** _"Execute Phase 21 of mvp.md (Hetzner deployment & CI/CD). Provision the server, set up Nginx/PHP-FPM/MySQL/Redis/Supervisor/TLS/backups, build the deploy pipeline, and complete deployment.md."_
       **Risks:** Reverb WebSocket proxying; Horizon supervisor config; backup restore actually tested.

---

### Phase 22 — Launch checklist

**Goal:** Final gate before go-live.
**Depends on:** 21.
**Tasks:**

1. [ ] Legal sign-off on compliance (⚖️ UK GDPR/PECR + UAE PDPL/TDRA, privacy notice, DPA, controller/processor split).
2. [ ] Seed plans/prices in Stripe (live); verify credit→cost margins.
3. [ ] Load/perf sanity; error budgets; on-call/runbook; backup/restore drill.
4. [ ] Accessibility (WCAG AA) + EN/AR pass across key flows; brand review against the guidelines checklist.
5. [ ] Data retention + suppression verified end-to-end; Sentry/Pulse dashboards green.
       **Acceptance:** all boxes ticked; owner sign-off.
       **Dispatch prompt:** _"Execute Phase 22 of mvp.md (Launch checklist). Work the checklist, confirm legal/compliance/billing/accessibility/brand/observability, and record sign-off."_
       **Risks:** legal is the critical path — start counsel review early (during Phase 15).

---

## Post-MVP backlog

- **Enrichment:** Apollo / People Data Labs / Hunter providers; paid email verification; technographics; scheduled re-enrichment.
- **Outreach:** multi-step sequences; Gmail/Outlook OAuth sending; open/reply tracking; unsubscribe link handling; A/B message testing; deliverability (SPF/DKIM/DMARC) tooling.
- **AI Insights:** full suite (best segments, message-angle performance, one-click ICP apply, learning from applied/dismissed).
- **Scoring:** learning-to-rank from feedback; confidence intervals.
- **Integrations:** outbound webhooks; Zapier/Make; HubSpot/Pipedrive sync; calendar booking links.
- **Admin:** full CRUD; provider-health dashboards; feature-flag toggles UI; broadcast announcements.
- **Auth:** SSO/SAML, SCIM, passkeys polish.
- **Notifications:** SMS, Slack/Teams.
- **Reporting:** scheduled email reports; custom report builder; cohort analysis.
- **Leads:** custom fields; automations; duplicate-merge UI; shared team inboxes.
- **Billing:** annual plans; coupons UI; usage-based pricing experiments; UK/UAE VAT handling.
- **Platform:** multi-provider AI routing by cost/quality; embeddings/vector store; streaming AI to UI.
