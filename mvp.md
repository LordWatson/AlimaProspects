# AlimaProspects — MVP Build Plan

> The phased plan to take AlimaProspects from the bootstrapped foundation to a launchable MVP. Each phase is sized for **one Claude Code session**. Read `CLAUDE.md` and the named `docs/modules/*.md` before starting any phase. A phase is done only when: `composer test` green, `composer analyse` clean (Larastan ≥ level 6; repo at 7), `npm run check` + `npm run types:check` + `npm run build` pass, **and docs updated** (module doc Changelog row, `architecture.md` if structure changed, `CHANGELOG.md`, this status table).

## Status

| Phase | Title | Status | Completed |
|---|---|---|---|
| 0 | Foundation, docs & knowledge base | ✅ Done | 2026-10-28 |
| 1 | Auth, teams, roles & invitations | ⬜ Not started | — |
| 2 | Brand design system & app shell (i18n/RTL) | ⬜ | — |
| 3 | Onboarding & Business Profile | ⬜ | — |
| 4 | Ideal Customer Profile (ICP) | ⬜ | — |
| 5 | AI Platform | ⬜ | — |
| 6 | Business Intelligence (AI background check) | ⬜ | — |
| 7 | Lead Sourcing — framework + Companies House | ⬜ | — |
| 7b | Lead Sourcing — Google Places + web search | ⬜ | — |
| 8 | Enrichment & Data Quality | ⬜ | — |
| 9 | Scoring & Matching | ⬜ | — |
| 10 | Leads Workspace — list, detail, contacts | ⬜ | — |
| 10b | Leads Workspace — pipeline, notes, tasks, CSV | ⬜ | — |
| 11 | Outreach (MVP) | ⬜ | — |
| 12 | Notifications | ⬜ | — |
| 13 | Reporting & Analytics | ⬜ | — |
| 14 | AI Insights (light) | ⬜ | — |
| 15 | Compliance & Privacy features | ⬜ | — |
| 16 | Billing & Plans | ⬜ | — |
| 17 | API v1 hardening | ⬜ | — |
| 18 | Mobile app (Capacitor + Ionic Vue) | ⬜ | — |
| 19 | Admin panel (internal) | ⬜ | — |
| 20 | Observability & security hardening | ⬜ | — |
| 21 | Hetzner deployment & CI/CD | ⬜ | — |
| 22 | Launch checklist | ⬜ | — |

Legend: ⬜ not started · 🟡 in progress · ✅ done · ⛔ blocked.

---

## What the MVP is (and isn't)

**In scope (v1 launch):** a UK/UAE team can sign up, be guided through onboarding, get an AI background check, define a weighted ICP, run AI-assisted lead discovery (Companies House + Google Places + web search), have leads enriched/deduped/scored (A–D with explanation), work them in a CRM-lite workspace (list, detail, pipeline, notes, tasks, assignment, CSV), generate AI-personalised outreach drafts and log contact attempts (with suppression/opt-out enforced), see a reporting dashboard, receive notifications and a daily "contact today" digest, pay via Stripe with plan-based lead/AI credit limits, and exercise core compliance (suppression, opt-out, retention, team export). Bilingual EN/AR + RTL. A hardened `/api/v1` and a focused mobile app follow.

**Explicitly out (post-MVP backlog at the bottom):** paid B2B enrichment providers, multi-step outreach sequences + mailbox OAuth + open/reply tracking, full AI-insight suite with one-click apply, CRM integrations/webhooks/Zapier, calendar booking, full admin CRUD, SSO/SCIM, SMS/Slack notifications, custom report builder, and advanced scoring (learning-to-rank).

---

## Cost model & margin protection

AI and data-provider calls are the variable cost of a "lead search". Protect margins with **per-plan credit allowances enforced before work runs** (Billing → AI Platform / Lead Sourcing guards).

**Indicative cost per discovery run** (≈50 candidate companies, order-of-magnitude — verify with live pricing at the AI/providers phases):

| Item | Unit | ~Cost | Per 50-lead run |
|---|---|---|---|
| Companies House | per request | free | £0 |
| Google Places | per detail request | ~$0.017 | ~$0.85 |
| Web search API (Serper/Brave) | per query | ~$0.001–0.005 | ~$0.10–0.25 |
| AI enrichment extract (Claude Haiku) | per lead, ~2k in/0.5k out | ~$0.002 | ~$0.10 |
| AI qualitative scoring (Claude Sonnet) | per lead, ~1.5k in/0.5k out | ~$0.012 | ~$0.60 |
| AI background check (Sonnet, once per business) | per run | ~$0.05 | n/a per-run |
| **Total variable** | | | **≈ $1.65–1.80 / 50 leads (~$0.035/lead)** |

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
1. [ ] Enable **spatie permission teams mode** (config `teams => true`, team foreign key); adjust/replace the published permission migration; seed the four `TeamRole` roles.
2. [ ] Resolve the active team into `TeamContext` + spatie's `setPermissionsTeamId()` in `LogContext`/a dedicated middleware early in the request.
3. [ ] `CreateTeamAction`, team switch endpoint (web + `/api/v1`), guard by membership.
4. [ ] Team **invitations**: `team_invitations` table, invite/accept/resend/revoke Actions, signed accept URL, mailable; invited user joins with a role.
5. [ ] Verify Fortify flows (register, verify email, reset, **2FA enable/confirm/recovery**) work with the starter kit; wire registration to create a personal team + Owner role.
6. [ ] Sanctum token issue/revoke endpoints for mobile; per-token abilities; rate-limit `/api/v1`.
7. [ ] Policies for team management; audit log on create/switch/invite/role-change.
**Data model:** `team_invitations`; permission tables (team-scoped); role on `team_user`.
**Tests:** registration creates team+owner; 2FA challenge; invite→accept joins team; switch-team denied for non-member; cross-tenant team access fails; token auth on a sample `/api/v1` route.
**Acceptance:** all auth + team flows pass; tenancy tests green; Larastan clean; docs updated.
**Dispatch prompt:** *"Execute Phase 1 of mvp.md (Auth, teams, roles & invitations). Read CLAUDE.md, docs/modules/auth-users.md and core.md first. Enable spatie teams mode, wire team creation/switching/invitations and roles, verify Fortify 2FA, add Sanctum token endpoints, write the tests, and update docs."*
**Risks:** spatie teams + global scope interaction (set permission team id consistently); Fortify 2FA UI already in starter kit — confirm before rebuilding.

---

### Phase 2 — Brand design system & app shell (i18n/RTL)
**Goal:** Brand tokens in Tailwind v4, the reusable base component library, the authenticated app shell (nav, team switcher, locale switch), and EN/AR + RTL.
**Depends on:** 1.
**Modules & docs:** Core; `docs/brand/brand-tokens.md`; ADR 0007.
**Tasks:**
1. [ ] Encode brand tokens as Tailwind v4 `@theme` vars in `resources/css/app.css` (colours, semantic tokens, radius, shadow, motion, fonts Poppins/DM Mono/Tajawal); export a shared token module in `resources/js/shared/` for mobile.
2. [ ] Build base components (`Button`, `Input`, `Card`, `Table`, `Modal`, `Badge`, `EmptyState`, `Toast`, form controls) on the brand, WCAG AA, keyboard-accessible, with stories/examples.
3. [ ] App shell: top bar (logo, team switcher, user menu, locale toggle), responsive nav, toast host; one primary CTA per view convention.
4. [ ] **i18n**: Laravel + front-end locale files (en, ar), `dir`/RTL handling, Tajawal for Arabic, number/date formatting per team locale/timezone.
5. [ ] Dark-mode tokens (per `brand-tokens.md` §7 — **confirm values with brand owner**).
6. [ ] Wire Laravel Echo + Reverb on the front end (the installer step deferred in Phase 0).
**Data model:** none.
**Tests:** component render/accessibility smoke (vue-tsc + build); locale switch flips `dir`; a Pest test for locale middleware.
**Acceptance:** base components exist and are used by the shell; EN/AR + RTL works; build + types pass; docs updated.
**Dispatch prompt:** *"Execute Phase 2 of mvp.md (Brand design system & app shell). Read brand-tokens.md and ADR 0007. Encode tokens into Tailwind v4, build the base component library and app shell, add EN/AR + RTL, and wire Echo/Reverb. Flag dark-mode values for approval."*
**Risks:** dark-mode palette pending approval; Tailwind v4 `@theme` specifics; RTL correctness across components.

---

### Phase 3 — Onboarding & Business Profile
**Goal:** A resumable onboarding wizard that captures the business profile and triggers the background check.
**Depends on:** 1, 2 (5 optional — can stub the trigger).
**Modules & docs:** Onboarding & Business Profile (`onboarding-business-profile.md`).
**Tasks:**
1. [ ] `business_profiles` + `onboarding_progress` migrations/models (team-scoped, `BelongsToTeam`).
2. [ ] Wizard steps (company, website, industry, size, locations, regions UK/UAE, products/services, value prop, pricing range, customer examples) with per-step validation (FormRequests/Data).
3. [ ] `SaveOnboardingStepAction` (resumable), `CompleteOnboardingAction` (dispatches background-check job `afterCommit` — stub if Phase 5/6 not done).
4. [ ] Inertia wizard pages + progress indicator; market selection sets compliance defaults.
5. [ ] `/api/v1` read of profile + progress.
**Data model:** `business_profiles`, `onboarding_progress`.
**Tests:** step save/resume; completion dispatches job; tenancy; validation.
**Acceptance:** wizard saves/resumes, profile stored, completion hook fires; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 3 of mvp.md (Onboarding & Business Profile). Read the module doc. Build the resumable wizard, business_profiles model, save/complete Actions (stub the BI trigger if needed), Inertia pages, and tests."*
**Risks:** scope creep in fields; keep it to the doc's list.

---

### Phase 4 — Ideal Customer Profile (ICP)
**Goal:** Teams define weighted ICPs with criteria and exclusions; changes emit a re-score event.
**Depends on:** 3.
**Modules & docs:** ICP (`icp.md`).
**Tasks:**
1. [ ] `icps` + `icp_criteria` migrations/models (team-scoped, weights, exclusions).
2. [ ] `CreateIcpAction`, `UpdateIcpAction`, `SetActiveIcpAction`; emit `IcpUpdated` event (consumed later by Scoring).
3. [ ] Criteria types enum (industry, size, geo, title/seniority, technographic, buying-signal, exclusion).
4. [ ] Inertia ICP editor with weight sliders + exclusions; `/api/v1` CRUD + resources.
5. [ ] (If Phase 5 done) accept AI-suggested ICP from Business Intelligence.
**Data model:** `icps`, `icp_criteria`.
**Tests:** CRUD; weights persist; active-ICP switch; `IcpUpdated` dispatched; tenancy; validation.
**Acceptance:** ICPs editable with weights/exclusions; event fires; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 4 of mvp.md (ICP). Read icp.md. Build icps/icp_criteria, the weighted editor, CRUD Actions + API, the IcpUpdated event, and tests."*
**Risks:** criteria schema must be flexible enough for scoring without over-engineering.

---

### Phase 5 — AI Platform
**Goal:** A production-grade `AiClient` (Anthropic Claude via `laravel/ai`) with prompt registry, JSON-schema validation, retries, caching, and per-team cost/credit logging.
**Depends on:** 1 (Billing optional — stub credit source).
**Modules & docs:** AI Platform (`ai-platform.md`), `docs/ai-prompts.md`, ADR 0005.
**Tasks:**
1. [ ] `composer require laravel/ai`; configure Anthropic driver; env keys (`ANTHROPIC_API_KEY`, models).
2. [ ] `Contracts\AiClient` + `ClaudeAiClient` (reasoning=Sonnet, cheap=Haiku); a fake for tests.
3. [ ] `PromptRegistry` + `ai_prompt_templates` (key, version, body, schema, tier); seed initial prompts from `ai-prompts.md`.
4. [ ] Structured output: JSON-schema validation + retry on invalid → typed DTO (spatie/laravel-data).
5. [ ] `ai_requests`/`ai_usage` logging (tokens, cost minor units, latency, cache hit) per team; `ai` log channel.
6. [ ] `AiCreditGuard` (reads plan allowance — stub until Billing); response cache keyed on prompt+version+input hash.
7. [ ] **Injection safety** helper: wrap untrusted content as delimited data; never execute embedded instructions.
**Data model:** `ai_prompt_templates`, `ai_requests`/`ai_usage`.
**Tests:** fake client returns schema-valid output; invalid output retried then errors; usage logged; cache hit avoids a 2nd call; injection wrapper; **external AI always faked**.
**Acceptance:** any module can call `AiClient->structured(key, input, schema)`; cost logged; credits enforced (stub ok); gates pass; docs + `ai-prompts.md` updated.
**Dispatch prompt:** *"Execute Phase 5 of mvp.md (AI Platform). Read ai-platform.md, ai-prompts.md, ADR 0005. Install laravel/ai, build AiClient + Claude driver + fake, prompt registry, schema validation, usage/cost logging, credit guard (stub), caching, injection safety, and tests. Never hit a real API in tests."*
**Risks:** `laravel/ai` API surface; model ids/pricing drift (verify live); keep our interface stable regardless of transport.

---

### Phase 6 — Business Intelligence (AI background check)
**Goal:** Re-runnable, versioned AI background check on the signed-up business, with review/accept.
**Depends on:** 3, 5, 7 (web fetch — can use web-search provider; if 7 not done, add a minimal fetch).
**Modules & docs:** Business Intelligence (`business-intelligence.md`).
**Tasks:**
1. [ ] `business_intel_reports` (versioned, status) model/migration.
2. [ ] `RunBackgroundCheckJob` (idempotent, `ShouldBeUnique`): fetch site/public pages, call `business_intel.background_check`, validate schema, store, **progress over Reverb**.
3. [ ] `AcceptBackgroundCheckAction` (merge into Business Profile + optionally seed ICP), `RerunBackgroundCheckAction`.
4. [ ] Inertia review screen (diff vs the business's own profile); accept/edit; `/api/v1` read.
**Data model:** `business_intel_reports`.
**Tests:** job produces a report (faked AI); accept merges; rerun versions; injection-safe handling; tenancy.
**Acceptance:** background check runs, is reviewable/acceptable, versioned; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 6 of mvp.md (Business Intelligence). Read the module doc. Build business_intel_reports, the RunBackgroundCheckJob with Reverb progress, accept/rerun Actions, review UI, and tests with faked AI."*
**Risks:** fetching arbitrary sites (timeouts, size limits, ToS); treat content strictly as data.

---

### Phase 7 — Lead Sourcing: framework + Companies House
**Goal:** The `LeadSourceProvider` contract, queued/tracked discovery runs with progress and limits, and the Companies House provider.
**Depends on:** 4, 5.
**Modules & docs:** Lead Sourcing (`lead-sourcing.md`), ADR 0008.
**Tasks:**
1. [ ] `Contracts\LeadSourceProvider`; provider registry/factory; `leads` (company-level) + `discovery_runs` migrations (team-scoped, source attribution).
2. [ ] `CompaniesHouseProvider` (official API, free) with timeout/retry/circuit-breaker; env key.
3. [ ] `DiscoverLeadsJob` (idempotent, `$tries/$backoff/$timeout/failed()`, progress over Reverb); `StartDiscoveryRunAction` enforces per-plan limits (stub until Billing).
4. [ ] Inertia "new discovery" + run-progress screens; `/api/v1` start/list runs.
5. [ ] Respect ToS/robots; attach `source`/`source_url`/`collected_at` to every field; LIA gate check (stub until Compliance).
**Data model:** `leads`, `discovery_runs`.
**Tests:** faked provider yields leads; run tracked + progress; limit blocks over-quota (stub); dedupe placeholder; tenancy.
**Acceptance:** a discovery run against Companies House (faked in tests) creates attributed leads with progress; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 7 of mvp.md (Lead Sourcing framework + Companies House). Read lead-sourcing.md and ADR 0008. Build the LeadSourceProvider contract, discovery_runs + leads, the Companies House provider, DiscoverLeadsJob with Reverb progress + limits, UI, and tests with a faked provider."*
**Risks:** Companies House rate limits; mapping registry data to our lead shape.

---

### Phase 7b — Lead Sourcing: Google Places + web search
**Goal:** Add the Google Places and web-search providers (UAE + general discovery).
**Depends on:** 7.
**Modules & docs:** Lead Sourcing, ADR 0008.
**Tasks:**
1. [ ] `GooglePlacesProvider` (location + category; strong for UAE); env key; cost logged.
2. [ ] `WebSearchProvider` (Serper/Brave/Bing abstraction); used for discovery + feeding BI/enrichment.
3. [ ] Provider selection in the discovery UI; per-provider cost surfaced; dedupe across providers.
**Data model:** none new (reuse `leads`/`discovery_runs`; add `provider` metadata).
**Tests:** each provider faked; multi-provider run dedupes; cost recorded.
**Acceptance:** three providers selectable; costs tracked; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 7b of mvp.md (Google Places + web search providers). Read lead-sourcing.md. Add both providers behind LeadSourceProvider, surface per-provider cost, dedupe across providers, and test with fakes."*
**Risks:** Google Places pricing/quotas; web-search provider choice (pick in this phase, record ADR).

---

### Phase 8 — Enrichment & Data Quality
**Goal:** Clean, deduped, source-attributed leads with contacts, free email verification, and freshness — no paid provider.
**Depends on:** 7, 5.
**Modules & docs:** Enrichment & Data Quality (`enrichment-data-quality.md`), ADR 0008, compliance.
**Tasks:**
1. [ ] `lead_contacts` + enrichment tracking migrations; `SourcedValue` cast/DTO (source/url/collected_at/method on every field).
2. [ ] `EnrichLeadJob` (idempotent): normalise company data; **AI extraction** of facts from fetched public text (`lead.enrich_extract`, cheap tier, injection-safe).
3. [ ] **Email verification** (syntax + MX/DNS + disposable-domain); store confidence, never assert.
4. [ ] **De-duplication** by domain, company number, email; merge keeping best source per field.
5. [ ] Prefer business/role over personal data; flag personal-looking data (compliance).
**Data model:** `lead_contacts`, enrichment fields on `leads`.
**Tests:** extraction (faked AI); MX check (faked resolver); dedupe merges; source attribution enforced; tenancy.
**Acceptance:** leads enriched + deduped with attribution + freshness; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 8 of mvp.md (Enrichment & Data Quality). Read the module doc + ADR 0008. Build lead_contacts, SourcedValue, EnrichLeadJob with AI extraction, MX/DNS email verification, dedupe, and tests with fakes."*
**Risks:** SMTP probing etiquette (prefer MX-only); AI extraction accuracy; dedupe false-merges.

---

### Phase 9 — Scoring & Matching
**Goal:** Hybrid ICP-fit score (deterministic + AI) with grade A–D, breakdown, explanation, re-scoring and feedback.
**Depends on:** 4, 8, 5.
**Modules & docs:** Scoring & Matching (`scoring-matching.md`).
**Tasks:**
1. [ ] `DeterministicScorer` over `icp_criteria` weights vs enriched fields → fit score + breakdown.
2. [ ] `ScoreLeadAction` combining deterministic + `scoring.qualitative_fit` (AI) → score, grade, explanation; `lead_scores` table.
3. [ ] Re-score on `IcpUpdated` and on enrichment update (queued, idempotent).
4. [ ] `score_feedback` (good/bad) capture; surface grade/breakdown/explanation (Inertia + `/api/v1`).
**Data model:** `lead_scores`, `score_feedback`.
**Tests:** deterministic math; hybrid produces grade+explanation (faked AI); re-score on ICP change; feedback stored; tenancy.
**Acceptance:** leads graded with explanation; re-scoring works; feedback captured; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 9 of mvp.md (Scoring & Matching). Read the module doc. Build the deterministic scorer, hybrid ScoreLeadAction, lead_scores, re-score listeners, feedback, UI, and tests with faked AI."*
**Risks:** weight normalisation; keeping explanations grounded (no hallucinated facts).

---

### Phase 10 — Leads Workspace: list, detail, contacts
**Goal:** The leads index (filters/search/saved views), lead detail page with contacts, and the activity timeline.
**Depends on:** 9.
**Modules & docs:** Leads Workspace (`leads-workspace.md`).
**Tasks:**
1. [ ] Leads index: server-driven table, filters (grade, source, stage, tags), search, pagination; **saved views** per user.
2. [ ] Lead detail: company + contacts, score panel, source attribution, data freshness.
3. [ ] `lead_activities` timeline (polymorphic) aggregating discovery/enrichment/scoring events.
4. [ ] `/api/v1` list/detail with resources + pagination.
**Data model:** `lead_activities`, `saved_views`.
**Tests:** filter/search/pagination; detail loads contacts+score; timeline; tenancy; no N+1 (preventLazyLoading).
**Acceptance:** list + detail usable; saved views work; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 10 of mvp.md (Leads Workspace list/detail). Read the module doc. Build the leads index with filters/search/saved-views, the detail page with contacts+score+timeline, the API, and tests."*
**Risks:** N+1s on list; filter performance (index columns).

---

### Phase 10b — Leads Workspace: pipeline, notes, tasks, bulk, CSV
**Goal:** Customisable kanban pipeline, tags, notes, tasks/reminders, assignment, bulk actions, and CSV import/export.
**Depends on:** 10.
**Modules & docs:** Leads Workspace.
**Tasks:**
1. [ ] `pipeline_stages` (team-customisable), `lead_tags`, `lead_notes`, `lead_tasks` (due/reminder), `lead_assignments`.
2. [ ] Kanban board (drag between stages), tag/note/task/assign UI; bulk actions (tag, assign, move, suppress, add to outreach).
3. [ ] **CSV import** (column mapping, dedupe on import) and **export**.
4. [ ] Task reminders raise notifications (Phase 12); stage moves write to timeline + audit.
**Data model:** `pipeline_stages`, `lead_tags`, `lead_notes`, `lead_tasks`, `lead_assignments`.
**Tests:** stage move; note/task CRUD; assignment; bulk action; CSV round-trip + dedupe; tenancy.
**Acceptance:** full CRM-lite collaboration works; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 10b of mvp.md (Leads Workspace pipeline/notes/tasks/CSV). Read the module doc. Build pipeline stages, tags/notes/tasks/assignment, bulk actions, CSV import/export, and tests."*
**Risks:** CSV edge cases/encoding; kanban perf with many leads.

---

### Phase 11 — Outreach (MVP)
**Goal:** AI-personalised drafts (email/LinkedIn text/call script), templates, tone controls, manual logging, and compliant email send — suppression/opt-out enforced.
**Depends on:** 10b, 5, 6 (profile). 15 recommended for full suppression; stub the check if before 15.
**Modules & docs:** Outreach (`outreach.md`), compliance.
**Tasks:**
1. [ ] `outreach_templates`, `outreach_messages`, `contact_attempts` migrations.
2. [ ] `DraftMessageAction` → `outreach.draft_message` (channel + tone) → `MessageDraft` (variants); injection-safe.
3. [ ] Draft UI: generate, edit, **copy** (LinkedIn/call), manual **log**; **send email** via team SMTP/mailbox (feasibility-gated).
4. [ ] **Suppression + opt-out + sender-ID checks at send time**; per-market (UK/UAE) outreach gate (stub until Compliance).
5. [ ] Bilingual drafting; log attempts to timeline + audit.
**Data model:** `outreach_templates`, `outreach_messages`, `contact_attempts`.
**Tests:** draft generated (faked AI); send blocked when suppressed; attempt logged; tenancy.
**Acceptance:** drafts + copy + log + compliant send work; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 11 of mvp.md (Outreach MVP). Read outreach.md + compliance.md. Build templates/messages/attempts, DraftMessageAction, the draft UI with copy/log/send, suppression+opt-out+sender-ID enforcement, and tests with faked AI."*
**Risks:** email deliverability/SPF/DKIM out of scope for MVP (document); suppression must be airtight.

---

### Phase 12 — Notifications
**Goal:** In-app (Reverb) + email notifications with per-user preferences and daily/weekly digests.
**Depends on:** 1, 2; consumers 7/9/10b/11.
**Modules & docs:** Notifications (`notifications.md`).
**Tasks:**
1. [ ] Notifications table + preferences; broadcast via Reverb; mail channel; toast host (Phase 2).
2. [ ] Notification types: discovery complete, new A-grade leads, task due, assignment, billing limit.
3. [ ] Preference UI; digest scheduler (daily/weekly).
**Data model:** `notifications`, `notification_preferences`.
**Tests:** notification dispatched + stored; preference suppresses a channel; digest builds; tenancy.
**Acceptance:** in-app + email + prefs + digest work; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 12 of mvp.md (Notifications). Read notifications.md. Build notifications + preferences + Reverb broadcast + email + digests, wire the key producers, and test."*
**Risks:** Reverb auth for private channels; digest timezone correctness.

---

### Phase 13 — Reporting & Analytics
**Goal:** Dashboard KPIs, funnel, source/ICP performance, team activity, AI usage, date filters, CSV export.
**Depends on:** 9, 10b, 11, 5.
**Modules & docs:** Reporting & Analytics (`reporting-analytics.md`), `dataviz` skill.
**Tasks:**
1. [ ] Aggregation services (optional rollup tables) over leads/scores/pipeline/outreach/AI usage.
2. [ ] Dashboard: KPIs, funnel by stage, source performance, ICP performance, team activity, AI credits.
3. [ ] Date-range filters; **CSV export** of any report; charts per the `dataviz` guidance.
**Data model:** optional `report_rollups`.
**Tests:** aggregation correctness; date filter; CSV export; tenancy.
**Acceptance:** dashboard accurate + exportable; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 13 of mvp.md (Reporting & Analytics). Read the module doc; load the dataviz skill for charts. Build the aggregation + dashboard + filters + CSV export, and tests."*
**Risks:** aggregation performance (consider rollups); chart accessibility.

---

### Phase 14 — AI Insights (light)
**Goal:** Stored, dismissable insights — stale-lead alerts and a daily "leads to contact today" digest.
**Depends on:** 13, 9, 5.
**Modules & docs:** AI Insights (`ai-insights.md`).
**Tasks:**
1. [ ] `insights` table; `GeneratePeriodicInsightsJob` (scheduled) → `insights.periodic_digest`.
2. [ ] MVP insight types: stale-lead alert, "contact today" list; feed into Notifications.
3. [ ] Insights feed UI (dismiss); (one-click ICP apply deferred to post-MVP).
**Data model:** `insights`.
**Tests:** job generates insights (faked AI); dismiss; digest notification; tenancy.
**Acceptance:** daily digest + stale alerts delivered and dismissable; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 14 of mvp.md (AI Insights light). Read the module doc. Build insights + the scheduled digest job + feed UI, wire Notifications, and test with faked AI."*
**Risks:** keep AI cost bounded (scheduled, cached); avoid noisy insights.

---

### Phase 15 — Compliance & Privacy features
**Goal:** System-enforced suppression (global+team), opt-out, retention purge, LIA record, team export, privacy notice, basic DSAR.
**Depends on:** 7, 8, 10b, 11.
**Modules & docs:** Compliance & Privacy (`compliance-privacy.md`), `docs/compliance.md`.
**Tasks:**
1. [ ] `suppressions` (global+team, identity hash) checked by Outreach; opt-out writes **global**.
2. [ ] `retention_policies` + `PurgeExpiredDataJob` (scheduled, audited).
3. [ ] **LIA record** gating discovery; market-aware outreach consent gate (UK LI vs UAE prior-consent).
4. [ ] **Team data export**; hosted **privacy notice**; DSAR locate+erase (MVP-light, audited).
**Data model:** `suppressions`, `retention_policies`, `lia_records`.
**Tests:** suppressed identity blocks outreach across teams; retention purge; LIA gate blocks discovery; export; DSAR locate; tenancy.
**Acceptance:** compliance enforced by the system; gates pass; docs updated. ⚖️ legal review flagged.
**Dispatch prompt:** *"Execute Phase 15 of mvp.md (Compliance & Privacy). Read compliance-privacy.md + docs/compliance.md. Build suppression (global+team), opt-out, retention purge, LIA gate, team export, privacy notice, and light DSAR, with tests. Flag items needing legal review."*
**Risks:** legal correctness (⚖️ counsel); retro-fitting the gates into Lead Sourcing/Outreach — ensure hooks exist.

---

### Phase 16 — Billing & Plans
**Goal:** Stripe/Cashier plans with trials, seats, lead + AI credit allowances, overage, portal, and enforcement.
**Depends on:** 1, 5, 7.
**Modules & docs:** Billing & Plans (`billing-plans.md`).
**Tasks:**
1. [ ] `composer require laravel/cashier`; Cashier wiring; plans config (tiers, prices, allowances, seats).
2. [ ] `UsageMeter` (leads + AI credits per period); replace the stubbed guards in AI Platform + Lead Sourcing with real `EnforcesPlanLimits`.
3. [ ] Trials; metered **overage**; Stripe customer portal; webhooks sync.
4. [ ] Billing settings UI (web only); invoices.
**Data model:** Cashier tables; `usage_meters`.
**Tests:** plan limits block over-quota; trial; webhook sync (faked Stripe); seat enforcement on invite; tenancy.
**Acceptance:** subscriptions + credits + enforcement work with Stripe faked; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 16 of mvp.md (Billing & Plans). Read billing-plans.md. Install Cashier, define plans/allowances/seats, build UsageMeter + real EnforcesPlanLimits, overage, portal, webhooks, UI, and tests with Stripe faked."*
**Risks:** Stripe test-mode setup; VAT (UK/UAE) deferred (⚖️); credit→cost mapping to protect margin.

---

### Phase 17 — API v1 hardening
**Goal:** A consistent, documented, rate-limited `/api/v1` ready for mobile.
**Depends on:** all feature phases that expose endpoints.
**Modules & docs:** all; ADR 0001.
**Tasks:**
1. [ ] Audit every `/api/v1` route: API Resources only, consistent error envelope, pagination, token abilities, rate limits.
2. [ ] OpenAPI/Scramble docs; version header policy; shared TS types in `resources/js/shared/`.
3. [ ] Contract tests for the endpoints the mobile app needs.
**Data model:** none.
**Tests:** auth/validation/tenancy/pagination per endpoint; error envelope shape.
**Acceptance:** API consistent + documented + covered; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 17 of mvp.md (API v1 hardening). Audit all /api/v1 endpoints for resources/errors/pagination/rate-limits/abilities, generate API docs, export shared TS types, and add contract tests."*
**Risks:** drift between web and API behaviour — both must call the same Actions.

---

### Phase 18 — Mobile app (Capacitor + Ionic Vue)
**Goal:** The `/mobile` app: auth, dashboard, leads list/detail, pipeline moves, notes/tasks, log call/email, AI draft, push.
**Depends on:** 17, 12.
**Modules & docs:** Mobile App (`mobile-app.md`).
**Tasks:**
1. [ ] Scaffold `/mobile` (Capacitor + Ionic Vue + TS) importing `resources/js/shared/` via alias.
2. [ ] Token auth (+2FA), dashboard KPIs, leads list/detail, pipeline move, notes/tasks, log attempt, AI draft.
3. [ ] **Push** via FCM/APNs wired to Notifications; RTL/Arabic.
4. [ ] iOS + Android build configs.
**Data model:** none (consumes API).
**Tests:** shared code unit tests; API contract reuse; smoke e2e where feasible.
**Acceptance:** app runs on both platforms against `/api/v1`; push works; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 18 of mvp.md (Mobile app). Read mobile-app.md. Scaffold /mobile with Capacitor + Ionic Vue reusing shared code, build the core screens against /api/v1, wire push, and set up iOS/Android builds."*
**Risks:** FCM/APNs credentials + Apple/Google accounts; Capacitor native build on Windows (may need macOS for iOS).

---

### Phase 19 — Admin panel (internal)
**Goal:** Internal super-admin: teams, users, plans, AI cost, provider health, failed jobs, impersonation (audited).
**Depends on:** 16, 5, 20 (Horizon/Pulse).
**Modules & docs:** Admin (`admin.md`), ADR 0009.
**Tasks:**
1. [ ] Confirm Filament v5 installs on L13/PHP 8.5 (else simple Inertia admin — ADR 0009).
2. [ ] Admin guard + `super_admin`; resources: Teams, Users, Plans, AI Usage/Cost, Provider Health, Failed Jobs (Horizon), Audit Log.
3. [ ] **Impersonation** with banner + full audit.
**Data model:** none new (reads existing).
**Tests:** admin access control; impersonation audited; non-admin blocked.
**Acceptance:** ops can run the platform; gates pass; docs updated.
**Dispatch prompt:** *"Execute Phase 19 of mvp.md (Admin panel). Read admin.md + ADR 0009. Install Filament v5 (or fall back to Inertia admin), build the internal resources + impersonation with audit, and tests."*
**Risks:** Filament + L13 compat (verify first); keep internal panel isolated from tenant UI.

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
**Dispatch prompt:** *"Execute Phase 20 of mvp.md (Observability & security hardening). Wire Sentry + Pulse, add security headers/rate-limits, set up dependency/secret scanning in CI, and run a security review; fix findings."*
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
**Dispatch prompt:** *"Execute Phase 21 of mvp.md (Hetzner deployment & CI/CD). Provision the server, set up Nginx/PHP-FPM/MySQL/Redis/Supervisor/TLS/backups, build the deploy pipeline, and complete deployment.md."*
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
**Dispatch prompt:** *"Execute Phase 22 of mvp.md (Launch checklist). Work the checklist, confirm legal/compliance/billing/accessibility/brand/observability, and record sign-off."*
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
