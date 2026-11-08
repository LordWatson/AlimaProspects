# AlimaProspects — Pre-Launch Plan (PL0–PL12)

> **Purpose.** Close the launch-blocking gaps found in the competitive gap analysis (Nov 2026) before go-live:
>
> 1. Every lead explains **why now** (evidence, source, date, confidence) and noise is suppressed.
> 2. Leads come with **verified contact data** via a multi-vendor waterfall, metered against plan allowances.
> 3. Leads can **leave the app**: CSV export, outbound webhooks (Zapier-ready), Pipedrive and HubSpot sync.
> 4. A **free sample report** replaces a free trial and feeds the Instagram `SAMPLE` funnel.
> 5. A **trust centre** and the compliance plumbing behind it (suppression, opt-out, TPS/CTPS, PECR subscriber type, Article 14 notices).
>
> **Out of scope (post-launch, days 30–90):** outcome tracking and ROI dashboard, weekly digest/push, AI-drafted openers, vertical packs, push-to-Instantly/lemlist, done-for-you tier, website-visitor signals. Where a pre-launch phase should leave a hook for one of these, the phase says so.
>
> **Lives alongside:** `mvp.md`, `signals-plan.md`, `lead-sources-plan.md`. Phase IDs use the `PL` prefix so they don't clash with `L0–L14`.

---

## 0. How Claude Code must work through this plan

These rules apply to **every** phase. If they conflict with the repo's `CLAUDE.md`, the docs/ADRs or the existing conventions, **the repo wins**: record the conflict in the phase's ADR and carry on.

1. **One phase per session.** Run the phase's dispatch prompt, finish it, run the full test suite, update docs, then **stop and report**. Don't start the next phase.
2. **Follow the existing architecture.** Thin controllers → Form Requests → Actions/Services. DB transactions around multi-write operations. Business logic is shared by web (Inertia/Vue) and mobile (Capacitor/Ionic, via Sanctum API), never duplicated.
3. **Multi-tenancy is non-negotiable.** Every new tenant-owned table has `team_id` (indexed, FK) and goes through the project's existing tenant scope/trait. Every new model gets a Policy. Every phase adds **tenant-isolation tests**: team A can't read, update, export, sync or enumerate team B's records through any route, API endpoint, job or broadcast channel.
4. **No real external calls in tests.** Each external vendor sits behind an interface, with a `Fake` implementation and `Http::fake()` fixtures. Use Pest (or whatever the repo uses) for feature and unit tests.
5. **Config over hard-coding.** Vendor order, weights, thresholds, costs and limits live in `config/*.php`, backed by `.env`. No vendor API keys in code, tests or docs.
6. **Feature flags.** Every user-facing feature in this plan ships behind a Laravel Pennant flag (`prelaunch.why-now`, `prelaunch.enrichment`, `prelaunch.export`, `prelaunch.webhooks`, `prelaunch.pipedrive`, `prelaunch.hubspot`, `prelaunch.sample-report`, `prelaunch.trust`) so it can be dark-launched and switched off without a deploy.
7. **Queues.** External I/O runs in queued jobs on named Horizon queues (`enrichment`, `integrations`, `webhooks`, `sample-reports`). Jobs are idempotent and set `tries`, `backoff` and `timeout`, plus a `failed()` handler that logs structured context.
8. **Observability.** Structured logs (channel and context keys matching the repo convention), Sentry breadcrumbs on vendor failures, and Pulse cards where useful.
9. **Docs.** Each phase updates or creates `docs/modules/<module>.md` and adds an ADR (`docs/adr/NNNN-*.md`) for each decision it makes. Append a changelog line to `docs/prelaunch/progress.md`.
10. **Migrations** are additive and reversible. Never edit a migration that has shipped.
11. **Money and credits** are stored as integers (pence, millicredits). Never floats.
12. **PII.** Encrypt credentials at rest (`encrypted` casts). Don't log raw emails or phone numbers: log a hash, or mask them (`a***@domain.com`).
13. **Commits.** Commit at logical checkpoints with conventional-commit messages scoped by phase, e.g. `feat(PL3): waterfall enrichment job`.

---

## 1. Phase map and dependencies

| Phase | Name                                                                  | Depends on     | Size |
| ----- | --------------------------------------------------------------------- | -------------- | ---- |
| PL0   | Recon, alignment and vendor decisions                                 | —              | S    |
| PL1   | Lead evidence and "why now"                                           | PL0            | L    |
| PL2   | Contact model and enrichment provider contracts                       | PL0            | M    |
| PL3   | Waterfall enrichment orchestration                                    | PL2            | L    |
| PL4   | Usage ledger, plan gating and top-ups                                 | PL0 (uses PL3) | L    |
| PL5   | Compliance core: suppression, opt-out, TPS/CTPS, PECR subscriber type | PL2            | L    |
| PL6   | CSV export                                                            | PL1, PL4, PL5  | M    |
| PL7   | Integration framework and outbound webhooks                           | PL5            | L    |
| PL8   | Pipedrive sync                                                        | PL7            | M    |
| PL9   | HubSpot sync                                                          | PL7            | M    |
| PL10  | Free sample report                                                    | PL1, PL4       | L    |
| PL11  | Trust centre, legal pages, Article 14 notices                         | PL5            | M    |
| PL12  | Hardening, QA and go/no-go                                            | all            | M    |

**Recommended order:** PL0 → PL1 → PL2 → PL3 → PL4 → PL5 → PL6 → PL7 → PL8 → PL9 → PL10 → PL11 → PL12.
PL10 and PL11 can run in parallel with PL7–PL9 if you use separate worktrees.

---

## 2. Things only you (Alex) can do

Start these now. Several take days.

- [ ] **Pick enrichment vendors and confirm their terms allow embedding/reselling data inside a SaaS product** (written confirmation where possible). Candidates to evaluate: Prospeo (email finder), FullEnrich or BetterContact (waterfall email + mobile), a verifier (e.g. ZeroBounce / MillionVerifier / NeverBounce), and optionally Crustdata (later, for job-change webhooks). **Don't use Apollo:** its standard plans are internal-use only. Record the choices in the PL0 ADR.
- [ ] Create vendor accounts and API keys. Note each vendor's per-call costs; PL4 needs them.
- [ ] **TPS/CTPS:** get access to TPS + CTPS screening (a licensed file from the TPS Ltd, or a screening API provider). Confirm the licence allows automated screening inside a product.
- [ ] **UAE:** confirm with a UAE adviser whether TDRA's Do Not Call Registry (DNCR) applies to your customers' outreach, and what the UAE PDPL requires for B2B prospecting data. Record the outcome in the PL5 ADR.
- [ ] **Pipedrive:** register a Developer Hub app (OAuth). Note the client ID/secret and set the redirect URL.
- [ ] **HubSpot:** create a developer account and a public app (OAuth). Note the scopes in PL9.
- [ ] **Cloudflare Turnstile** (or hCaptcha) site key and secret for the sample report.
- [ ] Stripe: create the top-up products/prices (PL4).
- [ ] **Legal review** of the trust centre, privacy notice, DPA template, Article 14 notice and lawful-basis wording (PL11). This plan builds the pages; a lawyer signs off the words.
- [ ] Confirm the Hetzner data-centre region (e.g. Falkenstein/Nuremberg DE, Helsinki FI) for the hosting statement.

---

## PL0 — Recon, alignment and vendor decisions

**Goal.** Map the plan onto the real codebase before writing feature code. No feature code in this phase.

**Tasks**

1. Read `CLAUDE.md`, `mvp.md`, `signals-plan.md`, `lead-sources-plan.md`, `docs/**` and every ADR.
2. Inventory and document in `docs/prelaunch/recon.md`:
    - Lead, Business/Company, Contact (if any), Signal, ICP/Profile, Team, Plan/Subscription models: tables, key columns, relations, scopes.
    - How a Signal converts into a Lead today (Action name, what is copied across).
    - Lead scoring: where it happens, inputs, outputs, stored fields.
    - AI usage: the service wrapper, how tokens are counted and limited today, model config.
    - Billing: Cashier setup, plans, how limits are expressed (Pennant? config? DB?), the existing "token top-up" approach.
    - Existing `LeadSourceProvider` / `SignalSource` contracts, enums, DTOs, config layout.
    - Frontend: lead list/detail Vue pages and components, the design system / brand tokens from `docs/brand`, mobile app structure.
    - Queues, Horizon config, Reverb channels, Sentry/Pulse setup.
    - Test setup: Pest version, factories, existing fakes.
3. Produce a **gap table**: each PL phase vs what already exists (reuse / extend / new).
4. Write `docs/adr/NNNN-prelaunch-scope.md`, covering the scope above, phase order, and naming decisions that adapt this plan to the repo (e.g. if `contacts` already exists, PL2 extends it; if limits already live in a `plan_limits` table, PL4 uses that).
5. Write `docs/adr/NNNN-enrichment-vendors.md` as a placeholder listing candidate vendors, the "embedding allowed" requirement and a `DECISION PENDING` marker for Alex to fill in.
6. Create `docs/prelaunch/progress.md`.

**Acceptance criteria**

- `recon.md` and the gap table exist and name real classes, files and tables.
- Every later phase has a short "adapt" note in `recon.md` (e.g. "PL2: extend `App\Models\Contact`, which already has `email`").
- No application code changed.

**Dispatch prompt**

```
Read prelaunch-plan.md section 0 and phase PL0. Execute PL0 only.
Inspect the codebase and existing docs/plans; write docs/prelaunch/recon.md (inventory + gap table +
per-phase adapt notes), the two ADRs, and docs/prelaunch/progress.md. Do not change application code.
Report the gap table and anything in the plan that contradicts the codebase, then stop.
```

---

## PL1 — Lead evidence and "why now"

**Goal.** Every lead shows dated, sourced reasons it is worth contacting **now**, plus a confidence score. Low-confidence leads are hidden by default, and users can flag wrong signals.

### Data model

- `lead_evidence`
    - `id`, `team_id`, `lead_id` (FK, cascade)
    - `kind` enum `EvidenceKind`: `signal`, `firmographic`, `web_research`, `ai_inference`, `register_record`
    - `source` enum `EvidenceSource` (reuse/extend the existing source enums: `companies_house`, `land_registry`, `serper_news`, `serper_web`, `google_places`, `osm`, `difc`, `adgm`, `dmcc`, `contracts_finder`, `planit`, `gazette`, `adzuna`, `reed`, `manual`, …)
    - `signal_id` nullable FK (when it came from a Signal)
    - `title` (short, human: "New director appointed")
    - `summary` (1–2 sentences)
    - `source_url` nullable, `source_label` nullable ("Companies House")
    - `observed_at` (when the event happened), `captured_at` (when we found it)
    - `reliability` int 0–100 (from config at capture time)
    - `payload` json (raw, for audit and debugging)
    - indexes: `(team_id, lead_id)`, `(lead_id, observed_at)`
- `leads` additions:
    - `fit_score` int 0–100 (if scoring already outputs one, map to it)
    - `timing_score` int 0–100
    - `confidence` int 0–100
    - `why_now_summary` text nullable, `why_now_generated_at` timestamp nullable, `why_now_evidence_hash` string nullable (cache key)
    - `suppressed_low_confidence` bool, default false (computed)
- `lead_evidence_feedback`: `id`, `team_id`, `lead_evidence_id`, `user_id`, `verdict` enum (`wrong`, `outdated`, `irrelevant`, `useful`), `note` nullable, timestamps.

### Config — `config/evidence.php`

- `reliability` per source (defaults: registers such as Companies House, Land Registry and the free-zone registers 95; contract/planning/gazette 90; job boards 80; Google Places 75; Serper news 60; Serper web 45; AI inference 35).
- `recency_half_life_days` per evidence kind (signal 30, firmographic 365, web_research 90).
- `corroboration_bonus` (e.g. +10 per extra independent source, max +20).
- `default_min_confidence` = 50 (per-team override in team settings).
- `why_now.max_evidence_items` = 5, `why_now.model` (reuse the AI config key).

### Actions / services

1. `RecordLeadEvidence` — creates evidence from a DTO, sets `reliability` from config, dedupes on (`lead_id`, `source`, `source_url` or a payload hash), then dispatches `RecalculateLeadConfidence`.
2. **Signal → Lead conversion:** extend the existing conversion Action so the signal becomes `lead_evidence` (kind `signal`, `signal_id` set). Backfill command: `php artisan evidence:backfill` builds evidence for existing leads from their signals and source data. Must be idempotent.
3. **Lead sourcing:** each `LeadSourceProvider` result records at least one `register_record` or `firmographic` evidence row. Update the provider contract or DTO to carry `evidenceItems[]`.
4. `CalculateLeadConfidence` (pure, unit-tested): `confidence = clamp( max_item_score + corroboration_bonus, 0, 100 )`, where `item_score = reliability × recency_decay(observed_at)`. `timing_score` = the strongest decayed signal-kind item. Sets `suppressed_low_confidence` against the team threshold.
5. `GenerateWhyNowSummary` (queued, `ai` queue): takes the top N evidence items and the team ICP, and returns 1–2 sentences that must reference only the supplied evidence. Prompt rules: no invented facts, mention the date, name the source. Skips the call if `why_now_evidence_hash` hasn't changed. Counts tokens through the existing AI usage path (and through the PL4 ledger once it exists, via a hook/interface now).
6. `SubmitEvidenceFeedback` stores feedback. If any evidence item gets a `wrong` verdict, its contribution drops to 0 for that lead and confidence is recalculated. Nightly `evidence:source-quality` command aggregates `wrong` rates per source into a report table or log for admin review. It doesn't auto-change config yet.

### UI (web + mobile)

- **Lead card (list):** a "Why now" line (the summary, or the top evidence title if there's no summary yet), confidence pill (High ≥75, Medium 50–74, Low <50), relative date of the freshest evidence, source label chips.
- **Lead detail:** "Why now" panel: summary, evidence timeline (newest first) with title, date, source label, outbound link (`rel="noopener nofollow"`), a reliability hint, and a "Flag" menu (wrong / outdated / irrelevant / useful).
- **Lead list:** "Hidden: N low-confidence leads — show" toggle, sort by timing or confidence, and filters by evidence source and kind.
- **Team setting:** minimum confidence slider (30–90).
- Mobile (Capacitor/Ionic) gets the same card and detail panel, using the shared API resources.

### API

- `LeadResource` includes `fit_score`, `timing_score`, `confidence`, `why_now_summary` and `evidence[]` (detail only).
- `POST /api/leads/{lead}/evidence/{evidence}/feedback`.

### Tests

- Unit: confidence maths (decay, corroboration, clamp, threshold), dedupe rules.
- Feature: signal conversion creates evidence; the backfill is idempotent; the summary job doesn't run when the hash is unchanged; the AI fake receives only the supplied evidence; feedback recalculates confidence; the suppression toggle works.
- Tenant isolation on evidence, feedback, and the API.

### Acceptance criteria

- Every new lead has ≥1 evidence item and a confidence score.
- Lead cards show a why-now line within one queue cycle of creation.
- Low-confidence leads are hidden by default and can be revealed.
- Flagging evidence as wrong lowers confidence immediately.
- Behind flag `prelaunch.why-now`.

### Risks

AI hallucination: the prompt and a test assert the summary only uses supplied facts, and the UI always shows the raw evidence beside the summary. Cost: hash-gated regeneration.

### Hook for later

The `useful`/`wrong` feedback and evidence-per-lead feed the post-launch outcome-tracking and scoring feedback loop.

**Dispatch prompt**

```
Read prelaunch-plan.md section 0, docs/prelaunch/recon.md and phase PL1. Execute PL1 only, adapting names
to the existing codebase per recon.md. Build the lead_evidence model, confidence calculation, signal→lead
evidence carry-over, backfill command, why-now AI summary job, feedback, and the web + mobile UI behind
Pennant flag prelaunch.why-now. Full tests including tenant isolation. Update module docs + ADR + progress.md.
Run the full suite, report, stop.
```

---

## PL2 — Contact model and enrichment provider contracts

**Goal.** A clean contact model with verification status, plus vendor-agnostic enrichment interfaces. No orchestration yet.

### Data model (extend `contacts` if it exists)

- `contacts`: `id`, `team_id`, `lead_id` (FK), `first_name`, `last_name`, `full_name`, `job_title`, `seniority` enum nullable, `department` nullable, `linkedin_url` nullable
    - Email: `email` (encrypted? see ADR — encrypting breaks dedupe and search, so store `email` plain + `email_hash` (sha256 lowercase) for lookup, and decide in the ADR), `email_status` enum `EmailStatus`: `unknown`, `verified`, `accept_all`, `risky`, `invalid`, `not_found`; `email_verified_at`, `email_source` (provider key)
    - Phone: `phone_e164` nullable, `phone_hash`, `phone_type` enum (`mobile`, `direct`, `switchboard`, `unknown`), `phone_status` enum (`unknown`, `found`, `invalid`, `not_found`), `phone_source`, `phone_found_at`
    - Compliance (filled in PL5): `tps_status` enum (`unchecked`, `clear`, `registered`, `not_applicable`), `tps_checked_at`, `pecr_subscriber_type` enum (`corporate`, `individual`, `unknown`)
    - `revealed_email_at`, `revealed_phone_at` (user-initiated reveals, for metering)
    - `enriched_at`, `enrichment_state` enum (`idle`, `queued`, `running`, `done`, `failed`)
    - unique: `(team_id, lead_id, email_hash)` where `email_hash` is not null
- Masking: `Contact::maskedEmail()` and `maskedPhone()` accessors. API resources return masked values until a reveal has happened.

### Contracts (`app/Modules/Enrichment/Contracts` or the repo's equivalent)

```php
interface EmailFinder    { public function findEmail(PersonQuery $q): EnrichmentResult; }
interface EmailVerifier  { public function verifyEmail(string $email): VerificationResult; }
interface PhoneFinder    { public function findPhone(PersonQuery $q): EnrichmentResult; }
interface PersonFinder   { public function findPeople(CompanyQuery $q, PersonCriteria $c): PeopleResult; } // decision-makers at a company
```

- DTOs (readonly): `PersonQuery` (first/last/full name, company name, domain, linkedin_url, country), `CompanyQuery` (name, domain, company_number, country), `PersonCriteria` (titles[], seniorities[], departments[], limit), `EnrichmentResult` (value, status, confidence 0–100, provider, costMillicredits, raw), `VerificationResult`, `PeopleResult`.
- `ProviderCapability` enum and a `ProviderRegistry` that resolves providers from `config/enrichment.php`.
- Every adapter implements a `describesCost(): CostProfile` (per success / per attempt, in millicredits and pence) so PL4 can meter it.

### Adapters

- One `Fake*` per interface (deterministic, configurable outcomes) for tests and local demo seeding.
- Real adapters for the vendors chosen in the PL0 ADR. If the ADR still says `DECISION PENDING`, build **one** reference adapter for a mainstream email finder + verifier using its public API docs, behind config, and mark it `experimental` in the ADR.
- Each adapter: `Http` client with timeout, retry on 429/5xx with backoff, maps vendor statuses to `EmailStatus`/`PhoneStatus`, and never throws on "not found" (returns status `not_found`).

### Config — `config/enrichment.php`

- `providers` keyed by name: class, enabled, api_key env, rate limit (per minute), cost profile.
- `waterfall.email_find` (ordered provider list), `waterfall.email_verify`, `waterfall.phone_find`, `waterfall.people_find`.
- `stop_on` (e.g. `verified` for email, `found` for phone).
- `reenrich_after_days` = 30.

### Tests

Adapter mapping with `Http::fake()` fixtures per vendor; registry resolution; masking; dedupe uniqueness; tenant isolation.

### Acceptance criteria

- Contracts, DTOs, registry, fakes and at least one real adapter set exist.
- Contacts are masked by default in all API resources.
- No user-facing change except masked display.

**Dispatch prompt**

```
Read prelaunch-plan.md section 0, recon.md, the enrichment-vendors ADR and phase PL2. Execute PL2 only:
contact model (extend existing if present), enums, masking, enrichment contracts/DTOs/registry, fakes, and
real adapters for the vendors chosen in the ADR (or one experimental reference adapter if pending).
Tests with Http::fake fixtures. Update docs + ADR + progress.md. Run suite, report, stop.
```

---

## PL3 — Waterfall enrichment orchestration

**Goal.** Find decision-makers and verified contact details for a lead by trying vendors in order. Stop at the first good result, record every attempt, and update the UI live.

### Data model

- `enrichment_attempts`: `id`, `team_id`, `contact_id` nullable, `lead_id`, `capability`, `provider`, `outcome` enum (`hit`, `miss`, `error`, `skipped_rate_limited`, `skipped_circuit_open`), `status_returned`, `latency_ms`, `cost_millicredits`, `cost_pence`, `error_code` nullable, `created_at`. Indexed for reporting.
- `enrichment_runs`: `id`, `team_id`, `lead_id`, `requested_by` user, `scope` enum (`people`, `email`, `phone`, `verify`), `state`, `started_at`, `finished_at`, `summary` json.

### Actions / jobs

1. `RequestLeadEnrichment(lead, scope, user)`: checks the flag, plan entitlement and suppression (PL5 hook: interface now, implemented in PL5), and whether the lead was enriched recently (`reenrich_after_days`). Creates `enrichment_run`, reserves credits (PL4 hook: `CreditReserver` interface with a no-op default until PL4), and dispatches `RunEnrichmentWaterfall`.
2. `RunEnrichmentWaterfall` job (`enrichment` queue):
    - `people`: run `PersonFinder` providers in order until N decision-makers matching the team ICP's target titles/seniority are found (N from config/plan, default 3). Create or update contacts.
    - `email`: per contact, `EmailFinder` waterfall → `EmailVerifier` waterfall on the found address → stop when `verified` (or `accept_all` if config allows).
    - `phone`: `PhoneFinder` waterfall. Normalise to E.164 with `giggsey/libphonenumber-for-php` (add if missing). Classify `phone_type`.
    - Each step: per-provider Redis rate limiter (`RateLimiter::attempt` or `Redis::throttle`); a **circuit breaker** (open after X consecutive errors in Y minutes, skip the provider for Z minutes, stored in cache); attempts recorded; costs summed.
    - On finish: commit actual costs (release unused reservation), update `enrichment_state`, record evidence (PL1) for any firmographic data returned, broadcast `LeadEnrichmentUpdated` on the team's private Reverb channel.
3. **Bulk:** `RequestBulkEnrichment(leadIds[])` fans out with `Bus::batch`, honours plan limits, and shows progress via a broadcast.
4. **Idempotency:** a unique job per `(lead_id, scope)` (`ShouldBeUnique`). Re-running within the window is a no-op that returns the existing result.

### UI

- Lead detail **Contacts** panel: decision-makers list; per contact, a masked email/phone with **Reveal** buttons (showing the credit cost), status badges (Verified ✓ / Accept-all / Risky / Not found), source and date on hover.
- "Find decision-makers" button when there are no contacts. Live spinner/progress via Reverb.
- Bulk action on the lead list: "Enrich selected (est. X credits)".
- Mobile: Reveal buttons + tap-to-call / tap-to-email / WhatsApp deep link (`https://wa.me/<e164>`) after reveal.

### Admin

Admin panel page: attempts per provider (hit rate, avg latency, avg cost, error rate) for the last 7/30 days; circuit-breaker states; manual "reset circuit".

### Tests

Waterfall stops at the first verified result; falls through on miss/error; the rate limiter and circuit breaker skip correctly; costs summed and committed; uniqueness; bulk batch honours limits; broadcast fired; tenant isolation on runs/attempts and on the broadcast channel authorisation.

### Acceptance criteria

- From a lead, a user can find decision-makers and reveal a verified email in one or two clicks, with live UI updates.
- Every vendor call is recorded with its cost.
- Turning one vendor off in config doesn't break the flow.
- Behind flag `prelaunch.enrichment`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL3. Execute PL3 only: enrichment runs/attempts, request Action,
waterfall job with rate limiting + circuit breaker, bulk batching, Reverb broadcasts, Contacts panel with
reveal UI (web + mobile), admin provider stats. Use CreditReserver and SuppressionChecker interfaces with
no-op defaults (implemented in PL4/PL5). Tests incl. tenant isolation. Docs + ADR + progress.md. Stop.
```

---

## PL4 — Usage ledger, plan gating and top-ups

**Goal.** One ledger meters AI tokens, enrichment reveals, verifications and sample reports against plan allowances. It supports top-ups and protects unit economics.

### Data model

- `usage_ledger` (append-only): `id`, `team_id`, `meter` enum `UsageMeter` (`ai_tokens`, `people_search`, `email_reveal`, `phone_reveal`, `email_verification`, `export_rows`, `crm_sync`, `sample_report`), `direction` enum (`reserve`, `commit`, `release`, `grant`, `topup`, `adjustment`), `quantity` int, `cost_pence` int (our vendor cost, for margin reporting), `period` (e.g. `2026-11` aligned to the billing cycle), `reference_type`/`reference_id` (morph: enrichment_run, export, ai_call…), `meta` json, `created_at`.
- `team_allowances` (or reuse the existing limits store): `team_id`, `meter`, `period`, `included`, `topped_up`, `used`, `reserved`. A cached projection of the ledger, rebuildable via `usage:rebuild`.

### Config — `config/plans.php` (merge with the existing plan config)

- Per plan (`starter` at £49, plus any others), per meter: included quantity per period. Starter defaults to propose (Alex to confirm): `email_reveal` 250, `phone_reveal` 25, `people_search` 150, `ai_tokens` (existing value), `export_rows` 2,000, `crm_sync` 1,000, `sample_report` n/a.
- `topups`: Stripe price IDs → meter → quantity (e.g. "+250 email reveals", "+50 phone reveals", "+AI tokens").
- `margin_guard.max_vendor_cost_ratio` = 0.35 (vendor cost ÷ plan price per period, per team). Above it → admin alert, and a soft limit on further phone reveals.

### Services

- `UsageMeterService` with `reserve(team, meter, qty, ref)`, `commit(reservation, actualQty, costPence)`, `release(reservation)`, `grant(team, meter, qty, period)`, `remaining(team, meter)`. Uses DB transactions with a row lock on the `team_allowances` row so concurrent reserves can't overspend.
- Implement PL3's `CreditReserver` with this service.
- Wire the existing AI token accounting into `ai_tokens` (keep the old path working, or migrate it; decide in the ADR).
- **Period rollover:** a listener on the Cashier subscription renewal webhook, or a scheduled job, grants the new period's allowances. Unused allowances don't roll over; top-ups last until the end of the next period (decide and record in the ADR).
- **Top-ups:** Stripe Checkout (Cashier `checkout()` for one-off prices) → webhook → `grant(direction=topup)`. Idempotent on the Stripe event ID.
- Pennant features or Gates: `can-reveal-phone`, `can-export`, `can-sync-crm`, resolved from plan + remaining.

### UI

- Billing/usage page: a meter bar per meter (used / included + top-up), reset date, "Buy more" buttons.
- Inline: reveal buttons show cost and remaining; when there's no allowance, an upgrade/top-up modal.
- Low-balance banner at 80%, and an email at 80% and 100% (respect notification preferences).
- Admin: per-team vendor cost vs MRR, margin-guard breaches list.

### Tests

Concurrency (two parallel reserves for the last unit, so only one succeeds); release on failure; commit with an actual quantity below the reserved one; rollover; top-up webhook idempotency; gates; tenant isolation.

### Acceptance criteria

- No paid vendor call can happen without a successful reservation.
- Usage is visible to the customer, and margin to admin.
- Top-up purchase works end-to-end in Stripe test mode.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL4. Execute PL4 only: usage ledger + allowance projection, meter
service with locking, plan config merge, AI token wiring, rollover, Stripe top-ups via Cashier,
gates/Pennant, usage UI, low-balance notices, admin margin view. Implement CreditReserver from PL3.
Tests incl. concurrency + tenant isolation. Docs + ADR + progress.md. Stop.
```

---

## PL5 — Compliance core: suppression, opt-out, TPS/CTPS, PECR subscriber type

**Goal.** Enforce, in code, the rules that make the product safe to sell in the UK and Dubai. Every reveal, export, sync and webhook passes through one gate.

### Data model

- `suppression_entries`: `id`, `scope` enum (`platform`, `team`), `team_id` nullable (required when scope = team), `type` enum (`email`, `email_domain`, `phone`, `company_number`, `person_name_company`), `value_hash` (sha256 normalised), `reason` enum (`opt_out_request`, `dsar_erasure`, `customer_added`, `bounce_hard`, `complaint`, `legal`), `source_note`, `created_by` nullable, timestamps. Unique: `(scope, team_id, type, value_hash)`.
- `data_subject_requests`: `id`, `type` enum (`opt_out`, `access`, `erasure`, `rectification`), `email`, `name`, `company`, `details`, `verified_at`, `status` enum (`pending_verification`, `open`, `completed`, `rejected`), `handled_by`, `completed_at`, timestamps. Platform-level (not tenant-scoped); admin only.
- `tps_checks`: `id`, `phone_hash`, `register` enum (`tps`, `ctps`), `result` enum (`clear`, `registered`), `checked_at`, `provider`. Shared cache keyed by phone hash (not tenant data; record this in the ADR).

### Services

1. `SuppressionChecker` (implements the PL3 interface): `isSuppressed(Contact|Lead|string, Context)` checks platform + team entries across email, domain, phone, company number. Used by: reveal, export, CRM sync, webhooks, sample report, people search results (suppressed people never become contacts).
2. **PECR subscriber type** — `ClassifySubscriberType` for UK leads:
    - Companies House type `ltd`, `plc`, `llp` and similar → `corporate`.
    - Sole trader / partnership / unincorporated (no company number, or Places-only small businesses) → `individual` or `unknown`.
    - The rule surfaced in the UI and exports: **unsolicited marketing email/SMS to `individual` subscribers needs prior consent**, while corporate subscribers can be emailed with an opt-out (generic PECR position; legal review in PL11). Show a warning badge and include a `pecr_subscriber_type` column in exports and syncs. Don't hard-block (it's the customer's decision); record the acknowledgement.
3. **TPS/CTPS screening** — `TpsScreeningProvider` interface + fake + a real adapter once the licence is confirmed. Screen UK numbers on reveal, and re-screen if `tps_checked_at` is older than 28 days at export or sync time. Registered numbers show a "Do not cold call — TPS/CTPS" badge, are excluded from "call" deep links, and are marked in exports.
4. **UAE:** a `UaeOutreachPolicy` placeholder that applies whatever the adviser confirms (e.g. DNCR); until then, a UI note on UAE phone reveals: "Check DNCR before telemarketing". Record in the ADR.
5. **Public opt-out and DSAR flow** (`/privacy/request`, no auth):
    - Form: request type, email, name, company, details, Turnstile captcha, rate limit.
    - Email verification link → `verified_at` → status `open`.
    - Opt-out/erasure on verification automatically creates **platform-scope** suppression entries (email + optional phone) and queues `PurgeSubjectData`: delete or anonymise matching contacts across all teams, remove them from caches, and log counts (not values).
    - Access requests → admin queue with an "export this subject's data" helper.
    - Admin screen: list, filter, complete, with SLA timers (respond within one month).
6. **Team suppression UI:** the customer adds their own do-not-contact list (CSV upload of emails, domains, phones, company numbers). Hashed on import; raw values are not stored.
7. **Hard bounces from verification** (status `invalid` at re-verify) → team-scope suppression `bounce_hard` (optional per config).
8. Audit log entries (reuse the existing audit mechanism if there is one) for reveals, exports, syncs and suppression changes.

### Tests

Suppression blocks reveal/export/sync/webhook paths; platform scope applies to all teams; team scope to one team only; opt-out purge removes contacts across teams; TPS re-screen after 28 days; PECR classification table tests; DSAR verification flow; captcha and rate limits; no raw suppressed values stored.

### Acceptance criteria

- One `SuppressionChecker` is called on every outbound path (an architecture test asserts the export, sync and webhook services depend on it).
- A data subject can opt out without an account and is purged within one job cycle.
- UK phone numbers carry their TPS/CTPS status.
- PECR subscriber type is shown on UK leads.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL5. Execute PL5 only: suppression model + checker (implement PL3
interface), PECR subscriber classification, TPS/CTPS provider interface + fake (+ real adapter if config
present), UAE policy placeholder, public opt-out/DSAR flow with verification and cross-tenant purge, team
suppression CSV upload, audit entries, admin DSAR screen. Architecture test that all outbound services use
SuppressionChecker. Tests incl. tenant isolation. Docs + ADR + progress.md. Stop.
```

---

## PL6 — CSV export

**Goal.** Customers can take leads, contacts and why-now evidence out as a CSV that's safe to use.

### Behaviour

- Export from the lead list (current filters or selection) and from saved lists, if those exist.
- **Column presets:** "Leads only", "Leads + contacts", "CRM-ready" (one row per contact, with company columns repeated), "Custom" (pick columns).
- Columns include: company name, domain, company number, country, address, sector, `fit_score`, `timing_score`, `confidence`, `why_now_summary`, top 3 evidence (title | date | source | url), contact name, title, email, `email_status`, phone (E.164), `phone_type`, `tps_status`, `pecr_subscriber_type`, AlimaProspects lead URL.
- **Only revealed** emails/phones are exported. Unrevealed ones are blank, and the UI offers "Reveal all before export (est. X credits)".
- Suppressed records are excluded, with a count shown in the summary.
- Meter `export_rows` (PL4). Exports up to 500 rows run synchronously; larger ones are queued (`exports` queue). The file goes on private storage with a signed URL valid for 24h, and a notification/email is sent when it's ready. Files are deleted after 7 days by a scheduled prune.
- CSV is UTF-8 with BOM (Excel-friendly for Arabic names), with CSV-injection protection: prefix cells starting with `=`, `+`, `-` or `@` with `'`.
- `exports` table: `id`, `team_id`, `user_id`, `filters` json, `preset`, `row_count`, `suppressed_count`, `path`, `expires_at`, `state`. Audit entry.

### Tests

Presets, column correctness, only revealed data, suppression exclusion, CSV-injection escaping, BOM, metering, signed URL expiry, prune, tenant isolation (team B can't download team A's file even with the path).

### Acceptance criteria

A 2,000-row export completes in the queue and downloads in Excel with correct encoding. Behind flag `prelaunch.export`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL6. Execute PL6 only: export model, presets, sync/queued generation,
signed URLs, prune, metering, suppression exclusion, CSV injection protection, UI (web; mobile can link to
web export). Tests incl. tenant isolation. Docs + progress.md. Stop.
```

---

## PL7 — Integration framework and outbound webhooks

**Goal.** A reusable base for CRM integrations, plus signed outbound webhooks (which make Zapier/Make/n8n work immediately).

### Data model

- `integrations`: `id`, `team_id`, `provider` enum (`pipedrive`, `hubspot`, later others), `status` enum (`connected`, `needs_reauth`, `error`, `disconnected`), `access_token` (encrypted), `refresh_token` (encrypted), `expires_at`, `external_account_id`, `external_account_name`, `settings` json (field mapping, auto-sync rules, pipeline/owner defaults), `last_synced_at`, `last_error`, timestamps. Unique: `(team_id, provider)`.
- `integration_links`: `id`, `team_id`, `integration_id`, `local_type` (lead/contact), `local_id`, `external_type` (organization/person/company/contact/deal/lead/note), `external_id`, `synced_hash`, `synced_at`. Unique: `(integration_id, local_type, local_id, external_type)`.
- `integration_sync_logs`: `id`, `team_id`, `integration_id`, `direction` (push), `local_type/id`, `action` (create/update/skip), `outcome`, `error`, `created_at`.
- `webhook_endpoints`: `id`, `team_id`, `url` (https only), `secret` (encrypted, generated), `events` json, `active`, `failure_count`, `disabled_at`, timestamps.
- `webhook_deliveries`: `id`, `team_id`, `webhook_endpoint_id`, `event`, `payload` json, `attempt`, `status_code`, `response_snippet` (truncated to 1KB), `delivered_at`, `next_retry_at`.

### Framework

- Interface `CrmConnector`: `authorizeUrl(state)`, `handleCallback(code)`, `refresh(integration)`, `upsertCompany(lead)`, `upsertPerson(contact, companyRef)`, `createNote(ref, text)`, `createDealOrLead(lead, refs)`, `testConnection()`.
- `OAuthStateService`: signed, single-use, 10-minute `state` bound to team + user. PKCE where the provider supports it.
- `TokenRefresher` middleware for connector HTTP calls: refresh on 401/expiry, set `needs_reauth` on refresh failure and notify team admins.
- `SyncLeadToCrm` Action → `PushLeadToCrm` job (`integrations` queue): checks suppression (PL5), meter `crm_sync` (PL4), then the connector upserts and links. A `synced_hash` skip prevents duplicate pushes.
- **Auto-sync rules** (settings): when a lead reaches `confidence ≥ X` **and** `fit ≥ Y`, or when a user marks it "Send to CRM". Defaults off.
- **Note template** pushed to the CRM: why-now summary + evidence bullets with dates and links + AlimaProspects URL.

### Outbound webhooks

- Events: `lead.created`, `lead.updated` (scores/why-now changed), `signal.detected`, `contact.enriched`, `lead.sent_to_crm`.
- Payload envelope: `{id, event, created_at, team_id (opaque public id, not the internal id), data}`. `data` uses the same API resources as the app, with masking rules applied: unrevealed contact data stays masked.
- Signing: header `X-Alima-Signature: t=<ts>,v1=<hmac_sha256(secret, ts.body)>`. Document verification in the docs and the UI.
- Delivery: queued, 5 retries with exponential backoff (1m, 5m, 30m, 2h, 12h). Auto-disable after 20 consecutive failures and email the team. "Send test event" button. Delivery log UI (last 100) with redeliver.
- **SSRF protection:** https only; resolve the host and reject private/loopback/link-local/metadata IP ranges on save **and** at send time; no redirects followed; 10s timeout.
- **Zapier/Make:** document "use Webhooks by Zapier → Catch Hook" with sample payloads (a native Zapier app is post-launch).

### UI

Settings → Integrations: cards for Pipedrive / HubSpot (connect, status, last sync, disconnect, mapping) and a Webhooks section (add endpoint, choose events, secret reveal/rotate, test, delivery log). Lead detail: "Send to CRM" button + sync status chip with a link to the external record.

### Tests

OAuth state single-use/expiry; token refresh flow; `needs_reauth`; webhook signing verification test; SSRF rejection (private IPs, DNS rebinding at send time); retry/backoff/auto-disable; masking in payloads; suppression blocks; tenant isolation on endpoints, deliveries and integrations.

### Acceptance criteria

- A webhook to a public request bin receives signed events; a test shows the signature verifies.
- The framework is ready for PL8/PL9.
- Behind flag `prelaunch.webhooks`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL7. Execute PL7 only: integrations/links/logs models, CrmConnector
contract, OAuth state + token refresh, SyncLeadToCrm action/job with suppression + metering + hash skip,
auto-sync rules, CRM note template, outbound webhooks with HMAC signing, retries, auto-disable, SSRF
protection, delivery log, settings UI. No provider-specific connector yet. Tests incl. SSRF + tenant
isolation. Docs (incl. webhook signature verification guide) + ADR + progress.md. Stop.
```

---

## PL8 — Pipedrive sync

**Goal.** One-click and rule-based push of leads and contacts into Pipedrive.

### Implementation

- `PipedriveConnector implements CrmConnector`. OAuth 2.0 via the Pipedrive Developer Hub app (client ID/secret in `.env`). Use the company-specific `api_domain` returned in the token response for API calls. Request only the scopes needed (deals/leads, contacts, and activities/notes; confirm exact scope names against current Pipedrive docs).
- Mapping:
    - Lead → **Organization** (name, address; domain/website into a custom field if configured). Dedupe: an existing link first, then search by name + domain.
    - Contact → **Person** (name, revealed email(s) and phone(s), job title, org link). Dedupe by email.
    - Lead → **Lead** in Pipedrive's Leads Inbox (default) **or** a **Deal** in a chosen pipeline/stage (setting), with the owner set from mapping.
    - **Note** with the why-now template attached to the Lead/Deal and the Org.
    - Optional custom fields (created on connect if the user opts in): `Alima confidence`, `Alima timing score`, `Alima why now`, `Alima URL`, `PECR type`, `TPS status`.
- Settings UI: pipeline/stage picker (fetched live), owner picker, Lead vs Deal toggle, custom-field opt-in, auto-sync rule.
- Rate limiting: respect Pipedrive's limit headers and back off on 429.
- Uninstall/revoke handling: a callback or 401 → `needs_reauth`.

### Tests

`Http::fake()` fixtures for token exchange, org/person search and create, lead/deal create, note create, 429 backoff, refresh, dedupe paths, custom-field creation.

### Acceptance criteria

In a Pipedrive sandbox/trial account: connecting works; "Send to CRM" creates Org + Person + Lead with a note; re-sending updates rather than duplicates; auto-sync fires on threshold. Behind flag `prelaunch.pipedrive`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL8. Execute PL8 only: PipedriveConnector (OAuth, api_domain, scopes
verified against current docs), org/person/lead-or-deal/note mapping with dedupe, optional custom fields,
settings UI, rate-limit handling, revoke handling. Http::fake tests. Docs + progress.md. Stop.
```

---

## PL9 — HubSpot sync

**Goal.** The same as PL8, for HubSpot.

### Implementation

- `HubSpotConnector implements CrmConnector`. Public app OAuth. Scopes (verify against current HubSpot docs): `crm.objects.companies.read/write`, `crm.objects.contacts.read/write`, `crm.objects.deals.read/write` (optional), and the notes-related scope.
- Mapping:
    - Lead → **Company**, upserted by `domain` (HubSpot dedupes companies on domain). Set name, domain, country, city, industry where mapped.
    - Contact → **Contact**, upserted by `email`. Set firstname, lastname, jobtitle, phone; associate with the company.
    - Optional **Deal** in a chosen pipeline/stage (setting).
    - **Note** engagement associated with the company (and deal) carrying the why-now template.
    - Optional custom properties created on connect (`alima_confidence`, `alima_timing_score`, `alima_why_now`, `alima_url`, `alima_pecr_type`, `alima_tps_status`) in an "AlimaProspects" property group.
- Use the CRM v3 objects/associations APIs and batch endpoints for bulk sync.
- Settings UI as PL8 (pipeline/stage, owner, deal toggle, properties opt-in, auto-sync rule).
- Respect HubSpot rate limits (429 + `Retry-After`).

### Tests

`Http::fake()` fixtures for OAuth, upserts, associations, notes, property creation, batch, 429, refresh, dedupe.

### Acceptance criteria

In a HubSpot developer test account: connect → send → Company + Contact (+ Deal) + Note with associations; re-send updates rather than duplicates. Behind flag `prelaunch.hubspot`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL9. Execute PL9 only: HubSpotConnector (OAuth, scopes verified against
current docs), company-by-domain and contact-by-email upserts, associations, optional deal, notes, custom
property group, batch sync, rate-limit handling, settings UI. Http::fake tests. Docs + progress.md. Stop.
```

---

## PL10 — Free sample report

**Goal.** A prospect gives us their website and gets a branded report of **10 scored leads with why-now reasons** for free, then converts to a paid team with their ICP already set up. This is the lead magnet behind the Instagram `SAMPLE` keyword and the ads.

### Flow

1. Public page `/sample` (Inertia, brand styling, mobile-first): fields `website_url`, `email`, `market` (UK / Dubai / Both), optional "what do you sell / who's your ideal customer?", a marketing-consent checkbox (separate and unticked, for PECR), Turnstile captcha. Capture UTM params + `ref` (e.g. `ig_sample`) in a first-party cookie / hidden fields.
2. Anti-abuse:
    - Rate limits per IP (3/day), per email (1/30 days), per website domain (1/30 days, returning the existing report link instead).
    - Block disposable email domains (maintained list package or config) and require a business-looking domain (warn on gmail/hotmail, but allow behind a config switch).
    - **Global daily budget kill switch:** `sample_reports.daily_max` (e.g. 50) and `sample_reports.max_cost_pence_per_report` (e.g. 40p). Above the cap, the page shows a waitlist message.
3. Email verification: a magic link to the email. Generation starts only after the click (stops bots and fake emails).
4. `GenerateSampleReport` job (`sample-reports` queue), reusing the real pipeline in a **sandbox team context** (a system-owned `sample` tenant, or a `SampleReport` aggregate; decide in the ADR, but don't create a real customer team):
    - AI background check of the prospect's business (reuse the onboarding Action) → derived ICP.
    - Run lead sourcing + signals for the chosen market, capped at 10 leads, chosen by best `confidence × fit` with at least 5 that have signal evidence (PL1).
    - **Company-level data only:** no personal contact data in the free report. Show the decision-maker _role_ found (e.g. "Head of Operations"), but not the name, email or phone. This keeps cost low and avoids handing out personal data to an unverified stranger.
    - Meter costs to the `sample_report` meter on the platform account; abort if the per-report cap is exceeded.
5. Report page `/sample/r/{token}` (signed, unguessable, expires in 30 days):
    - Header: "10 businesses likely to buy from <their company> right now".
    - ICP summary (what the AI understood about their business; editable on sign-up).
    - 10 cards: company, location, fit/timing/confidence, why-now summary, evidence chips with dates and sources, a blurred contact block ("Decision-maker found — unlock with Starter").
    - Signals sample: "3 more trigger events this week in your market".
    - CTA: "Get these contacts + new leads every week — £49/month, first month risk-free" → register.
    - Print-friendly CSS (users can "Save as PDF"). Open Graph tags for sharing.
6. Email the report link when it's ready (transactional, always sent). Marketing follow-ups go only to those who ticked consent: queue a 3-email nurture via the existing mail setup or a list sync (post-launch for the full sequence; for now just tag them in the email list export/integration).
7. **Conversion:** register with a `claim` token → the new team is created with the report's ICP and the 10 leads copied in (now enrichable with their plan allowance), plus attribution (`utm_*`, `ref`) stored on the team.
8. Admin: list of reports (status, cost, domain, converted?, source), funnel stats (requested → verified → generated → viewed → registered → paid), and resend/regenerate actions.
9. Analytics events (reuse whatever exists, or a simple `funnel_events` table): `sample_requested`, `sample_verified`, `sample_generated`, `sample_viewed`, `sample_cta_clicked`, `sample_claimed`, `subscription_started`.

### Tests

Rate limits; disposable blocking; captcha required; generation only after verification; per-report cost cap abort; daily kill switch; company-level-only data (assert no contact PII fields in the report payload); token expiry; claim copies ICP and leads into the new team only; attribution stored; consent stored separately; sample context isolated from customer teams.

### Acceptance criteria

- A real website URL produces a 10-lead report in a few minutes, costs under the cap, and looks good on mobile.
- Signing up from it lands the user in a team with ICP and leads pre-loaded.
- Behind flag `prelaunch.sample-report`.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL10. Execute PL10 only: public /sample form with Turnstile, UTM
capture, consent, abuse controls, budget kill switch, email verification, GenerateSampleReport job reusing
onboarding + sourcing + signals in an isolated sample context, company-level-only report page (print CSS,
OG tags), report email, claim-on-register flow copying ICP + leads, attribution, admin funnel view, funnel
events. Tests (incl. no-PII assertion + isolation). Docs + ADR + progress.md. Stop.
```

---

## PL11 — Trust centre, legal pages, Article 14 notices

**Goal.** A public trust centre that answers "is this legal and safe?" before a prospect asks, generated from the real config so it stays accurate.

### Pages (public, Inertia, brand styling; content in `resources/content/legal/*.md` so a lawyer can edit the wording without touching code)

1. `/trust` — overview: what data we process, where it comes from, how we keep it safe, and links to the pages below.
2. `/trust/data-sources` — **generated** from the enabled `LeadSourceProvider`s, `SignalSource`s and enrichment providers' config: name, type of data, licence/terms summary (a `license_summary` key added to each source's config), markets covered. A test fails if an enabled source has no `license_summary`.
3. `/trust/compliance` — UK GDPR + PECR stance (legitimate interests for B2B prospecting, business-capacity data, the corporate vs individual subscriber distinction, TPS/CTPS screening, suppression), UAE PDPL stance, and how customers should use the data. Placeholder text marked `LEGAL REVIEW REQUIRED`.
4. `/trust/security` — hosting (Hetzner, region from config), encryption in transit/at rest, access control, tenant isolation, backups, sub-processors.
5. `/trust/subprocessors` — generated from `config/subprocessors.php` (Hetzner, Stripe, AI provider(s), Serper, enrichment vendors, email provider, Sentry…): name, purpose, location, transfer mechanism.
6. `/legal/privacy` (customers + website visitors), `/legal/terms` (incl. acceptable use: no spam, comply with PECR/PDPL, honour opt-outs), `/legal/dpa` (downloadable DPA template as PDF/HTML; customer is controller, we are processor for their data; for prospect data we are an independent controller; lawyer to confirm).
7. `/legal/prospect-privacy-notice` — the **Article 14 notice** for people whose business contact data we hold: who we are, sources, purposes, lawful basis, retention, rights, link to `/privacy/request` (PL5).
8. Cookie banner/consent if any non-essential cookies or analytics are used (check the current setup).

### Article 14 notification approach

- Document and implement the chosen approach (ADR). Recommended: a public prospect privacy notice, plus **notification when the platform first stores a person's contact details** (e.g. a short email on first reveal, sent by the platform, linking to the notice and opt-out), batched daily, deduped platform-wide by email hash, and never repeated within 12 months. Behind its own flag (`prelaunch.article14-notify`) so legal can decide whether to enable it.
- Data retention: scheduled `data:retention` job that deletes unrevealed person data older than N months and revealed contacts on cancelled teams after M days (config, ADR).

### In-app

- Footer links everywhere (web + mobile).
- On first use of reveal/export/CRM sync: a one-time "responsible use" acknowledgement modal (PECR subscriber types, TPS, honour opt-outs), with acceptance stored per user + version.
- Sign-up: terms + privacy acceptance stored with version.

### Tests

Data sources page lists every enabled source; missing `license_summary` fails; sub-processors render; acknowledgement gating; Article 14 batch dedupe + 12-month rule; retention job deletes the right rows only.

### Acceptance criteria

All pages are live behind flag `prelaunch.trust`, wording is clearly marked for legal review, and the generated lists match config.

**Dispatch prompt**

```
Read section 0, recon.md and phase PL11. Execute PL11 only: trust centre + legal pages with markdown
content, generated data-sources and subprocessors pages (with license_summary test), responsible-use
acknowledgement, terms/privacy acceptance versioning, Article 14 notice page + optional notification job
behind its own flag, retention job, footer links web + mobile. Mark all legal wording LEGAL REVIEW
REQUIRED. Tests. Docs + ADR + progress.md. Stop.
```

---

## PL12 — Hardening, QA and go/no-go

**Goal.** Prove everything works together, is safe across tenants, and can be operated by one person.

### Tasks

1. **Security pass**
    - Run an authorisation audit: list every new route/API endpoint/broadcast channel → policy/gate used. Fail if any is unprotected (architecture test).
    - A tenant-isolation test suite covering all new models through HTTP, API, jobs and channels (consolidate the per-phase ones and fill gaps).
    - Rate limits on reveal, export, webhook-test, sample, privacy-request and OAuth callbacks.
    - Encrypted columns verified; secrets out of logs (log-scrubbing test).
    - Dependency audit (`composer audit`, `npm audit`), and fix the highs.
2. **Reliability**
    - Horizon queue config: supervisors and balancing for `enrichment`, `integrations`, `webhooks`, `sample-reports`, `exports`, `ai`; long-wait alerts.
    - Failure drills: vendor 5xx (the circuit breaker opens), Stripe webhook replay, CRM token expiry, webhook endpoint down.
    - Backups: confirm the DB backup schedule and test a restore on staging. Document it in `docs/runbooks/`.
3. **Performance**
    - Index review for the new tables (EXPLAIN on lead-list queries with evidence and confidence filters).
    - N+1 checks on lead list and detail (Laravel's `preventLazyLoading` in non-prod).
    - A load test of 200 concurrent enrichment jobs with fakes (queue throughput, ledger locking).
4. **End-to-end smoke tests** (browser tests via the repo's tooling), using fakes:
    - Sample report → verify → view → claim → subscribe (Stripe test) → leads present.
    - Lead → find decision-makers → reveal email → export CSV → push to Pipedrive (fake) → webhook delivered.
    - Opt-out request → verify → contact purged → suppressed on next search.
5. **Demo data:** update the seeder to include evidence, contacts with mixed statuses, TPS flags, sample report — for the Instagram screen recordings (the Playwright script can target these).
6. **Docs and runbooks**
    - `docs/runbooks/`: vendor outage, kill switch for sample reports, disabling a feature flag, handling a DSAR, rotating secrets, refunding a top-up.
    - Update `README`/module docs; finalise ADRs.
7. **Go/no-go checklist** in `docs/prelaunch/go-no-go.md`, ticked with evidence links:
    - [ ] All flags tested on and off.
    - [ ] Real vendor keys in production `.env`; one live end-to-end enrichment on an internal test lead.
    - [ ] Stripe live mode: plan + top-ups + webhooks.
    - [ ] Pipedrive + HubSpot apps approved/published (or private-app install documented).
    - [ ] Turnstile live keys; sample budget caps set.
    - [ ] TPS/CTPS licence active (or the UI clearly says screening is pending, and phone reveal is limited).
    - [ ] Legal review signed off for the trust centre, privacy notice, DPA, Article 14 notice and terms.
    - [ ] Sentry alerts routed; Horizon/Pulse dashboards checked; backups verified.
    - [ ] Margin guard thresholds set; admin can see vendor cost vs MRR.
    - [ ] Demo account seeded for marketing.

### Acceptance criteria

The full suite and E2E smoke tests are green, the go/no-go checklist is complete (or each open item is explicitly accepted by Alex), and the flags are ready to turn on for launch.

**Dispatch prompt**

```
Read section 0, recon.md, progress.md and phase PL12. Execute PL12 only: security/authorisation audit with
architecture tests, consolidated tenant-isolation suite, rate limits, log scrubbing, dependency audit,
Horizon config, failure drills, index/N+1 review, enrichment load test with fakes, E2E smoke flows, demo
seeder update, runbooks, and docs/prelaunch/go-no-go.md. Report open items needing Alex. Stop.
```

---

## Appendix A — New flags

`prelaunch.why-now`, `prelaunch.enrichment`, `prelaunch.export`, `prelaunch.webhooks`, `prelaunch.pipedrive`, `prelaunch.hubspot`, `prelaunch.sample-report`, `prelaunch.trust`, `prelaunch.article14-notify`

## Appendix B — New queues

`ai` (if not already present), `enrichment`, `integrations`, `webhooks`, `exports`, `sample-reports`

## Appendix C — New config files

`config/evidence.php`, `config/enrichment.php`, `config/plans.php` (merge), `config/sample_reports.php`, `config/subprocessors.php`, `config/compliance.php` (TPS, PECR, UAE, retention)

## Appendix D — Hooks left for post-launch

- **Outcome tracking:** add `status` / `outcome` / `deal_value` to leads, and feed `lead_evidence_feedback` and outcomes into scoring.
- **Weekly digest / push:** reuse the `signal.detected` event and the PL1 why-now summaries.
- **AI-drafted openers:** a new Action consuming PL1 evidence plus the revealed contact.
- **Push to Instantly / lemlist / Smartlead:** a new `CrmConnector`-like `SenderConnector` on the PL7 framework.
- **Website-visitor signals:** a new `SignalSource` via a partner API.
- **Vertical packs:** ICP + source presets in config, exposed in onboarding and the sample report.
