# AlimaProspects — Signals (trigger-event prospecting) Build Plan

> Companion to `mvp.md`. Feed this to Claude Code one phase at a time (each phase has a **Dispatch prompt**).
> Scope for v1: **Dubai / UAE only**. Created 2026-10-29.

## Status

| Phase | Title                                              | Status | Completed |
| ----- | -------------------------------------------------- | ------ | --------- |
| S0    | Decisions, docs & Serper spike                     | ✅ Done | 2026-10-29 |
| S1    | Signals module scaffold, data model & tenancy      | ✅ Done | 2026-10-29 |
| S2    | Serper News client & ICP-driven query builder      | ✅ Done | 2026-10-29 |
| S3    | AI event extraction & the signal watch run         | ✅ Done | 2026-10-29 |
| S4    | Convert a signal into a standard Lead              | ⬜     | —         |
| S5    | Signals feed & watch settings UI (EN/AR, RTL)      | ⬜     | —         |
| S6    | API v1, retention, compliance hardening & polish   | ⬜     | —         |

---

## What Signals is (and isn't)

**Is:** a feed of **wealth / liquidity events** happening in the team's ICP geography: a company sold, a founder exits, a big funding round, a founder or executive relocating to Dubai. These are found with **Serper News**, classified and summarised by AI, and shown to the team as **signals**. A team member can **dismiss** a signal or **convert it into a standard `Lead`**. The new lead then flows through the existing Enrichment → Scoring → Leads Workspace pipeline.

**Isn't:**

- **Not a "find rich people" search.** Queries look for _events_, not stated intent. "Rich person looking to buy property in Dubai" returns agency blogs and portals, which are competitors, not buyers.
- **Not a contact-finding tool.** Signals store a person's **name, public role and source URL only**, never email or phone.
- **Not an outreach channel.** UAE PDPL means the suggested next step is LinkedIn or a referral introduction, not cold email (ADR 0007).
- **Not a `LeadSourceProvider`.** Providers return company leads keyed for dedupe; a signal is an _event about_ a person or company. Signals is its own module and hands off to LeadSourcing only on conversion.

**Primary customer (v1):** a Dubai luxury real-estate agency. The feature is generic, though: the event types and query templates are data, so other verticals (recruiters, B2B SaaS) can reuse it later with different templates.

## Cost model (v1, per team per day)

| Item                                 | Unit                          | ~Cost    | Per daily watch run (~8 queries, ~80 results) |
| ------------------------------------ | ----------------------------- | -------- | ---------------------------------------------- |
| Serper `/news`                       | per query                     | ~$0.001  | ~$0.01                                         |
| AI extraction (cheap tier, batched)  | per batch of ≤10 results      | ~$0.003  | ~$0.03 (results already seen are skipped first) |
| **Total**                            |                               |          | **≈ $0.04 / team / day (~$1.20 / month)**       |

Log cost on `signal_runs.cost_micros`, as `discovery_runs` does. Verify rates against live pricing.

---

## Phases

Every phase follows `CLAUDE.md` §4, and the **definition of done** (`phase-complete` skill) applies to each one: `composer test` green, `composer analyse` clean (level 7), `npm run check` + `npm run types:check` + `npm run build` pass, module doc + `CHANGELOG.md` + this file's status table updated. Use the **`quality-gates`** skill (Herd binaries, not Laragon). Run **`laravel-module-reviewer`** after backend phases and **`brand-reviewer`** after S5.

---

### Phase S0 — Decisions, docs & Serper spike

**Goal:** Record the decisions, create the module doc, and prove with real Dubai results that Serper News returns usable signals, before writing any code.
**Depends on:** nothing (Phases 4, 5, 7b, 8, 9 and 10b of `mvp.md` already done).
**Modules & docs:** new `docs/modules/signals.md`, `docs/decisions/0011-signals-module.md`, `docs/compliance.md`, `docs/architecture.md`, `CLAUDE.md`.
**Tasks:**

1. [x] **ADR 0011 — Signals module & Serper News.** Signals is its own domain module (ADR 0002), not a `LeadSourceProvider`. It reuses the Serper account from ADR 0010 via the **`/news`** endpoint. v1 is **UAE-only**, **snippet-only** (title + snippet + source + date; no full-page fetch), and conversion is the only route from a signal to a `Lead`. → `docs/decisions/0011-signals-module.md`
2. [x] **Module doc** `docs/modules/signals.md`, from `_TEMPLATE.md`: purpose, planned code map, data model (ER diagram for `signal_watches`, `signal_runs`, `signals`), key flows (watch run, conversion), external dependencies (Serper News, AI cheap tier), permissions, changelog row — plus the recorded spike findings.
3. [x] **Compliance note** in `docs/compliance.md` (new §5a + §6 mapping):
    - Signals hold personal data (a named person's name and public role, from published news).
    - Lawful basis: legitimate interest; add a section to the LIA.
    - Data minimisation: no contact details on signals.
    - Retention: dismissed signals deleted after 90 days; new signals nobody acts on deleted after 180 days. Configurable, set in S6.
    - UAE outreach: the converted lead is flagged "UAE: prior-consent outreach only".
    - Mark ⚖️ for the pre-launch legal review already required by ADR 0007.
4. [x] Add Signals to `docs/architecture.md` (module map table + flowchart) and `CLAUDE.md` (§5 ADR list + §3). Linked this file from the `mvp.md` status table.
5. [x] **Manual Serper spike (Claude with the key).** Ran the S2 templates against `POST /news` with `gl=ae`, `hl=en`, `tbs=qdr:w` (past week). **Finding:** the plan's strict exact-phrase templates return 0 results (7 of 8); loosened plain-keyword variants return 3–10 genuine Dubai events per event type — well past the ~3/week bar. `/news` caps at ~10 results/query. **Templates loosened for S2**, noisy domains and the geo-qualification requirement recorded in `docs/modules/signals.md` → "Serper News spike — findings".

**Tests:** n/a (docs only).
**Acceptance:** ADR 0011 accepted; module doc exists; spike findings recorded; templates adjusted.
**Dispatch prompt:** _"Execute Phase S0 of signals-plan.md. Read CLAUDE.md, mvp.md, ADRs 0002/0007/0008/0010, docs/compliance.md and docs/modules/\_TEMPLATE.md. Write ADR 0011, docs/modules/signals.md, the compliance section, and the architecture/CLAUDE.md/mvp.md updates. Do not write application code."_
**Risks:** Serper News coverage of Dubai business news may be thin, so the spike decides whether to proceed.

---

### Phase S1 — Signals module scaffold, data model & tenancy

**Goal:** The `Signals` module exists with its tables, models, enums, policies and tenancy guarantees. No external calls yet.
**Depends on:** S0.
**Modules & docs:** Signals (`signals.md`).
**Tasks:**

1. [x] Scaffold with the **`new-module`** skill: `app/Modules/Signals/` (Actions, Services, Contracts, Data, Enums, Events, Listeners, Jobs, Models, Policies, Http/{Controllers/Web, Controllers/Api/V1, Requests, Resources}, Exceptions, routes/{web,api}.php, Providers/SignalsServiceProvider.php). Register it via the Core base provider.
2. [x] **Enums** (backed, with `label()` and `values()`, no magic strings):
    - `SignalEventType`: `Acquisition` (company sold / founder exit), `FundingRound`, `Relocation` (founder/exec moving to the UAE). Each case carries a `description()` for the UI.
    - `SignalStatus`: `New`, `Dismissed`, `Converted`.
    - `SignalWatchCadence`: `Daily`, `Weekly`.
    - `SignalRunStatus`: `Queued`, `Running`, `Completed`, `Failed` (mirror `DiscoveryRunStatus`).
3. [x] **Migrations** (named by module, every table has `team_id` + FK + index):
    - `signal_watches`: `id`, `team_id`, `icp_id` (nullable FK), `name`, `market` (`Market` enum, default `uae`), `location` (string, e.g. "Dubai"), `event_types` (json list of `SignalEventType`), `cadence`, `is_active` (bool), `last_run_at` (nullable), timestamps.
    - `signal_runs`: `id`, `team_id`, `signal_watch_id`, `status`, `queries_count`, `results_count`, `signals_created`, `cost_micros` (int), `error` (nullable, user-safe message), `started_at`, `finished_at`, timestamps.
    - `signals`: `id`, `team_id`, `signal_watch_id`, `signal_run_id`, `event_type`, `status`, `headline`, `summary` (AI, ≤500 chars), `subject_person_name` (nullable), `subject_person_role` (nullable), `subject_company_name` (nullable), `counterparty_name` (nullable, e.g. the acquirer), `amount_minor` (nullable bigint), `currency` (nullable, `Currency` enum), `location` (nullable), `confidence` (0–100), `relevance` (0–100), `suggested_approach` (nullable text), `source_url`, `source_name` (nullable), `published_at` (nullable, UTC), `url_hash` (char 40), `raw` (json, the clamped Serper item), `lead_id` (nullable FK → leads, `nullOnDelete`), `actioned_by` (nullable FK → users), `actioned_at` (nullable), timestamps.
    - Unique index `(team_id, url_hash)`, so the same article never becomes two signals for one team. Index `(team_id, status, published_at)` for the feed.
4. [x] **Models** `SignalWatch`, `SignalRun`, `Signal`: `BelongsToTeam`, typed casts (enums, `Carbon`, money as int minor units), relationships (`watch → runs → signals`, `signal → lead`), `@property` docblocks for Larastan. Factories named by module.
5. [x] **Policies** `SignalWatchPolicy` and `SignalPolicy`, which double-check `team_id`:
    - View: every role, including Read-only.
    - Create/update/delete watches and dismiss/convert signals: Owner, Admin, Member.
6. [x] Domain exceptions: `SignalsException extends DomainException` (e.g. `alreadyConverted()`, `watchInactive()`, `noSubjectToConvert()`), rendered centrally.
7. [x] Log channel `signals` (add to `config/logging.php`, alongside `lead_sourcing`). Log IDs only, never names.

**Data model:** as above.
**Tests:** factories; enum coverage; **cross-tenant access fails** for each of the three models (global scope + policy); unique `(team_id, url_hash)` enforced; `lead_id` nulls when the lead is deleted.
**Acceptance:** module boots; migrations run on a fresh DB; tenancy tests pass; gates pass; `signals.md` code map and data model filled in.
**Dispatch prompt:** _"Execute Phase S1 of signals-plan.md. Read CLAUDE.md and docs/modules/signals.md. Use the new-module skill to scaffold app/Modules/Signals, then add the enums, migrations, models, factories, policies, exception and log channel exactly as specified, with tenancy tests. No external API calls."_
**Risks:** `Lead` lives in LeadSourcing. Keep the FK one-way (signal → lead) so LeadSourcing never depends on Signals.

---

### Phase S2 — Serper News client & ICP-driven query builder

**Goal:** Turn a watch (plus the team's active ICP) into a set of Dubai event queries, run them against Serper `/news`, and get back normalised, clamped news items.
**Depends on:** S1.
**Modules & docs:** Signals; reuses `config/lead-sourcing.php` → `providers.web_search` (key, base URL).
**Tasks:**

1. [x] **Config** `config/signals.php`:
    - Serper settings: reuse the existing key/base URL by reference; own timeout, connect timeout, retries and backoff.
    - `results_per_query` (default 20), `recency` (`qdr:w`), `cost_micros_per_request`.
    - `ignore_domains`: portals and agencies to drop (e.g. bayut.com, propertyfinder.ae, dubizzle.com, plus the client's competitors, set per team later).
    - The **query templates** per `SignalEventType` and market. Document every key in `.env.example`.
2. [x] **v1 UAE query templates** (in config, editable without code changes; `{location}` comes from the watch, which defaults to the ICP's first Geography value). **Loosened per the S0 Serper spike** — plain keywords, no exact-phrase `"…"` quoting and no `-based` (those returned 0 results on `/news` + `qdr:w`; see `docs/modules/signals.md` → "Serper News spike — findings"):
    - `Acquisition`:
        - `{location} company acquisition`
        - `UAE acquisition deal`
        - `UAE startup acquired`
    - `FundingRound`:
        - `{location} startup raises funding`
        - `UAE Series B funding`
        - `{location} startup Series A funding round`
    - `Relocation`:
        - `{location} founder relocates`
        - `family office {location} opens`
        - _(dropped `company relocates headquarters {location}` — too noisy / mostly non-UAE in the spike)_
    - **Note for S3:** the AI `signals.extract_event` prompt must **geo-qualify** — set `is_event=false` or low `relevance` when the subject isn't tied to the watched location (keyword hits include non-UAE M&A that merely mention the UAE). `/news` returns ≤ ~10 items/query, so breadth (more templates) beats a bigger `num`.
3. [x] **`SignalQueryBuilder`** (Service): watch + active ICP → `list<SignalQuery>` DTOs (`eventType`, `q`, `gl`, `hl`, `tbs`, `num`). Rules:
    - The watch's `location` overrides the ICP Geography value.
    - The watch's `event_types` choose the templates.
    - ICP `BuyingSignal` criteria are **appended as extra queries** when they match a known event keyword (e.g. "raised funding" → `FundingRound`). Otherwise they're ignored and the reason is logged.
    - Deterministic: no AI. Tested with a table of inputs → expected queries.
4. [x] **Contract `NewsSearchClient`** + **`SerperNewsClient`**:
    - Calls `POST /news` with `q`, `gl`, `hl`, `tbs`, `num` and maps the `news` array to `NewsItem` DTOs (`title`, `snippet`, `link`, `source`, `date` parsed to UTC when possible, `raw`).
    - Clamps text (as `SourcedLead` does), rejects non-http(s) links and drops `ignore_domains`.
    - Reuses the hardening pattern from `AbstractHttpLeadSourceProvider`: timeouts, retry with backoff on 429/5xx only, circuit breaker (`LeadSourcing\Support\CircuitBreaker`, or extract it to Core if reuse gets awkward), and `requireApiKey`.
    - Throws `SignalsException` on genuine failure; never returns partial garbage silently.
5. [x] **`FakeNewsSearchClient`** in `app/Modules/Signals/Testing/` with canned Dubai fixtures, bound in tests.
6. [x] `SignalItemNormaliser`: `url_hash` = sha1 of the canonicalised URL (lower-cased host, `utm_*`/fragment stripped), so the same article with tracking parameters dedupes.

**Tests:** query builder table tests (location override, event-type selection, BuyingSignal mapping); Serper client maps a recorded `/news` payload (`Http::fake`); ignores portal domains; retries on 429 then succeeds; circuit opens after repeated failure; missing key fails fast; URL canonicalisation.
**Acceptance:** `SerperNewsClient` returns normalised Dubai items from a fixture; gates pass; module doc "External dependencies" filled in.
**Dispatch prompt:** _"Execute Phase S2 of signals-plan.md. Read docs/modules/signals.md, ADR 0010/0011 and app/Modules/LeadSourcing/Services/{AbstractHttpLeadSourceProvider,WebSearchProvider}.php for the hardening pattern. Build config/signals.php with the UAE templates, SignalQueryBuilder, the NewsSearchClient contract, SerperNewsClient, a fake, and URL canonicalisation, with tests. Fake Serper in every test."_
**Risks:** Serper `/news` date strings are relative ("3 days ago"). Parse best-effort and fall back to the run time.

---

### Phase S3 — AI event extraction & the signal watch run

**Goal:** A scheduled, idempotent job runs each active watch: search → skip already-seen URLs → AI classifies and extracts each item → saves genuine, relevant events as `Signal`s → reports progress.
**Depends on:** S2, AI Platform (Phase 5).
**Modules & docs:** Signals; `docs/ai-prompts.md`; AI Platform (`PromptRegistry`, `AiClient`, `UntrustedContent`, `JsonSchemaValidator`).
**Tasks:**

1. [x] **Prompt `signals.extract_event`** (cheap tier), registered in `PromptRegistry` and documented in `docs/ai-prompts.md`.
    - **Input:** up to 10 `NewsItem`s, each wrapped in `UntrustedContent`, plus the team's business profile summary, ICP Geography/BuyingSignal values and the watched event types.
    - **Output JSON schema, per item:** `item_index`, `is_event` (bool), `event_type` (enum or null), `subject_person_name`, `subject_person_role`, `subject_company_name`, `counterparty_name`, `amount` (number|null), `currency` (ISO|null), `location`, `confidence` (0–100), `relevance` (0–100, i.e. how likely this person is a high-net-worth buyer for _this_ team), `summary` (≤ 2 sentences), `suggested_approach` (one line; must be LinkedIn / referral / introduction, never "email them").
    - The system prompt must tell the model to ignore instructions inside articles and to return `is_event=false` for listicles, property-portal pages, opinion pieces and rumours.
2. [x] **`ExtractedSignal` DTO** + validation through `JsonSchemaValidator`. If the schema fails, retry once, then skip the batch and log it; the run doesn't fail.
3. [x] **`RunSignalWatchAction`** (orchestration) for one watch:
    1. Create the `signal_runs` row (status Running) in a transaction.
    2. **Outside any transaction:** build queries → call Serper per query → collect items → drop items whose `url_hash` already exists for the team → batch the rest (≤10) through `signals.extract_event`.
    3. Keep items where `is_event && confidence ≥ config('signals.min_confidence', 60) && relevance ≥ config('signals.min_relevance', 40)`.
    4. Persist the kept signals + run totals (`queries_count`, `results_count`, `signals_created`, `cost_micros` = Serper + AI) in **one `DB::transaction()`**. Use `insertOrIgnore` / an upsert on `(team_id, url_hash)` so a re-run is idempotent.
    5. **`afterCommit`:** fire the `SignalsDetected` event (team, run, count) and update `last_run_at`.
4. [x] **`RunSignalWatchJob`**: `ShouldBeUnique` (unique per watch id), `$tries = 3`, `$backoff = [30, 120, 300]`, `$timeout = 120`; `failed()` marks the run Failed with a user-safe error and logs to `signals`. Runs inside `TeamContext`. Uses `Log::withContext(['team_id','signal_watch_id','signal_run_id'])`.
5. [x] **Scheduler** in `routes/console.php`:
    - `signals:dispatch-due` runs hourly and queues `RunSignalWatchJob` for active watches whose cadence is due (Daily: `last_run_at` older than 24h; Weekly: older than 7 days).
    - Also add a **"Run now"** action, `TriggerSignalWatchAction`, rate-limited to once every 15 minutes per watch.
6. [x] **Progress over Reverb**: a `SignalRunProgressed` broadcast on the team's private channel (stages: searching, analysing, saving, done), mirroring `DiscoveryRunProgressed`.
7. [x] **AI credits**: go through `AiCreditGuard`, so a team out of credit gets a clear Failed run ("AI credit exhausted") instead of a half-run.

**Tests:** end-to-end with `FakeNewsSearchClient` + `FakeAiTransport`:
- happy path creates the right signals;
- re-running the same results creates 0 new signals (idempotent);
- below-threshold items are dropped;
- a prompt-injection snippet is treated as data;
- a schema-invalid AI response is retried then skipped;
- a Serper failure fails the run with `failed()`;
- `ShouldBeUnique` prevents a double run;
- the scheduler picks only due, active watches;
- AI credit exhaustion fails the run cleanly;
- cross-tenant: one team's run never sees or dedupes against another team's signals.

**Acceptance:** with fakes, a daily watch for Dubai produces signals; cost is recorded; progress is broadcast; gates pass; module doc "Key flows" (with sequence diagram) + `ai-prompts.md` updated.
**Dispatch prompt:** _"Execute Phase S3 of signals-plan.md. Read docs/modules/signals.md, docs/modules/ai-platform.md, docs/ai-prompts.md and app/Modules/LeadSourcing/Jobs/DiscoverLeadsJob.php for the job/progress pattern. Build the signals.extract_event prompt + schema, RunSignalWatchAction, RunSignalWatchJob, the scheduler command, run-now, Reverb progress and credit guarding, with tests. Never call Serper or the AI inside a DB transaction."_
**Risks:** AI over-classifying noise as events, so keep the confidence threshold configurable and log the reasons for dropped items (as IDs and counts, not content).

---

### Phase S4 — Convert a signal into a standard Lead

**Goal:** A team member turns a signal into a normal company-level `Lead` with the named person as a `LeadContact`. It then goes through the existing Enrichment → Scoring → Workspace pipeline, with no duplicates.
**Depends on:** S3; LeadSourcing (7/7b), Enrichment (8), Scoring (9), Leads Workspace (10/10b).
**Modules & docs:** Signals, LeadSourcing (`lead-sourcing.md`), Leads Workspace (`leads-workspace.md`).
**Tasks:**

1. [ ] **`LeadSource::Signal`** case in `app/Modules/LeadSourcing/Enums/LeadSource.php`:
    - label "Signal"; description "Created from a wealth/liquidity event in your Signals feed."
    - `isAvailable()` = **false** (it isn't a discovery provider, like `Import`).
    - `rank()` = 1, equal to web search: news-derived, so registry/places/import data wins on merge.
    - Update the enum tests and any `match` that must be exhaustive.
2. [ ] **`ConvertSignalData`** (spatie/laravel-data): `companyName` (required; prefilled from `subject_company_name`), `website` (nullable), `personName` (prefilled), `personRole` (prefilled), `locality` (default "Dubai"), `country` (default "AE"), `pipelineStageId` (nullable), `note` (nullable). Validation lives in `ConvertSignalRequest` / the Data object only.
3. [ ] **`ConvertSignalToLeadAction`**, in one `DB::transaction()`:
    1. Lock the signal (`lockForUpdate`). If it's already Converted, **return the existing lead** (idempotent; no error on double-click).
    2. Build the `Lead`: `source = Signal`, company fields from the DTO, `source_url = signal.source_url`, `collected_at = now()`, `raw = ['signal_id' => …, 'event_type' => …, 'headline' => …]`, `has_personal_data = true` when a person is named. Compute `dedupe_key` the same way `SourcedLead::dedupeKey()` does.
    3. **Dedupe:** run the existing `Enrichment\Services\LeadDeduplicator::deduplicate()`. If the company already exists as a lead for this team, **attach to that lead** instead of creating a duplicate ("fill in, never overwrite").
    4. **Create a `LeadContact`** for the person: `name`, `title` = role, `email`/`phone` **null**, `is_personal = true`, `source = 'signal'`, `source_url`, `method = 'news_extraction'`. Skip it if a contact with the same name already exists on the lead.
    5. Record a `LeadActivity` ("Created from signal: {headline}", or "Linked to signal …" if attached) and an audit log entry.
    6. Update the signal: `status = Converted`, `lead_id`, `actioned_by`, `actioned_at`.
    7. **`afterCommit`:** queue `EnrichLeadJob` for a new lead (scoring follows via the existing `LeadEnriched` → `ScoreEnrichedLead` listener), and fire `SignalConverted`.
4. [ ] **UAE outreach flag:** store `raw.outreach_restriction = 'uae_prior_consent'` on leads created from UAE signals, and show a badge on the lead detail page ("Approach via LinkedIn or a referral"). Phase 11 (Outreach) must read this flag. Add that requirement to `docs/modules/outreach.md`.
5. [ ] **`DismissSignalAction`** (with an optional reason enum: `NotRelevant`, `AlreadyKnown`, `NotAnEvent`) and **`RestoreSignalAction`** (Dismissed → New). Both are transactional and audited. Store dismiss reasons for future tuning.
6. [ ] The lead detail page shows **"Origin: Signal"** with the headline and source link (a small read-only panel in Leads Workspace that reads `raw.signal_id`; keep the dependency one-way via a Signals-provided resource or a query in Signals).

**Tests:**
- Conversion creates a lead + contact with `source = Signal` and no email or phone.
- Converting twice returns the same lead.
- Converting a signal for a company that already exists attaches to it, adds the contact, and doesn't overwrite imported fields.
- Enrichment is queued only after commit (`Queue::fake` + `afterCommit`).
- The activity and audit entries are written.
- Dismiss/restore work.
- Policies: Read-only can't convert; other teams get 404/403.
- The `LeadSource::Signal` enum case is excluded from runnable discovery sources.

**Acceptance:** a converted signal appears in the Leads list, gets enriched and scored automatically, shows its origin, and can't be duplicated; gates pass; `signals.md`, `lead-sourcing.md` and `leads-workspace.md` updated with changelog rows.
**Dispatch prompt:** _"Execute Phase S4 of signals-plan.md. Read docs/modules/{signals,lead-sourcing,enrichment-data-quality,leads-workspace}.md, app/Modules/LeadSourcing/Enums/LeadSource.php, app/Modules/LeadSourcing/Data/SourcedLead.php, app/Modules/Enrichment/Services/LeadDeduplicator.php and app/Modules/LeadsWorkspace/Actions/ImportLeadsAction.php for the fill-in-never-overwrite pattern. Add LeadSource::Signal, ConvertSignalToLeadAction, dismiss/restore, the UAE outreach flag and the lead origin panel, with tests."_
**Risks:** a relocation signal may have no company, so the conversion form must require a company name (the person's company or holding entity) and explain why. Don't invent one.

---

### Phase S5 — Signals feed & watch settings UI (EN/AR, RTL)

**Goal:** A branded, bilingual Signals feed the client can demo: browse signals, convert or dismiss them, and set up watches.
**Depends on:** S4; brand design system (Phase 2).
**Modules & docs:** Signals; `docs/brand/brand-tokens.md` (**read first**, per CLAUDE.md §2a).
**Tasks:**

1. [ ] **Routes + thin controllers** (Policy → FormRequest → one Action → Inertia):
    - `signals.index`, `signals.dismiss`, `signals.restore`, `signals.convert`
    - `signal-watches.index/store/update/destroy/run`
2. [ ] **`Pages/Signals/Index.vue`, the feed:**
    - Tabs: New / Converted / Dismissed. Filters: event type, date range, min relevance. Paginated, newest first; dates in the team timezone.
    - **Live update** on `SignalsDetected` / `SignalRunProgressed` via Reverb (toast "3 new signals").
3. [ ] **`Components/Signals/SignalCard.vue`:**
    - Event-type `Badge`; headline; AI summary; person · role · company; amount formatted from minor units in its currency; location; source name + published date; relevance meter.
    - Suggested approach line.
    - Actions: **Convert to lead** (opens the modal below), **Dismiss** (with reason), **View lead** (when converted).
    - Use **`status.*` tokens** for state, never Signal Orange (CLAUDE.md §2a).
4. [ ] **`Components/Signals/ConvertSignalModal.vue`:** fields prefilled from the signal (company required), pipeline stage picker, note. Shows the UAE "LinkedIn / referral only" notice.
5. [ ] **`Pages/Signals/Watches.vue`:** list and create/edit watches (name, location defaulting to the ICP's Geography, event-type checkboxes with descriptions, cadence, active toggle), **Run now** with live progress, last run stats (signals found, cost), recent run history.
6. [ ] **`EmptyState`** for a team with no watches ("Set up your first Dubai watch", with a one-click default watch for Dubai covering all three event types) and for no signals yet.
7. [ ] Add **Signals** to the app-shell navigation. Add EN + AR strings to `lang/`, mirror the layout in RTL (don't mirror the logo), and use Tajawal for Arabic. Copy follows the brand voice (bright, bold, clear, together).
8. [ ] Accessibility: keyboard navigation on cards and modals, labelled controls, focus return after the modal closes, WCAG AA contrast.

**Tests:** feature tests per route (auth, validation, tenancy, happy path); Inertia props shape; Vue components type-check (`vue-tsc`); RTL snapshot or render check if the repo already has that pattern.
**Acceptance:** the full flow works in the browser, in EN and AR: create a Dubai watch → Run now → see signals arrive live → convert one → it appears in Leads with origin "Signal". **`brand-reviewer`** passes; gates + build pass.
**Dispatch prompt:** _"Execute Phase S5 of signals-plan.md. Read CLAUDE.md §2a, docs/brand/brand-tokens.md and docs/modules/signals.md first. Build the Signals feed, SignalCard, ConvertSignalModal, Watches page, empty states, navigation and EN/AR strings using the existing base components and brand tokens only. Then run the brand-reviewer agent and fix its findings."_
**Risks:** an over-busy card. Lead with cream, use one accent per layout, and favour fewer colours.

---

### Phase S6 — API v1, retention, compliance hardening & polish

**Goal:** Close the feature out to the same standard as the rest of the MVP: API parity, data retention, audit, docs and a demo script.
**Depends on:** S5.
**Modules & docs:** Signals; `docs/compliance.md`; `docs/runbooks/`.
**Tasks:**

1. [ ] **API v1** (`/api/v1`, Sanctum, per-token rate limit, API Resources only, the standard error envelope and pagination):
    - `GET /signals`, `POST /signals/{id}/convert`, `POST /signals/{id}/dismiss`
    - `GET/POST/PATCH/DELETE /signal-watches`, `POST /signal-watches/{id}/run`
    - Covered by feature tests (auth, validation, tenancy, happy path).
2. [ ] **Retention sweep** `signals:prune`, scheduled daily: hard-delete Dismissed signals older than `signals.retention.dismissed_days` (90) and New signals older than `signals.retention.unactioned_days` (180). Converted signals stay as long as their lead does. Idempotent, chunked, logged as counts only.
3. [ ] **Data-subject requests (DSAR) hook:** a `SignalsPersonalDataExporter` / eraser that finds signals by `subject_person_name` for a team, ready for Phase 15 (Compliance) to call. Document it in `compliance-privacy.md`.
4. [ ] **Per-team ignore list:** `signal_watches.ignore_domains` (json), so a client can exclude their competitors' domains. Merged with the config list in `SerperNewsClient`.
5. [ ] **Audit log** entries for watch create/update/delete, convert, dismiss and restore (if S4/S5 didn't already add them).
6. [ ] **Observability:** structured logs on the `signals` channel; Sentry breadcrumbs on run failures; a simple admin/Filament read-only view of `signal_runs` (cost, failures) if the admin panel exists by then, otherwise note it for Phase 19.
7. [ ] **Demo runbook** `docs/runbooks/signals-demo.md`: how to set up a Dubai watch for the real-estate client, what a good week of signals looks like, and how to talk about compliance ("we work from published news, store no contact details, approach via LinkedIn or referrals").
8. [ ] **Final review:** run `laravel-module-reviewer` across the Signals module and `security-review` on the prompt and URL handling; fix the findings. Update `CHANGELOG.md`, this file's status table and `mvp.md`.

**Tests:** API feature tests; the prune command deletes only eligible rows and never Converted ones; the DSAR exporter finds a named person's signals only within the team; per-team ignore domains are applied.
**Acceptance:** API parity; retention runs; compliance doc complete; demo runbook written; all gates green; reviewers clean.
**Dispatch prompt:** _"Execute Phase S6 of signals-plan.md. Read docs/modules/signals.md and docs/compliance.md. Add the API v1 endpoints, signals:prune, the DSAR exporter hook, per-team ignore domains, audit entries, observability and the demo runbook, then run laravel-module-reviewer and security-review and fix the findings. Finish with the phase-complete checklist."_
**Risks:** retention deleting something a team relied on. Show "auto-deletes in N days" on dismissed signals in the UI.

---

## Post-v1 backlog (Signals)

- **UK market:** Companies House Streaming API (changes to people with significant control, i.e. company sales) as a second signal source; UK query templates.
- **More event types:** senior appointments, IPOs/listings, large DLD off-plan launches (Dubai Pulse, as market context).
- **Arabic-language queries** (`hl=ar`) and AR news sources.
- **Full-article fetch** for high-relevance items via Core `WebContentFetcher` (better amounts and names), behind a cost cap.
- **Weekly email digest** of top signals (Notifications, Phase 12).
- **Learning from dismiss reasons** to tune thresholds and templates per team.
- **Vertical presets:** template packs for recruiters (hiring surges, new offices) and B2B SaaS (new marketing directors, expansions).
- **Premium wealth data:** an Altrata (Wealth-X / WealthEngine) enrichment provider for converted leads.
