# AlimaProspects — Free Lead Sources Expansion Build Plan

> Companion to `mvp.md` and `signals-plan.md`. Feed this to Claude Code **one phase at a time**
> (each phase has a **Dispatch prompt**). Created 2026-11-02.
> Scope: **free** data sources only. Paid enrichment (Apollo / PDL / Hunter / Cognism / Crunchbase)
> stays post-MVP per ADR 0008 and is **out of scope here**.
>
> **Two corrections from L0, which apply to every phase below.** First, the decision this plan calls
> "ADR 0018" is **ADR 0025** (`docs/decisions/0025-free-lead-sources.md`) — 0018 was taken by
> _signal definitions are data_ while this plan sat unexecuted, and Signals v2 has since run to 0024.
> Every "ADR 0018" in the phases below means 0025. Second, **L0 dropped five sources on their own
> terms and found L6 already built**, so L4, L8 and L10 are ❌ and L6 is superseded. The evidence is
> in `docs/modules/lead-sourcing.md` → _Free sources — spike findings (L0)_; read it before starting
> any phase, because several phases' designs changed with it.

## Status

| Phase | Title                                                         | Status                   | Completed  |
| ----- | ------------------------------------------------------------- | ------------------------ | ---------- |
| L0    | Decisions, licence/ToS spike & docs                           | ✅ Complete              | 2026-11-06 |
| L1    | Lead Sourcing foundations: new sources, reference datasets    | ⬜ Pending               |            |
| L2    | UK sector registers (FSA, CQC, FCA, Charity Commission)       | ⬜ Pending               |            |
| L3    | HM Land Registry CCOD/OCOD (companies that own property)      | ⬜ Pending               |            |
| L4    | UAE free-zone registers (DIFC, ADGM, DMCC)                    | ❌ Dropped               | 2026-11-06 |
| L5    | OpenStreetMap (Overpass) provider                             | ⬜ Pending               |            |
| L6    | Signals: the `SignalSource` seam (refactor, no new behaviour) | ✅ Superseded (ADR 0023) | 2026-11-06 |
| L7    | Signals: Companies House Streaming (UK signals)               | ⬜ Pending               |            |
| L8    | Signals: hiring signals (Adzuna, Reed)                        | ❌ Dropped               | 2026-11-06 |
| L9    | Signals: public contracts (Contracts Finder, Find a Tender)   | ⬜ Pending               |            |
| L10   | Signals: planning applications (PlanIt)                       | ❌ Dropped               | 2026-11-06 |
| L11   | The Gazette: insolvency signals + lead hygiene                | ⬜ Pending               |            |
| L12   | Dubai Pulse DLD transactions: UAE property market context     | ⬜ Pending               |            |
| L13   | Enrichment: tech-stack detection from fetched pages           | ⬜ Pending               |            |
| L14   | Plan gating, API v1, UI polish, docs & reviews                | ⬜ Pending               |            |

Phases L2, L3 and L5 depend only on L1. L7, L9 and L11 depend on the Signals source registry, which
**already exists** (ADR 0023), so they depend only on L0. L12 and L13 are independent of everything
except L0.

**Revised order after L0** (the dropped phases removed, and the phases that need nothing from you
first): **L0 ✅ → L1 → L9 → L11 → L2 → L13 → L5 → L7 → L3 → L12 → L14.** L9 (public contracts) and
L11 (the Gazette) move to the front because they need **no key, no account and no licence**, and L9
turned out to carry the plan's best identity signal — an award names its supplier by Companies House
number. L7 and L3 move back behind them because they are blocked on you (a streaming key; a Land
Registry account and its licence), and L12 behind those because its permission grant takes up to a
fortnight.

---

## What this plan adds (and the two seams it uses)

The app already has two ways data comes in, and every new source goes behind exactly one of them:

1. **Company-record sources → `LeadSourcing\Contracts\LeadSourceProvider`** (ADR 0008). They return
   `SourcedLead`s that are deduped, enriched and scored by the existing discovery pipeline. A new
   provider registers in `LeadSourceProviderRegistry` and needs **no change** to `DiscoverLeadsJob`
   or `StartDiscoveryRunAction`.
2. **Event sources → a new `Signals\Contracts\SignalSource`** (introduced in L6). Signals today is
   hard-wired to Serper News through `NewsSearchClient` + AI extraction. Structured event feeds
   (Companies House stream, contract awards, job ads) don't need AI to tell them what happened, so L6
   pulls a seam out first, with Serper News as its first implementation and **no behaviour change**.
   ADR 0011's rules still hold: conversion is the only route from a signal to a `Lead`, and the
   foreign key runs one way.

A third, smaller addition is **reference datasets**: bulk files (CCOD/OCOD, optionally the FSA
dump) imported into **platform-level** tables that aren't owned by any team. These are public
reference data, not tenant data, so they deliberately have **no `team_id`**. That is a written
exception to ADR 0006 and goes in ADR 0018 (L0).

## The sources

| #   | Source                                   | Seam                              | Market | Auth                  | What it gives                                                                        |
| --- | ---------------------------------------- | --------------------------------- | ------ | --------------------- | ------------------------------------------------------------------------------------ |
| 1   | Companies House **Streaming API**        | SignalSource (push)               | UK     | Free stream key       | Incorporations, officer appointments, PSC (control) changes, new charges, filings    |
| 2   | HM Land Registry **CCOD / OCOD**         | Reference dataset + provider      | UK     | Free account+licence  | Every UK / overseas company owning property in England & Wales, with title addresses |
| 3   | **DIFC** public register                 | Provider                          | UAE    | None (verify)         | DIFC-registered companies                                                            |
| 4   | **ADGM** public register                 | Provider                          | UAE    | None (verify)         | ADGM-registered companies                                                            |
| 5   | **DMCC** member directory                | Provider (**only if ToS allows**) | UAE    | None (verify)         | DMCC free-zone companies                                                             |
| 6   | **Food Standards Agency** ratings API    | Provider                          | UK     | None                  | Almost every UK food business, by type and area                                      |
| 7   | **CQC** API                              | Provider                          | UK     | Free subscription key | Care providers and locations                                                         |
| 8   | **FCA** Financial Services Register API  | Provider                          | UK     | Free key              | Authorised financial firms                                                           |
| 9   | **Charity Commission** API               | Provider                          | UK     | Free key              | Registered charities                                                                 |
| 10  | **OpenStreetMap** (Overpass API)         | Provider                          | UK+UAE | None                  | Local businesses by tag + area, as a free supplement to Google Places                |
| 11  | **Adzuna** API                           | SignalSource (pull)               | UK     | Free app id + key     | Job ads, giving hiring-surge signals                                                 |
| 12  | **Reed** API                             | SignalSource (pull)               | UK     | Free key              | Job ads (a second hiring source)                                                     |
| 13  | **Contracts Finder** + **Find a Tender** | SignalSource (pull)               | UK     | None                  | Public contract awards (supplier won) and tenders (buyer is buying), as OCDS         |
| 14  | **PlanIt** planning applications         | SignalSource (pull)               | UK     | None                  | Planning applications aggregated across UK councils                                  |
| 15  | **The Gazette**                          | SignalSource + lead hygiene       | UK     | None                  | Corporate insolvency notices                                                         |
| 16  | **Dubai Pulse** DLD transactions         | Market context (not leads)        | UAE    | Open dataset (verify) | Anonymised property transactions: area, price, type. **No buyer identities**         |
| 17  | **Tech-stack detection** (in-house)      | Enrichment                        | Both   | None                  | Technologies detected from pages the app already fetches                             |

**Every row was verified in L0 on 2026-11-06**, and the verdicts are in
`docs/modules/lead-sourcing.md` → _Free sources — spike findings (L0)_, which supersedes the table
above wherever the two disagree. In short: ✅ 1, 6, 9, 13, 15, 17 · ⚠️ with conditions 2, 7, 8, 10,
16 · ❌ dropped 3, 4, 5, 11, 12, 14. Five of the six drops are the source's own terms or `robots.txt`
saying no — Adzuna names "sales prospecting or lead generation" as needing a written agreement, Reed
publishes no terms at all, and PlanIt, DIFC and DMCC sit behind a `Disallow` — and the sixth (ADGM)
publishes no machine-readable register at all.

## Ground rules for every phase

- Everything in `CLAUDE.md` applies: modules per ADR 0002, thin controllers, Actions in
  `DB::transaction()`, no external calls inside a transaction, enums not magic strings,
  `declare(strict_types=1)`, Larastan level 7.
- **Each provider or source extends the existing HTTP patterns.** Providers extend
  `AbstractHttpLeadSourceProvider`, which gives timeouts, retries with backoff and the circuit
  breaker. HTML sources fetch through `Core\Services\HttpWebContentFetcher`, which respects
  robots.txt. Every source gets its own `timeout`, `retry_times` and circuit-breaker keys in config,
  and every key is documented in `.env.example`.
- **Source attribution on every record** (`SourcedLead::$sourceUrl`, `$raw`, `source_name` on
  signals). Licensed data carries the attribution string the licence requires (OGL, ODbL, the
  Land Registry licence), shown wherever the data is shown.
- **Business data, not personal data.** Officers, PSCs, trustees, registered managers, planning
  applicants and personal insolvencies are people. Signals may store a person's **name, public role
  and source URL only** (ADR 0011). Providers store **no named individuals**. Personal (non-corporate)
  Gazette notices and individual planning applicants are **never ingested**.
- **Fakes in tests, never live calls.** Each source ships a `Testing/Fake…` alongside the real one,
  as `FakeLeadSourceProvider` and `FakeNewsSearchClient` do, with recorded fixture payloads under
  `tests/Fixtures/<Source>/`.
- **Cost is still logged** even at zero: `costMicros()` returns 0, and lead quotas
  (`LeadQuotaSource`) still apply per discovered lead, because a free source still spends a team's
  allowance. No billing changes are needed unless L0 decides to gate sources by plan.
- **Log IDs, never payloads** (ADR 0017's Sentry scrubber). Add a log channel only if a source
  earns one.
- **Definition of done** (`phase-complete` skill) for every phase: `composer test` green,
  `composer analyse` clean, `npm run check` + `npm run types:check` + `npm run build` pass, module
  doc + `CHANGELOG.md` + this file's status table updated. Run gates with the **`quality-gates`**
  skill (Herd binaries). Run **`laravel-module-reviewer`** after every backend phase and
  **`brand-reviewer`** after every phase that touches UI. Run `graphify update .` after code changes.

---

## Phases

### Phase L0 — Decisions, licence/ToS spike & docs

**Goal:** Know, before writing code, which sources we may legally and practically use, under what
terms, and record the architecture decisions.
**Depends on:** nothing.
**Modules & docs:** `docs/decisions/0018-free-lead-sources.md` (new), `docs/modules/lead-sourcing.md`,
`docs/modules/signals.md`, `docs/modules/enrichment.md`, `docs/compliance.md`, `docs/architecture.md`,
`CLAUDE.md`, `mvp.md`, `.env.example`.
**Tasks:**

1. [x] **Licence/ToS/limits spike, one row per source (1–17 above).** For each, record in a new
       "Free sources — spike findings" section of `docs/modules/lead-sourcing.md`:
       endpoint/base URL; auth method; rate limit; whether **commercial use inside a SaaS** is
       permitted; whether **storing** results is permitted, and for how long; the required
       attribution text; robots.txt position (HTML sources); data freshness; and a sample request
       and response trimmed to the fields we'd use. Make **real calls** only where no key is needed
       or the user has provided one. Otherwise mark the row "needs key" and continue.
2. [x] **Go / no-go per source.** Mark each row ✅ Go, ⚠️ Go with conditions, or ❌ Drop, with the
       reason. Expected hard questions, answered explicitly:
    - **DMCC directory:** is automated access permitted at all? If not, drop it.
    - **Reed:** do its terms allow storing job ads, or only displaying them with a link back?
    - **CCOD/OCOD:** do the licence terms allow use in a commercial product, and what must be
      displayed?
    - **PlanIt:** what volume of use is acceptable for a volunteer-run service?
    - **Overpass:** what does the public-instance usage policy allow, and at what volume would we
      need our own instance?
    - **Dubai Pulse:** is the DLD transactions dataset openly downloadable or API-accessible
      without a government account?
3. [x] **ADR 0025 (not 0018 — see the header note) — Free lead sources expansion.** Record:
       (a) the two-seam rule: company records go through `LeadSourceProvider`, events go through
       `SignalSource`;
       (b) the `SignalSource` contract shape, pull vs push, and when AI extraction runs (only for
       unstructured sources);
       (c) **platform reference datasets**: tables without `team_id`, an explicit, documented
       exception to ADR 0006, read-only to tenants, refreshed by scheduled imports, with an import
       ledger;
       (d) Companies House streaming runs as a **supervised long-lived worker**, not a queued job;
       (e) personal-data rules per source;
       (f) the decision on **plan gating** (see task 5).
4. [x] **Compliance:** extended `docs/compliance.md` (new §3.14, plus §4, §5 and §6). Add LIA lines for the new personal-data surfaces
       (CH officer and PSC names in signals) and the "never ingested" list (personal insolvency,
       individual planning applicants, named individuals from the sector registers). Add a retention
       line for each platform reference dataset (replaced on refresh). Mark ⚖️ for the pre-launch
       legal review.
5. [x] **Decision for the user (asked 2026-11-06; the answer was "all sources, all plans"):** should the new sources be available on every
       plan, or gated by plan through `CapabilityGate` (ADR 0015)? Record the answer in ADR 0018. The
       default proposal is all sources on all plans, with lead quotas as the only limit.
6. [x] Update `docs/architecture.md` (data-flow diagram showing the new sources and the
       reference-dataset import), `CLAUDE.md` §5 (ADR 0018) and §3 (link this plan), and the
       `mvp.md` status table (link this plan). Add every new env key to `.env.example`, commented,
       with no values.
7. [x] **List the user's to-dos** at the end of the phase summary: which free keys and accounts to
       register (see "What you need to do" at the bottom of this file).

**Tests:** n/a (docs only).
**Acceptance:** every source has a go/no-go with evidence; ADR 0018 accepted; dropped phases marked
❌ in the status table.
**Dispatch prompt:** _"Execute Phase L0 of lead-sources-plan.md. Read CLAUDE.md, mvp.md,
signals-plan.md, ADRs 0002/0006/0007/0008/0010/0011/0015, docs/compliance.md and
docs/modules/{lead-sourcing,signals,enrichment}.md. Run the licence/ToS/limits spike for every
source in the plan's table (use WebSearch/WebFetch for terms; make live calls only where no key is
needed), record the findings and a go/no-go per source, write ADR 0018, and update the compliance,
architecture, CLAUDE.md, mvp.md and .env.example docs. Ask me the plan-gating question. Do not write
application code."_
**Risks:** a source's terms forbid our use. That is the point of the phase: drop it rather than work
around it.

**What L0 answered** (full evidence in `docs/modules/lead-sourcing.md`; decisions in ADR 0025):

- Each of the six hard questions this phase named, answered. **DMCC** — no: its directory is a
  Salesforce site whose `robots.txt` is `Disallow: /` for everyone but googlebot. **Reed** — it
  publishes no API terms at all, so neither storing nor displaying is permitted in writing; dropped
  pending an answer from Reed. **CCOD/OCOD** — the dataset listing says "See individual licence",
  not the OGL, and that licence is readable only inside an account, so L3 plans on title **counts**
  rather than addresses until it has been read (⚖️). **PlanIt** — its `robots.txt` disallows the API
  to every agent, which answers the volume question by making it moot. **Overpass** — the public
  endpoint states in its own 502 body that it is "intended for browser-based and human-operated
  tools", so concurrency 1, backoff, and our own instance for production volume. **Dubai Pulse** —
  neither openly downloadable nor openly API-accessible: an account **and** a per-dataset permission
  grant, answered within ~14 days, then OAuth client credentials.
- **Plan gating: none.** Every source on every plan; lead and signal quotas stay the only limit
  (ADR 0025 §7). No new `Capability` case.
- **One finding changed the plan's shape rather than a phase's design.** The seam L6 was to build
  already exists as `Signals\Contracts\SignalSourceProvider` + `SignalSourceRegistry` (ADR 0023,
  S13a), with a per-source breaker key, the three addressing shapes and the 4xx trichotomy this plan
  had not anticipated. L6 is superseded, not deferred.
- **No application code was written**, as the dispatch prompt required.

---

### Phase L1 — Lead Sourcing foundations: new sources, reference datasets

**Goal:** The plumbing every new provider needs: enum cases, per-market availability, and the
reference-dataset infrastructure. No new provider yet.
**Depends on:** L0.
**Modules & docs:** LeadSourcing (`lead-sourcing.md`), Core.
**Tasks:**

1. [ ] **`LeadSource` enum:** add one case per ✅ provider from L0 (e.g. `LandRegistry`, `Difc`, `Adgm`,
       `Dmcc`, `FoodStandards`, `Cqc`, `Fca`, `CharityCommission`, `OpenStreetMap`), each with
       `label()`, `description()` and `rank()`. Registries rank 3 alongside Companies House; OSM
       ranks 2 alongside Google Places. Explain the rank in the docblock, as the existing cases do.
2. [ ] **Config-driven availability.** Replace the hard-coded `isAvailable()` match for provider
       cases with "implemented **and** enabled in `config/lead-sourcing.php`
       (`providers.<key>.enabled`) **and** its key is set when one is required". Keep `Import`,
       `Manual` and `Signal` permanently unavailable. A source that isn't built yet keeps showing as
       "coming soon" in the picker.
3. [ ] **`LeadSource::markets()`**: the `Market`s each source serves (Companies House → UK; DIFC →
       UAE; OSM → both). The discovery picker and `StartDiscoveryRequest` only offer and accept
       sources for the ICP's market. Validation lives in the FormRequest.
4. [ ] **Industry hints**: an optional `LeadSource::bestFor()` list, so the discovery UI can suggest
       "FSA hygiene register" when the ICP industry is restaurants or cafés. This is a suggestion
       only, never automatic.
5. [ ] **Reference-dataset infrastructure** (Core or LeadSourcing, decided in ADR 0018):
    - a migration `reference_dataset_imports` (`id`, `dataset` enum, `version`/`period`, `status`,
      `rows_imported`, `started_at`, `finished_at`, `error`). This is platform-level, with no
      `team_id`;
    - a `ReferenceDataset` enum and an `ImportReferenceDatasetAction` base that **streams** a CSV in
      chunks (never loads the whole file), upserts in batches inside per-chunk transactions, and
      swaps versions atomically (import into a new version, flip a pointer, delete the old one);
    - an artisan command `reference-data:import {dataset} {--file=}`, with a `--dry-run` option.
6. [ ] If an existing test asserts that every table has `team_id`, add the reference tables to its
       documented allowlist with a comment that points to ADR 0018. Never weaken the test.
7. [ ] Admin panel: a read-only "Reference datasets" list (last import, row count, status) behind
       `AdminAbility::ManageOperations`. Use the admin module's own patterns (`docs/modules/admin.md`).

**Tests:** enum availability honours config and missing keys; `StartDiscoveryRequest` rejects a
UAE-only source on a UK ICP and the reverse; the chunked importer is idempotent (running twice gives
the same rows) and rolls back a failed version without touching the live one; the admin page needs
the ability.
**Acceptance:** new sources appear as "coming soon"; reference-dataset import works end to end on a
fixture CSV.
**Dispatch prompt:** _"Execute Phase L1 of lead-sources-plan.md. Read docs/modules/lead-sourcing.md
(including the L0 spike findings), ADR 0018 and docs/modules/admin.md. Add the LeadSource cases,
config-driven availability, markets() and bestFor(), and the reference-dataset infrastructure with
its import command and admin list. Finish with laravel-module-reviewer and the phase-complete
checklist."_
**Risks:** large CSVs on a small Hetzner box. Stream, chunk, and measure memory in the test.

---

### Phase L2 — UK sector registers (FSA, CQC, FCA, Charity Commission)

**Goal:** Four free UK registers usable as discovery sources, for niche customer profiles.
**Depends on:** L1.
**Modules & docs:** LeadSourcing.
**Tasks (repeat 1–5 for each ✅ register: FSA → CQC → FCA → Charity Commission):**

1. [ ] `Services/<Name>Provider.php` extends `AbstractHttpLeadSourceProvider`. Map
       `DiscoveryQuery` (`terms`, `location`, `industries`, `limit`) onto the register's search
       parameters. Document the mapping in the class docblock.
2. [ ] Map results to `SourcedLead`. Use the register's own ID as `externalId`, put the
       register's record URL in `sourceUrl`, clamp `raw` to the fields used, and populate the
       address. **Drop named individuals** (registered managers, trustees, approved persons) before
       building the DTO.
3. [ ] **Dedupe into Companies House** where possible. If the register exposes a company number (CQC
       and FCA often do), set `companyNumber` so `LeadDeduplicator` merges with a Companies House
       record instead of creating a duplicate.
4. [ ] Config block `providers.<key>` (base URL, key env, timeouts, retries, circuit breaker,
       `enabled`), plus a `Testing/Fake<Name>Provider` with fixtures.
5. [ ] Register it in `LeadSourcingServiceProvider`.
6. [ ] **FSA specifics:** it has no free-text company search, so search by business type + local
       authority/area, and map ICP industries to FSA business types in config. Its rating date can
       serve as an "opened recently" hint for scoring. Record it as a fact in `raw`; don't change
       Scoring in this phase.
7. [ ] Discovery UI: show each register in the source picker with its `description()` and the
       `bestFor()` suggestion. EN + AR strings in `lang/`.

**Tests per provider:** query mapping; DTO mapping from a fixture; individuals are stripped; a
5xx/timeout is retried, then marks the run failed; the circuit breaker opens; a company number causes
a dedupe merge with a Companies House lead; cost is 0 and quota is still consumed.
**Acceptance:** a discovery run against each register (with fakes) produces leads that flow through
Enrichment → Scoring as normal.
**Dispatch prompt:** _"Execute Phase L2 of lead-sources-plan.md for the registers marked ✅ in L0.
Read docs/modules/lead-sourcing.md and ADR 0018. Build one provider at a time (FSA, CQC, FCA, Charity
Commission), each with its fake, fixtures, config and tests, then the picker strings in EN/AR. Run
laravel-module-reviewer and brand-reviewer, then the phase-complete checklist."_
**Risks:** search semantics differ per register. Keep each mapping explicit and tested rather than
writing a clever shared adapter.

---

### Phase L3 — HM Land Registry CCOD/OCOD (companies that own property)

**Goal:** Know which UK and overseas companies own property in England and Wales: discover them by
area, and enrich existing leads with "owns N UK titles".
**Depends on:** L1. Also needs the user's Land Registry account and licence acceptance.
**Modules & docs:** LeadSourcing (provider + dataset), Enrichment (fact), `lead-sourcing.md`,
`enrichment.md`.
**Tasks:**

1. [ ] Reference tables `ref_land_titles` (`dataset` CCOD|OCOD, `title_number`, `tenure`,
       `property_address`, `postcode`, `postcode_district`, `county`, `region`,
       `proprietor_name`, `company_number` nullable, `proprietor_country` (OCOD),
       `date_proprietor_added`, `price_paid` nullable minor units, `dataset_version`), indexed on
       `company_number`, `postcode_district` and normalised `proprietor_name`.
2. [ ] `ReferenceDataset::Ccod` / `::Ocod` importers on the L1 base. Use the monthly **full**
       file; the change-only file is a later optimisation. Download manually or by script per L0's
       finding; never commit the file.
3. [ ] Schedule a monthly import with `withoutOverlapping()`. If downloading needs the user's login,
       the scheduled job imports a file dropped in `storage/app/reference/` and alerts admins
       (through `NotificationDispatcher::toAdmins`) when a new month is overdue.
4. [ ] `LandRegistryProvider` (`LeadSource::LandRegistry`) queries the **local table**, not an API:
       companies owning titles in the query's location (postcode district / county / region),
       optionally filtered by `terms` against the proprietor name. Aggregate one `SourcedLead` per
       company with a title count, and carry `companyNumber` so it merges with Companies House.
5. [ ] **Enrichment fact:** when a UK lead has a company number, attach "Owns N titles in England &
       Wales (CCOD, <month>)" as a sourced fact with the licence attribution. Keep it cheap: one
       indexed query, no AI.
6. [ ] Show the attribution text required by the licence (from L0) wherever this data appears.

**Tests:** importer streams a fixture CSV and swaps versions; aggregation per company; location
filter; company-number merge with Companies House; enrichment fact added and attributed; overdue
alert fires once, not every night.
**Acceptance:** "companies owning property in SW1" returns real-shaped leads from fixtures, and the
enrichment fact appears on a matching lead.
**Dispatch prompt:** _"Execute Phase L3 of lead-sources-plan.md. Read ADR 0018, the L0 findings for
CCOD/OCOD and docs/modules/{lead-sourcing,enrichment}.md. Build the reference tables, importers,
monthly schedule, LandRegistryProvider and the enrichment fact with attribution. Run
laravel-module-reviewer, then the phase-complete checklist. List anything I must download or accept."_
**Risks:** the files are millions of rows. Index carefully and test import memory. Proprietor names
are companies by definition in CCOD/OCOD (individual owners are not in these datasets), but still
store no individuals.

---

### Phase L4 — UAE free-zone registers (DIFC, ADGM, DMCC)

**Goal:** Close ADR 0007's "no free UAE registry" gap with the free-zone public registers.
**Depends on:** L1. **Only the zones marked ✅ in L0.**
**Modules & docs:** LeadSourcing.
**Tasks (per zone):**

1. [ ] `Services/<Zone>RegistryProvider.php`. If the register has a JSON endpoint, extend
       `AbstractHttpLeadSourceProvider`. If it's HTML, fetch through `HttpWebContentFetcher` (robots
       respected, polite rate, a user agent that identifies us) and parse with a DOM parser, **not
       AI**. The structure is regular, and AI would cost money and add injection surface.
2. [ ] Map to `SourcedLead`: company name, registration number (`externalId` = `<zone>:<number>`),
       status, licence/activity type → `companyType`, address, `country = AE`, `sourceUrl` = the
       public record page. Drop director and shareholder names.
3. [ ] Map ICP industries to each register's activity categories in config.
4. [ ] Cache register pages (24h) so repeated discovery runs don't hammer the source. Respect any
       crawl delay found in L0.
5. [ ] UAE outreach rules still apply downstream (prior-consent leaning, ADR 0007) with no change,
       because `OutreachGuard` already decides by jurisdiction. Add a test proving it.

**Tests:** HTML/JSON fixture parsing (including a changed-layout fixture that must fail loudly, not
return garbage, as `LeadSourceProvider::discover()` requires); individuals stripped; cache hit on a
second run; robots disallow → provider refuses with a domain exception.
**Acceptance:** a UAE ICP can run discovery against each ✅ zone and get leads that flow through the
pipeline.
**Dispatch prompt:** _"Execute Phase L4 of lead-sources-plan.md for the free zones marked ✅ in L0.
Read ADR 0007, ADR 0018 and the L0 findings. Build one provider per zone with parser fixtures
(including a broken-layout fixture), caching and robots handling. Run laravel-module-reviewer and
security-review on the HTML parsing, then the phase-complete checklist."_
**Risks:** HTML layouts change without notice. The broken-layout test plus the circuit breaker make
the failure visible instead of silent.

---

### Phase L5 — OpenStreetMap (Overpass) provider

**Goal:** A free supplement to Google Places for local businesses in the UK and UAE.
**Depends on:** L1.
**Modules & docs:** LeadSourcing.
**Tasks:**

1. [ ] `OpenStreetMapProvider` builds an Overpass QL query from the `DiscoveryQuery`. Industries map
       to OSM tags (`shop=*`, `amenity=*`, `office=*`, `craft=*`) in config. The location resolves
       to an area (OSM `area` by name, with an optional bounding box). Use a server-side `[timeout:]`
       and `[maxsize:]`.
2. [ ] Map nodes/ways to `SourcedLead`: `name`, `addr:*`, `website`, `phone` (business line only),
       `externalId` = `osm:<type>/<id>`, `sourceUrl` = the openstreetmap.org object URL. Skip
       unnamed features.
3. [ ] **ODbL attribution** ("© OpenStreetMap contributors") stored on the lead's source
       attribution and shown in the UI.
4. [ ] Respect the public-instance usage policy from L0: a concurrency cap of 1, backoff on 429,
       and a configurable endpoint (`OSM_OVERPASS_URL`) so we can point at our own instance later.
5. [ ] Dedupe with Google Places by name + postcode/coordinates. Places ranks equal; on a tie the
       older record wins, as existing behaviour does.

**Tests:** QL generation per industry/location; mapping; unnamed skipped; 429 backoff; attribution
present; dedupe with a Places fixture.
**Acceptance:** "cafés in Dubai Marina" via OSM returns leads from fixtures, merging with any Places
duplicates.
**Dispatch prompt:** _"Execute Phase L5 of lead-sources-plan.md. Read ADR 0018 and the L0 Overpass
findings. Build OpenStreetMapProvider with config-driven tag mapping, ODbL attribution, rate
politeness and dedupe tests. Run laravel-module-reviewer, then the phase-complete checklist."_
**Risks:** OSM coverage varies a lot by area. Present it as a supplement, not a replacement.

---

### Phase L6 — Signals: the `SignalSource` seam (refactor, no new behaviour)

**Goal:** Signals can take events from more than Serper News, without changing anything a user sees.
**Depends on:** L0.
**Modules & docs:** Signals (`signals.md`), ADR 0011 (amended by 0018).
**Tasks:**

1. [ ] `Signals\Contracts\SignalSource`:
       `key(): SignalSourceKey`; `markets(): list<Market>`; `eventTypes(): list<SignalEventType>`;
       `collect(SignalWatch $watch, ?CarbonImmutable $since): list<SignalCandidate>`;
       `needsExtraction(): bool`; `costMicros(int $requests): int`.
       A `SignalCandidate` is either raw text for AI extraction (news) or an already-structured
       event that maps straight to `ExtractedSignal` (feeds).
2. [ ] `SerperNewsSignalSource` wraps the existing `NewsSearchClient` + `SignalQueryBuilder`. It is
       the only registered source after this phase.
3. [ ] `SignalSourceRegistry`, registered in `SignalsServiceProvider`, mirrors
       `LeadSourceProviderRegistry`.
4. [ ] `RunSignalWatchAction` iterates the sources that serve the watch's market and event types.
       It runs AI extraction **only** for `needsExtraction()` candidates. Structured candidates skip
       the AI and use a fixed confidence from config, so thresholds still apply uniformly. Dedupe
       still uses `url_hash`; structured sources must supply a stable canonical URL.
5. [ ] Enum `SignalSourceKey` (`SerperNews` for now). Add a nullable `source` column to `signals`
       and `signal_runs`, backfilled to `serper_news`.
6. [ ] `SignalEventType::sources()` says which sources can produce each type; the watch settings UI
       shows only the types available for the watch's market. There is no visible change yet, since
       only news exists.
7. [ ] Add UK to `config/signals.php` `markets` (`gl=gb`, default location London) with **news
       templates for the existing three event types**, if L0/ADR 0018 agrees. This is the cheap step
       that makes Signals UK + UAE.

**Tests:** the entire existing Signals test suite passes **unchanged**; a fake structured source
produces signals without any AI call; the AI is called only for extraction candidates; a mixed run
records cost per source; the `source` backfill is correct.
**Acceptance:** zero behaviour change for existing UAE watches; a fake second source can be
registered in a test and works end to end.
**Dispatch prompt:** _"Execute Phase L6 of lead-sources-plan.md. Read ADR 0011, ADR 0018,
docs/modules/signals.md and app/Modules/Signals (RunSignalWatchAction, NewsSearchClient,
SignalQueryBuilder, ExtractedSignal). Extract the SignalSource seam with Serper News as its first
implementation and keep every existing Signals test green unchanged. Add the UK news market if ADR
0018 says so. Run laravel-module-reviewer, then the phase-complete checklist."_
**Risks:** a refactor that subtly changes extraction. The unchanged test suite is the safety net, so
don't edit existing tests to make them pass.

---

### Phase L7 — Signals: Companies House Streaming (UK signals)

**Goal:** Real-time UK trigger events from the official register: new incorporations, officer
appointments, control (PSC) changes and new charges.
**Depends on:** L6. Also needs the user's Companies House **stream** key, which is separate from the
REST key.
**Modules & docs:** Signals, `docs/runbooks/` (new `companies-house-stream.md`).
**Tasks:**

1. [ ] New `SignalEventType` cases: `Incorporation`, `OfficerAppointment` (senior appointments),
       `ControlChange` (PSC change, a likely sale or exit), `NewCharge` (financing, often property).
       Each has `label()` and `description()`, with EN/AR strings.
2. [ ] **Ingest worker:** `php artisan signals:ch-stream {stream}` holds a long-lived HTTP
       connection to the chosen streams (`companies`, `officers`, `persons-with-significant-control`,
       `charges`). It persists the **timepoint** so it resumes after a restart, writes events to a
       platform-level buffer table `ch_stream_events` (no `team_id`, per ADR 0018), and handles
       reconnects with backoff. It is **not** a queued job.
3. [ ] Deploy: a Supervisor program per stream on Hetzner (add to the deploy runbook). Locally on
       Windows, run the command manually in a terminal. Note in the runbook that it isn't part of
       `composer dev`.
4. [ ] `CompaniesHouseStreamSignalSource` (pull from the buffer): for each watch, select buffered
       events since `last_run_at` that match its criteria (region/postcode area, SIC codes from the
       ICP, event types), and map them to structured candidates. Each candidate carries the company
       name and number, the person's name and role **only** for officer/PSC events, and a canonical
       `source_url` (the company's Companies House page). `needsExtraction() = false`.
5. [ ] Buffer retention: prune events older than the longest watch window (config, e.g. 14 days) in
       the nightly sweeps, after the platform rollup per ADR 0016's ordering.
6. [ ] **Conversion** uses the existing `ConvertSignalToLeadAction`. Pre-fill the company number so
       the lead merges with Companies House on discovery or enrichment.
7. [ ] Observability: a Pulse card or admin metric for stream lag (now minus the last event's
       timepoint), with an admin alert when lag exceeds a threshold.

**Tests:** stream line parsing from fixtures (including heartbeats/blank lines); timepoint
resume; reconnect backoff; watch matching by region/SIC/type; officer/PSC carries name + role only;
buffer pruning; conversion pre-fills the company number; the worker refuses to start without a key.
**Acceptance:** with a fixture stream, a UK watch for "Manchester, SIC 62xxx" surfaces new officer
appointments and PSC changes as signals that convert into leads.
**Dispatch prompt:** _"Execute Phase L7 of lead-sources-plan.md. Read ADR 0018, docs/modules/signals.md,
the L0 Companies House streaming findings and docs/runbooks/observability.md. Build the stream
ingest command with timepoint resume, the buffer table and pruning, the
CompaniesHouseStreamSignalSource, the new event types (EN/AR), the runbook and the lag metric. Run
laravel-module-reviewer and security-review, then the phase-complete checklist."_
**Risks:** the firehose volume. Filter at ingest to the four streams and the fields we need, and
index the buffer on (event type, postcode area, SIC, timepoint).

---

### Phase L8 — Signals: hiring signals (Adzuna, Reed)

**Goal:** "This company is hiring for roles that buy what you sell" as a UK signal.
**Depends on:** L6. Also needs the user's Adzuna app id/key and Reed key.
**Modules & docs:** Signals.
**Tasks:**

1. [ ] `SignalEventType::HiringSurge`. The watch config gains optional `role_keywords` (e.g.
       "marketing manager"), defaulting from the ICP.
2. [ ] `AdzunaSignalSource` and, if ✅ in L0, `ReedSignalSource` (pull): query by role keywords +
       location over the recency window.
3. [ ] **Aggregate per company:** a surge is ≥ N matching ads from one employer in the window
       (configurable). One signal per company per window, not one per ad. Store the ad count, the
       sample titles and the source URLs in `raw`. Store no recruiter or contact names.
4. [ ] Exclude recruitment agencies (a config list plus a heuristic on employer name) so the
       employer is the real hiring company.
5. [ ] `needsExtraction() = false`. Candidates are structured.
6. [ ] Respect storage limits from L0. If Reed only allows display, store the minimum and link out.

**Tests:** aggregation threshold; agency exclusion; one signal per company per window; dedupe
across Adzuna and Reed for the same employer.
**Acceptance:** a fixture with 4 marketing ads at one company produces one HiringSurge signal.
**Dispatch prompt:** _"Execute Phase L8 of lead-sources-plan.md for the job sources marked ✅ in L0.
Read ADR 0018 and docs/modules/signals.md. Add the HiringSurge event type, the source(s), per-company
aggregation, agency exclusion and EN/AR strings. Run laravel-module-reviewer and brand-reviewer, then
the phase-complete checklist."_
**Risks:** noisy employer names. Normalise them with `Enrichment\Services\CompanyNormaliser`.

---

### Phase L9 — Signals: public contracts (Contracts Finder, Find a Tender)

**Goal:** UK companies that just **won** public contracts (they're growing), and public bodies that
are **buying**.
**Depends on:** L6.
**Modules & docs:** Signals.
**Tasks:**

1. [ ] `SignalEventType::ContractAwarded` (subject = supplier) and `TenderPublished` (subject =
       buyer).
2. [ ] `ContractsFinderSignalSource` and `FindATenderSignalSource` (pull, OCDS JSON): filter by CPV
       codes (map ICP industries to CPV in config), region and minimum value. Use the award's
       `amount_minor` and `currency`. Prefer the supplier's Companies House number when the OCDS
       record carries one.
3. [ ] Dedupe across both services by OCDS id, since a notice can appear in both.
4. [ ] `needsExtraction() = false`.

**Tests:** OCDS fixture mapping; CPV/region/value filtering; cross-service dedupe; money stored in
minor units.
**Acceptance:** a fixture award to a Leeds IT supplier appears as a ContractAwarded signal with the
amount.
**Dispatch prompt:** _"Execute Phase L9 of lead-sources-plan.md. Read ADR 0018, the L0 OCDS findings
and docs/modules/signals.md. Add both procurement sources with CPV mapping, value filtering,
cross-service dedupe and EN/AR strings. Run laravel-module-reviewer, then the phase-complete
checklist."_
**Risks:** CPV mapping quality. Keep it as editable config and show the matched CPV on the signal.

---

### Phase L10 — Signals: planning applications (PlanIt)

**Goal:** Commercial planning applications as a signal for construction, property and fit-out
customers.
**Depends on:** L6.
**Modules & docs:** Signals, `compliance.md`.
**Tasks:**

1. [ ] `SignalEventType::PlanningApplication`.
2. [ ] `PlanItSignalSource` (pull): search by area and keywords/application type over the window,
       **keeping only applications whose applicant or agent is an organisation**. Drop
       householder/individual applications entirely; never store an individual applicant's name.
3. [ ] The summary comes from structured fields (description, site address, status). If a
       description is long and unstructured, it may use the cheap AI tier for a summary, treating
       the text as **data, never instructions**.
4. [ ] Usage politeness per L0: low concurrency, caching and the agreed volume.

**Tests:** individual applications filtered out; organisation applicants kept; politeness limits;
injection-style text in a description is not followed.
**Acceptance:** a fixture commercial extension application in Bristol surfaces as a signal; a
householder one does not.
**Dispatch prompt:** _"Execute Phase L10 of lead-sources-plan.md if PlanIt is ✅ in L0. Read ADR
0018, docs/compliance.md and docs/modules/signals.md. Add the source with organisation-only
filtering, polite usage and EN/AR strings. Run laravel-module-reviewer, then the phase-complete
checklist."_
**Risks:** personal data in applications. The organisation-only filter is the control, and it must
be tested.

---

### Phase L11 — The Gazette: insolvency signals + lead hygiene

**Goal:** Two uses of one feed: (a) corporate insolvency events as a signal for verticals that want
them, and (b) **flagging existing leads** that have entered insolvency so teams stop pursuing them.
**Depends on:** L6.
**Modules & docs:** Signals, LeadsWorkspace (flag only), `compliance.md`.
**Tasks:**

1. [ ] `SignalEventType::Insolvency` (administration, liquidation, winding-up petition). **Off by
       default** on new watches. It's only useful for some verticals.
2. [ ] `GazetteSignalSource` (pull, notice feed): **corporate notice types only**; personal
       insolvency notices are never fetched or stored. Match to companies by company number where
       present.
3. [ ] **Lead hygiene job** (scheduled daily): for each team's leads with a company number, flag a
       lead whose company has a new corporate insolvency notice. Show the flag in Leads Workspace
       with the notice link. Raise **one aggregated** notification per team through
       `NotificationDispatcher` (the `AnnounceTopLeadsJob` pattern), never one per lead. The flag
       informs; it doesn't auto-suppress.
4. [ ] LeadsWorkspace gets the flag through a Signals-owned query/resource, the same way the origin
       panel works (ADR 0011), so LeadsWorkspace doesn't import Signals internals.

**Tests:** personal notices excluded at the source; company-number matching; one aggregated
notification per team; tenancy, so team A's leads are never flagged from team B's data; the flag
shows in the workspace.
**Acceptance:** a fixture liquidation notice for a company in a team's leads flags that lead and
sends one notification.
**Dispatch prompt:** _"Execute Phase L11 of lead-sources-plan.md. Read ADR 0018, docs/compliance.md,
docs/modules/{signals,notifications,leads-workspace}.md. Add the corporate-only Gazette source, the
Insolvency event type (off by default), the daily lead-hygiene job with aggregated notifications,
and the workspace flag. Run laravel-module-reviewer and brand-reviewer, then the phase-complete
checklist."_
**Risks:** a false match flagging a healthy company. Match by company number only, never by name
alone.

---

### Phase L12 — Dubai Pulse DLD transactions: UAE property market context

**Goal:** For UAE property teams, a "market pulse": which areas and property types are moving, and
at what prices. **This is context, not leads.** The data is anonymised and has no buyer identities;
say so in the UI.
**Depends on:** L0 (✅ only), L1 (reference datasets).
**Modules & docs:** Reporting or BusinessIntelligence (decide in the phase; probably Reporting), a
new section in its module doc.
**Tasks:**

1. [ ] `ReferenceDataset::DldTransactions`, importing the open dataset into `ref_dld_transactions`
       (date, area, property type, usage, off-plan/ready, price in minor units AED, size) with a
       scheduled refresh at the cadence the dataset supports.
2. [ ] Builders for: transactions by area over time, median price per sqm by area, and the off-plan
       vs ready split. Bounds go through `DateRange::constrain()` in UTC, per the Reporting rules.
3. [ ] A "Dubai market pulse" panel shown to teams whose ICP market is UAE and whose industry is
       real estate (config-driven). Use the `dataviz` skill and brand tokens. Signal Orange is never
       a status colour.
4. [ ] Optional: a "hot areas" hint on Signals watch settings for UAE real-estate teams, to suggest
       locations. A suggestion only.

**Tests:** import from a fixture; builders correct, with UTC bounds; panel visible only to the right
teams; tenancy (the panel reads platform data but is shown inside the tenant shell without leaking
other teams' data).
**Acceptance:** a UAE real-estate team sees area trends from fixture data, labelled "anonymised
public transactions, no buyer details".
**Dispatch prompt:** _"Execute Phase L12 of lead-sources-plan.md if Dubai Pulse is ✅ in L0. Read
ADR 0018, docs/modules/reporting-analytics.md and docs/brand/brand-tokens.md, and load the dataviz
skill. Build the DLD dataset import, the builders and the market-pulse panel (EN/AR, RTL). Run
laravel-module-reviewer and brand-reviewer, then the phase-complete checklist."_
**Risks:** users reading it as a buyer list. Label it plainly in the UI copy.

---

### Phase L13 — Enrichment: tech-stack detection from fetched pages

**Goal:** Know what a lead's website runs on (Shopify, WordPress, HubSpot, the Meta pixel, GA4,
Wix…) at zero extra cost, using the pages Enrichment already fetches.
**Depends on:** L0.
**Modules & docs:** Enrichment (`enrichment.md`), Scoring (an input only), ICP (an optional
criterion), LeadsWorkspace (a filter).
**Tasks:**

1. [ ] `Enrichment\Services\TechStackDetector` takes the already-fetched HTML + response headers +
       script/link URLs + meta `generator`, and returns `list<DetectedTechnology>` (name, category,
       evidence, confidence). **No extra HTTP requests** beyond what enrichment already makes.
2. [ ] Fingerprints are **our own data** in `config/tech-fingerprints.php` (regex on script src,
       header, cookie name, meta, global JS name visible in inline script). Start with about 40
       high-value technologies. Don't copy third-party fingerprint databases without checking their
       licence. Record the decision in `enrichment.md`.
3. [ ] Storage: a `lead_technologies` table (`team_id`, `lead_id`, `technology` enum or string key
       from config, `category`, `confidence`, `detected_at`), using `BelongsToTeam` and including the
       cross-tenant test.
4. [ ] ICP criterion "uses / doesn't use technology X" (e.g. an agency targeting Shopify stores
       without a reviews app), a LeadsWorkspace filter, and a Scoring input behind a config weight
       that defaults to 0, so scoring doesn't change unless someone opts in.
5. [ ] API v1: expose technologies on `LeadResource` and regenerate `docs/api/openapi.json`.

**Tests:** detector against fixture pages (positive and negative per fingerprint); no extra HTTP
calls (assert with `Http::fake()` call counts); tenancy; the ICP criterion filters; scoring is
unchanged at weight 0; `RouteContractTest` passes.
**Acceptance:** a fixture Shopify store is detected as Shopify + Meta pixel and filterable in the
workspace.
**Dispatch prompt:** _"Execute Phase L13 of lead-sources-plan.md. Read docs/modules/{enrichment,
scoring,icp,leads-workspace}.md and docs/api/README.md. Build the TechStackDetector with our own
fingerprint config, the lead_technologies table, the ICP criterion, workspace filter, zero-weight
scoring input and API exposure, then regenerate the OpenAPI spec. Run laravel-module-reviewer and
brand-reviewer, then the phase-complete checklist."_
**Risks:** false positives from loose regexes. Require evidence on every detection and show it on
hover.

---

### Phase L14 — Plan gating, API v1, UI polish, docs & reviews

**Goal:** Tie everything together, then review it as one change.
**Depends on:** every phase not marked ❌.
**Modules & docs:** LeadSourcing, Signals, Billing (if gating), Admin, Mobile (read-only check), all
touched docs.
**Tasks:**

1. [ ] **Plan gating** per the L0 decision. If gated, add capabilities through `CapabilityGate` and
       `config/billing.php` only. No module learns what a plan is (ADR 0012/0015).
2. [ ] **API v1:** discovery accepts the new `LeadSource` values (validated by market); signal
       watches accept the new event types; resources expose `source` on signals. Regenerate
       `docs/api/openapi.json` (`composer api:docs`). `RouteContractTest` green.
3. [ ] **Discovery picker UX:** group sources by market and type ("Official registers", "Local
       businesses", "Web"). Show the attribution and "best for" hints, and disable sources not on
       the team's plan with an upgrade link. EN/AR, RTL, brand tokens.
4. [ ] **Signals watch UX:** group event types by source, mark UK-only and UAE-only types, and show
       "structured (official data)" vs "from news" on each signal card.
5. [ ] **Admin:** a provider health overview (circuit-breaker state, last success, failure rate per
       source) and reference dataset freshness, behind `ManageOperations`.
6. [ ] **Mobile:** the mobile app is a field companion. Confirm new signal types and sources render
       (labels and icons) and that no new screens are needed. Shared types move through `@shared`,
       never by copying.
7. [ ] **Sentry:** add any new _expected_ refusal (e.g. "source disabled on plan") to
       `sentry.ignore_exceptions` with a comment. Never add the base `DomainException`.
8. [ ] Docs: every module doc's Code map, Data model, Key flows and Changelog row; `architecture.md`;
       `CHANGELOG.md`; `CLAUDE.md` §3 (a short "Free lead sources" paragraph like the other phase
       notes); this file's status table; `mvp.md`.
9. [ ] Run **`laravel-module-reviewer`**, **`brand-reviewer`**, **`security-review`** (HTML parsing,
       the stream worker, reference imports) and **`code-review`** over the whole L-series diff, and
       fix the findings or record why they were accepted.

**Tests:** gating (if any) per source; the API contract; picker filtering by market and plan; admin
health page ability check.
**Acceptance:** all gates green; every ✅ source is usable from web, API and (read-only) mobile;
docs complete.
**Dispatch prompt:** _"Execute Phase L14 of lead-sources-plan.md. Read ADR 0012, 0013, 0015, 0017 and
0018, docs/api/README.md and docs/modules/{admin,mobile-app}.md. Apply plan gating per ADR 0018,
finish the API, picker, watch and admin UX, check mobile rendering, update every doc, then run
laravel-module-reviewer, brand-reviewer, security-review and code-review over the L-series and fix
the findings. Finish with the phase-complete checklist."_
**Risks:** scope creep in polish. Anything new goes to the backlog below.

---

## What you need to do (user actions)

Register these, then put the keys in `.env` (never commit them). Every one is free. The commented
placeholders and each source's licence conditions are already in `.env.example`. **L0 confirmed this
list on 2026-11-06** — nothing here is speculative any more.

- [x] Answer the **plan-gating** question in L0 — answered: all sources on all plans.
- [ ] **HM Land Registry** account on `use-land-property-data.service.gov.uk`: **read and accept the
      CCOD/OCOD licence**, then download the monthly files. Needed for L3 — and the licence text is
      also what closes a ⚖️ in `docs/compliance.md` §5, so send it to counsel rather than only
      clicking through it.
- [ ] **Companies House stream key** — a separate "Streaming" application in the CH developer hub,
      alongside the REST key already in use. Two concurrent connections per key and reconnect loops
      get an application banned, so do not share it with anything else. L7.
- [ ] **CQC** subscription key from `api-portal.service.cqc.org.uk`, and pick a `partnerCode`: CQC
      asks every consumer to identify itself on each request. L2.
- [ ] **Charity Commission**: an account on `api-portal.charitycommission.gov.uk`, then subscribe to
      the Register of Charities product for a key. L2.
- [ ] **FCA Register** key from the FCA developer portal — **and** a written answer from
      `RegisterAPISupport@fca.org.uk` on commercial reuse and attribution. The second half is the
      precondition, not a follow-up: the FCA sells register extracts, so silence in its terms about
      commercial use is not the same as an open dataset's silence. L2.
- [ ] **Dubai Pulse**: request permission for `dld-transactions` (L12) **and**
      `dld_free_zone_companies_licensing` — the latter is the only lawful machine route L0 found to
      UAE free-zone company data, and so is the live option for the gap L4's drop leaves open. A
      grant arrives within ~14 days and issues a key and a secret in two separate emails.
- [ ] Hetzner: add the Supervisor program for the Companies House stream worker (L7 runbook). It is a
      supervised long-lived process rather than a queued job, and it cannot be exercised that way on
      Windows.
- [ ] **Optional, and only if you want L8 back:** ask **Adzuna** for the written agreement their
      terms require for lead generation, and ask **Reed** whether their API may be used commercially
      at all. Until one of them says yes in writing, L8 stays dropped.

**No key is needed** for the FSA hygiene register, Contracts Finder, Find a Tender, The Gazette or
Overpass — which is why the revised order puts those phases first. **Do not register for** PlanIt,
DIFC, ADGM or DMCC: those are dropped on their terms, not pending a credential.

## Post-plan backlog

- Land Registry **change-only** monthly files instead of full reloads.
- A self-hosted Overpass instance if public-instance limits bite.
- UAE federal **National Economic Register**: there's no free bulk API, so only through a paid KYB
  aggregator. Revisit with paid enrichment (ADR 0008).
- **The UAE company-registry gap, properly** (ADR 0007, reopened by L0's drop of all three free
  zones): Dubai Pulse's `dld_free_zone_companies_licensing` dataset is the one free machine route
  found, and it covers Dubai only. Abu Dhabi (ADGM) and the federal register still have none.
- A **written agreement with Adzuna**, which is the only thing standing between this plan and hiring
  signals — the data and the endpoint are both fine.
- Arabic-language job and news sources for UAE hiring and relocation signals.
- Learning per-team thresholds for hiring surges and contract values from dismiss reasons.
- Paid providers behind the same seams: Apollo / PDL / Hunter / Cognism (people), Crunchbase
  (funding), as ADR 0008 already anticipates.
