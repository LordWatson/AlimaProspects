# Signals v2 — build plan (phases S7–S16)

> Companion to `signals-plan.md` (v1, phases S0–S6, complete 2026-10-29). Read
> `CLAUDE.md`, `docs/modules/signals.md`, `docs/decisions/0011-signals-module.md` and
> `docs/runbooks/signals-demo.md` before starting any phase here.
> Status table at the bottom. One phase per dispatch; each phase ends with the
> `phase-complete` checklist.

---

## Why this plan exists

Signals v1 shipped as a **vertical feature wearing a generic coat**. The coat is real —
query templates are config, event types have labels and explainers, the feed is server-driven
and bilingual — but three things make it one customer's feature rather than a product:

1. **The event types are a PHP enum.** `SignalEventType` has exactly three cases
   (`acquisition`, `funding_round`, `relocation`), chosen for a Dubai luxury real-estate
   agency. A recruiter who wants "hiring surge", a wealth manager who wants "IPO filed", a
   fit-out contractor who wants "planning permission granted" cannot have them. Adding one
   is a code change, a migration of the AI schema's `enum`, a release and a deploy.
2. **The geography is hard-coded to one market.** `SignalWatchData::DEFAULT_MARKET` is
   `Market::Uae`, `SaveSignalWatchRequest` deliberately **refuses** a `market`, the templates
   in `config/signals.php` are keyed `uae` only, and a watch holds **one** `location` string.
   The UK — a market the rest of the app serves, with Companies House already wired up in
   Lead Sourcing — gets nothing.
3. **The templates encode one buyer's thesis.** "Who just came into money and might buy a
   villa" is a good thesis. It is not the only one, and it is not configurable by the person
   who holds a different one.

This plan fixes all three, and then keeps going — because "user-defined signal types" on its
own is a configuration screen, not a market position. **Phases S7–S9 are the ask. Phases
S10–S16 are the moat.** They can be reordered or stopped after S9 with a coherent product
still standing; nothing after S9 is load-bearing for anything before it, except where a
"Depends on" line says so.

### The one-paragraph pitch, after S16

> Tell Alima what a buying moment looks like in your words. It compiles that into a standing
> watch over news, registries, job boards and your own feeds, across the markets you sell
> into; it clusters the eight articles about one event into one card; it scores each one
> against your ICP and tells you why; it watches the accounts already in your pipeline as well
> as the market at large; it learns which signals you keep and which you bin and retires the
> ones that never became pipeline; and when one lands, it can route, stage, assign and draft
> the approach — inside the compliance rules of the jurisdiction the event happened in.

That is not three checkboxes.

### The non-negotiables this plan inherits

Every phase below obeys these without restating them:

- **Tenancy** — every new table carries `team_id` and uses `BelongsToTeam`; every new model
  gets a policy that double-checks `team_id`; every new tenant model gets a test proving
  cross-tenant access fails (ADR 0006). The one exception is the **platform preset library**,
  which is deliberately cross-tenant and read-only to tenants — handled like
  `platform_daily_metrics` (ADR 0016), i.e. with its own reasoning written down, not as
  "tenancy with the scope removed".
- **Transactions** — writes in `DB::transaction()` at the Action level; Serper, the AI and any
  HTTP call stay **outside** it; post-commit work via `DB::afterCommit()`.
- **Spend** — a signal definition is a standing instruction to spend money. Every phase that
  multiplies definitions, queries or AI calls must say where the ceiling is, and the ceiling
  goes in a **guard**, never in middleware (ADR 0012) — a scheduled watch has no request
  behind it.
- **Compliance** — Signals still stores a person's name, public role and source URL and
  **never** an email or phone. Anything that reaches a prospect goes through Outreach, and the
  gate is `OutreachGuard`, not a policy (`docs/compliance.md` §3.2–3.3).
- **Brand** — §2a of `CLAUDE.md`. Read `docs/brand/brand-tokens.md` before any screen. Signal
  Orange stays decorative. EN/AR at parity, RTL correct, WCAG AA. Run `brand-reviewer` after
  every frontend phase.
- **Gates** — `composer lint`, `composer analyse` (Larastan 7), `composer test`,
  `npm run check`, `npm run types:check`, `npm run build`. Mobile only where a phase touches
  `/mobile`. Use the Herd binaries (`quality-gates` skill). `graphify update .` after code
  changes.
- **Logging** — the `signals` channel, IDs and counts only, never a name or a line of article
  text. New channels only where one earns it.

### Decisions to make before S7 starts

| #   | Question                                                                          | Default taken below if you say nothing                                                                                                   |
| --- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Does the AI composer (S9) ship on every plan, or is it paid?                      | Paid: `Capability::SignalComposer`, middle tier up. The library and the manual form stay on every plan.                                  |
| 2   | Is the inbound-webhook source (S13) in scope, or post-launch?                     | In scope, but **last** inside S13 and behind `Capability::ApiAccess`.                                                                    |
| 3   | Can a team publish a definition to the platform library, or does an admin curate? | Admin curates (S15); a team can only _submit_.                                                                                           |
| 4   | Full-article fetch (S12) — a cost cap per team per month?                         | Yes: `signals.article_fetch.monthly_cap_micros`, default off.                                                                            |
| 5   | Does the mobile app get the builder?                                              | No — the feed and the playbook inbox only. Building stays on the web, per the Phase 18 "field companion, not a second web app" decision. |

---

## Phase S7 — Signal definitions become data

**Goal:** Replace the closed `SignalEventType` enum with a team-owned, platform-seeded
`SignalDefinition` row, so a signal type can be created, edited, paused and retired by a user
without a deploy. Behaviour for existing teams is **identical** on the day this ships — the
three enum cases become three seeded presets, and every existing watch and signal points at
one of them.
**Depends on:** nothing (v1 complete).
**Modules & docs:** Signals; Reporting; Admin; `docs/decisions/0018-*`;
`docs/modules/signals.md`.

### The decision to write down first (ADR 0018)

`CLAUDE.md` §2 says **"Enums for every status/type column. No magic strings."** This phase
breaks that for exactly one column, so the reason has to be on the record rather than
discovered later by someone reading the diff.

The line is **whether the application branches on the value**:

- `SignalStatus`, `SignalRunStatus`, `SignalWatchCadence` and `SignalDismissReason` **stay
  enums**. The app branches on all four — a `Converted` signal refuses a dismiss, a `Daily`
  cadence is stale after 24 h, `indicatesMisclassification()` reads the reason. A closed set
  the code reasons about is an enum.
- `SignalEventType` **becomes a row**. Grep it: nothing branches on which case it is. It is
  used to pick query templates (data), render a label and a description (data), map an ICP
  buying signal by keyword (data), filter the feed (a value) and group a report (a key). Every
  one of those is a property of the type, not a decision about it. A "type" the code only ever
  carries, displays and groups by is a **row with columns**, and keeping it as an enum is what
  makes adding one a deploy.

Second decision in the same ADR: **the preset library is platform data, not tenant data.** A
preset is `team_id = null`, readable by every team, writable only by the admin panel. A team
"uses" a preset by **cloning** it into their own definition — not by referencing it — so a
platform edit never silently changes what a team is paying to run, and a team's tuning never
leaks upward. The clone keeps `preset_key` + `preset_version` so S15 can tell a team their
preset has moved on and offer the diff.

Third: **`event_key` is denormalised onto `signals`** beside the FK. A definition can be
deleted; a signal from it is history and must still render, group and report. `signals.lead_id`
already follows this shape (one-way, `nullOnDelete`) for the same reason.

### Tasks

1. [ ] **ADR 0018 — "Signal definitions are data, not an enum"**: the branching test above,
       the preset-library-is-platform-data decision, the clone-not-reference decision, the
       denormalised `event_key`, and what stays an enum. Add it to `CLAUDE.md` §5 and
       `docs/architecture.md`.
2. [ ] **Migration `create_signal_definitions_table`** — `team_id` (**nullable**, FK
       `cascadeOnDelete`; null = platform preset), `key` (string 64, the stable storage key),
       `name`, `description` (the one-liner that was `SignalEventType::description()`),
       `category` (for grouping the library: `liquidity`, `growth`, `movement`, `distress`,
       `hiring`, `regulatory`, `custom`), `icon` (a Lucide name, bounded to an allowlist),
       `is_active`, `query_templates` (json `list<string>`, with `{location}` and the S8
       placeholders), `match_keywords` (json — the `SignalQueryBuilder::KEYWORDS` list for ICP
       buying-signal mapping), `classification_rules` (json: `includes` / `excludes` / `notes`,
       prose fed to the AI prompt), `thresholds` (json: `min_confidence`, `min_relevance`,
       nullable → fall back to config), `suggested_approach_hint` (nullable), `markets`
       (json `list<Market>`, nullable → any; a preset that only makes sense in the UK says so),
       `preset_key` + `preset_version` (nullable, provenance of a clone), `version` (int,
       bumped on every save, so a signal can record which version classified it), `created_by`
       (nullable FK users), timestamps, soft deletes.
       Unique `(team_id, key)`; unique `(key)` where `team_id is null`; index
       `(team_id, is_active)`.
       **MySQL check:** those two partial-unique shapes differ between MySQL and SQLite, and
       `memory/sqlite-tests-mysql-prod.md` is exactly about this — if a partial index is not
       portable, use a generated `scope_key` column instead and say so in the migration.
3. [ ] **`SignalDefinition` model** — `BelongsToTeam`, plus the escape hatch for the
       `team_id = null` presets. An `orWhereNull('team_id')` inside the global scope is **not**
       acceptable; add an explicit `PlatformPresetReader` service that queries without the
       scope and is the only place that does, mirroring how `PlatformContext` isolates scope
       removal in Admin. Casts, `effectiveThresholds()`, `isPreset()`, `appliesToMarket()`.
       `SignalDefinitionFactory` with a `preset()` state.
4. [ ] **Migration `signals_and_watches_point_at_definitions`** - `signals`: add `signal_definition_id` (nullable FK, `nullOnDelete`); rename
       `event_type` → `event_key` and drop the enum cast (a rename is cheaper to reason
       about than a new column plus a backfill — do one or the other, and say which in the
       migration comment). Add `(team_id, event_key, published_at)` only if the explain plan
       asks for it; otherwise add, don't swap. - `signal_watches`: `event_types` (json of enum values) → `signal_definition_ids`
       (json of ints), **plus** a kept `event_keys` json recording what was watched when.
       The builder writes both.
5. [ ] **Data migration + `SignalPresetSeeder`** — seed the three v1 types as platform presets
       with **exactly** their current templates (from `config/signals.php`), descriptions
       (from the enum), keywords (from `SignalQueryBuilder::KEYWORDS`) and thresholds (from
       config); then clone one per existing team and repoint every existing `signal_watch` and
       `signal` at that team's clone. **Idempotent and reversible** — a failed deploy must not
       leave a team's watches pointing at nothing. Test against a fixture of a team with two
       watches, one paused, one holding a converted signal.
6. [ ] **`SignalQueryBuilder` reads the definition**, not config: templates, keywords and
       thresholds come off the row. The config templates stay in `config/signals.php` as the
       **seed source only**, commented as such, and the builder stops reading them.
7. [ ] **The AI prompt stops enumerating types.** `signals.extract_event` **v2** (a new
       version, never an edit — the rule in `docs/ai-prompts.md`): `event_type` becomes a
       string constrained to the keys **in this batch's watch**, injected into the prompt body
       with each definition's name, description and `classification_rules.includes` /
       `.excludes`. The JSON schema's `enum` becomes a bounded `maxLength` string, so
       `RunSignalWatchAction` must **reject a returned key that was not offered** — the schema
       can no longer be the guard here, and the Action has to be. Record the swap in the ADR;
       it is the one real safety regression in this phase, and it needs a test that feeds a
       hostile key back.
8. [ ] **Update every coupling point** (37 files; `grep -rl SignalEventType`):
       `ExtractedSignal`, `SignalFilters`, `SignalQuery`, `SignalWatchData`,
       `ListSignalsAction`, `ListSignalsRequest`, `SignalWatchResource`, `SignalResource`,
       `Signal`, `SignalWatch`, `Admin\Resources\SignalResource`,
       `Reporting\Actions\BuildSignalBreakdownAction` (it iterates `SignalEventType::cases()`
       — it must now iterate the team's definitions, and must keep rendering a bucket for a
       **deleted** definition from `event_key`), the factories, the seeders, the frontend types
       and both Vue pages.
       **Delete `SignalEventType`.** A dead enum left behind is the next person's magic string.
9. [ ] **`SignalDefinitionPolicy`** — `viewAny` / `view` / `create` / `update` / `delete`; view
       for every role, mutations Owner/Admin/Member, matching `SignalWatchPolicy`. A **preset is
       never updatable or deletable by a tenant**, and that is a policy answer, not a
       controller `if`.
10. [ ] **Definition CRUD, minimal UI.** No builder yet (that is S9) — this phase ships the
        plumbing plus the smallest screen that proves it: `/signals/types`, listing the team's
        definitions and the preset library, clone-a-preset, a plain form for name/description/
        templates/thresholds, pause, and delete (refused while a watch uses it —
        `SignalsException::definitionInUse()`, 422, naming the count). **The watch editor's
        three fixed checkboxes become a checkbox per active definition**, which is the literal
        ask, delivered in this phase.
11. [ ] **API v1 parity in the same phase** — `GET/POST /signal-definitions`,
        `GET/PATCH/DELETE /signal-definitions/{id}`, `GET /signal-definition-presets`. Named,
        `auth:sanctum`, an `ability:` from `Auth\Enums\TokenAbility`, and present in
        `docs/api/openapi.json` via `composer api:docs`. `RouteContractTest` is the gate.
12. [ ] **Audit** every definition create/update/delete/clone, recording on delete the watches
        and signals that referenced it — the same reasoning as `DeleteSignalWatchAction`'s
        cascade counts.
13. [ ] **Ceiling** — `signals.max_definitions_per_team` (default 25) enforced in
        `SaveSignalDefinitionAction`. Presets don't count. The real plan limit arrives in S16;
        this is the config floor so the phase can't ship unbounded.

**Data model:** `signal_definitions` (new); `signals.signal_definition_id` + `event_key`;
`signal_watches.signal_definition_ids` + `event_keys`.
**Tests:** the data migration repoints every row for a team with paused watches and converted
signals; a definition in use refuses deletion; a preset refuses a tenant edit; a cross-tenant
definition id on a watch save is **dropped and logged** (not 403 — matching
`SaveSignalWatchAction`'s treatment of an out-of-team ICP); the AI returning an unoffered
`event_type` is rejected and the item dropped; the reporting breakdown still renders a bucket
for a deleted definition; `SignalEventType` no longer exists anywhere; `RouteContractTest`
green.
**Acceptance:** an existing team sees no change; a new definition can be created in the UI and
a watch run picks it up on the next cadence; all gates green; `laravel-module-reviewer` clean.
**Dispatch prompt:** _"Execute Phase S7 of signals-v2-plan.md. Read CLAUDE.md,
docs/modules/signals.md and docs/decisions/0011. Write ADR 0018 first, then replace
SignalEventType with the signal_definitions table and its presets, migrate existing data,
update all 37 coupling points, ship the minimal /signals/types screen and the API v1 routes,
and regenerate openapi.json. Run laravel-module-reviewer and brand-reviewer, then the
phase-complete checklist."_
**Risks:** the data migration. It touches every team's live watches, and a half-applied run
leaves signals orphaned. Write it reversible, test it against a seeded multi-team fixture, and
**run it against a MySQL copy** before it goes near production — the partial unique indexes
and the json column changes are exactly the class of thing SQLite waves through
(`memory/sqlite-tests-mysql-prod.md`).

---

## Phase S8 — Geography, and the jurisdiction bug it uncovers

**Goal:** A watch covers the markets and places a team actually sells into — several at once —
and the UK becomes a first-class Signals market. Along the way, fix a compliance defect that
multi-geography watches would otherwise turn from latent into live.
**Depends on:** S7.
**Modules & docs:** Signals; Core (`Market`); Compliance; `docs/decisions/0019-*`;
`docs/compliance.md`; `docs/modules/signals.md`.

### The defect, stated plainly

`ConvertSignalToLeadAction::stampOrigin()` takes the outreach restriction from
**`$signal->watch->market->outreachRestriction()`** — the _watch's_ market. In v1 that is
sound, because every watch is UAE and every signal in it is about the UAE. The moment a watch
can span places it is wrong in both directions: a UAE-market watch that catches a London
funding round stamps a UAE prior-consent restriction on a UK lead (over-restrictive, merely
annoying), and a UK-market watch that catches a Dubai relocation stamps **nothing** on a lead
PDPL says needs prior consent (under-restrictive, and a legal problem — `docs/compliance.md`
§3).

The fix is a principle worth an ADR: **the restriction follows the subject, not the watch.**
The AI already returns a `location` per signal. S8 resolves that to a jurisdiction at
extraction time, stores it on the signal, and conversion reads **the signal's** jurisdiction,
falling back to the watch's market only when the signal has none. "Unknown" resolves to the
**more restrictive** of the candidates, because the direction to fail in is obvious.

### Tasks

1. [x] **ADR 0019 — "Search geography is not the compliance market"**: two concepts were one
       column. `search_region` (a Serper `gl`/`hl` pair plus place names — a question about
       _where to look_) and `Market` (the compliance regime, currency and locale — a question
       about _which rules apply_). A watch picks regions; a **signal** carries a jurisdiction;
       `Market::outreachRestriction()` is asked of the signal's jurisdiction. Record the
       more-restrictive-on-unknown rule and why.
2. [x] **`Core\Enums\Market` gains nothing.** Resist adding `Saudi`, `Global` and friends here
       — it is the compliance regime enum, and every case on it implies a legal position the app
       does not yet hold (ADR 0007). Instead add **`Signals\Enums\SearchRegion`** (or a
       `SearchRegion` value object over `config/signals.regions`) with `gl`, `hl`,
       `default_locations` and a **nullable** `market` saying which compliance regime, if any,
       this region maps to. Seed `ae`, `gb`, `sa`, `us`, `in`, `sg`, `global`. A region with
       **no** market maps to the restrictive default, and the ADR says why.
3. [x] **Migration `watches_cover_many_places`** — `signal_watches.locations` (json
       `list<string>`, max 10) replaces `location`; `signal_watches.regions` (json
       `list<SearchRegion>`, max 5) replaces the implicit market→`gl`. Keep `market` on the
       watch as the **team's own declared regime** for it (it still seeds defaults and still
       backstops a signal with no resolvable jurisdiction) but it stops driving `gl`. Backfill:
       `location` → `[location]`, `market = uae` → `regions = ['ae']`.
4. [x] **Migration `signals_carry_a_jurisdiction`** — `signals.jurisdiction` (string 8,
       nullable, ISO-3166-1 alpha-2) and `signals.resolved_market` (string, nullable, a
       `Market` value). Both set at extraction.
5. [x] **`SignalJurisdictionResolver`** (`Services/`) — the AI's free-text `location` → a
       country code, **deterministic, no AI**: a config map of place names and aliases per
       region (`Dubai`, `DXB`, `Abu Dhabi`, `United Arab Emirates`, `UAE` → `AE`), the watch's
       regions as the tiebreak, and null when it cannot tell. Unit-tested hard; this is the
       input to a legal rule, so it gets the coverage a legal rule deserves.
6. [x] **`ConvertSignalToLeadAction` asks the signal.** `resolvedMarket() ?? watch->market`,
       and when **neither** resolves, the most restrictive restriction across the watch's
       regions. Keep `raw.outreach_restriction` surviving the de-duplicator merge exactly as it
       does now, and add the **jurisdiction** to `raw` as well, so a lead can explain its own
       restriction. Extend `tests/Feature/Signals/ConvertSignalToLeadActionTest.php` with four
       cases: signal-UK/watch-UAE, signal-UAE/watch-UK, signal-unknown/watch-UAE,
       signal-unknown/watch-multi-region.
7. [x] **`SignalQueryBuilder` goes multi-place.** One query per (definition template ×
       location), de-duplicated, with a **hard ceiling** on the product
       (`signals.max_queries_per_run`, default 40) and a deterministic trim order, so a team
       picking 6 definitions × 10 locations gets a predictable, affordable run rather than 240
       queries — and the run records that it trimmed, **visibly**, on the watch card. A silent
       trim is a lie about coverage.
8. [x] **`hl` per region**, which unlocks the v1 backlog's **Arabic-language queries** for
       free: a region can declare `hl: ar` and a definition can carry `query_templates_ar`.
       Ship the plumbing; writing Arabic _templates_ is a content task, flagged for the
       native-speaker pass already pending in `signals.md`.
9. [x] **UK presets.** A platform pack for `gb`: `acquisition`, `funding_round`,
       `administration_or_insolvency`, `psc_change` (a change in people with significant
       control — the UK's closest published analogue to "the owner just sold"),
       `planning_permission_granted`, `senior_appointment`. Templates written and **spiked
       against live Serper `/news` the way S0 was** — the S0 finding (exact phrases and
       `-based` return zero; plain keywords return 3–10) is a Serper fact, not a UAE fact, so
       the same discipline applies. Record the spike table in `docs/modules/signals.md`.
10. [x] **The watch editor takes places and regions**, with the team's market pre-selecting a
        region and an explainer that names the compliance consequence in brand voice: a UAE
        signal is approached by introduction, not cold email, wherever the watch lives.
11. [x] **API + docs** — `locations` / `regions` on the watch endpoints, `jurisdiction` on the
        signal resource, `openapi.json` regenerated, `docs/compliance.md` §3 updated with the
        follows-the-subject rule.

**Data model:** `signal_watches.locations`, `.regions`; `signals.jurisdiction`,
`.resolved_market`.
**Tests:** the four conversion-restriction cases; the resolver's place map; the query ceiling
trims deterministically and reports it; a v1 watch backfills to one location and one region and
produces **byte-identical queries** to before; the UK preset templates produce non-empty
results against a recorded fixture.
**Acceptance:** a watch can cover Dubai + London; a London funding round converts to a lead
with **no** UAE restriction and a Dubai one converts with it, whichever watch found it; all
gates green.
**Dispatch prompt:** _"Execute Phase S8 of signals-v2-plan.md. Write ADR 0019 first. Add
SearchRegion, multi-location/multi-region watches, the jurisdiction resolver, and move the
outreach restriction from the watch's market to the signal's jurisdiction. Spike the UK preset
templates against live Serper /news and record the table in docs/modules/signals.md. Update
docs/compliance.md. Run laravel-module-reviewer and brand-reviewer, then phase-complete."_
**Risks:** (a) the jurisdiction resolver is a legal input built on an AI's free-text guess —
keep it deterministic, keep "unknown" restrictive, and never let it _remove_ a restriction a
lead already carries; (b) query count × cost. The ceiling is not optional.

---

## Phase S9 — The signal builder: library, plain-language composer, preview

**Goal:** The phase that makes dynamic definitions _usable_ rather than merely possible. A user
describes a buying moment in their own words; Alima drafts the definition; they see what it
would have found **before** they save it; they tune and ship. This is the phase that earns the
feature a demo.
**Depends on:** S7, S8.
**Modules & docs:** Signals; AiPlatform; Billing; `docs/ai-prompts.md`;
`docs/modules/signals.md`; `docs/runbooks/signals-demo.md`.

### Three doors into a definition

1. **Library** — browse platform presets by vertical pack and category, filtered to the team's
   regions, with "what this finds" examples; clone in one click. The fast path, and the one
   that teaches the format.
2. **Composer** — a text box: _"tell me when a UK manufacturing business changes hands or the
   owner sells a controlling stake."_ The `signals.compose_definition` prompt returns a **draft
   definition** — name, description, category, icon, 3–6 query templates,
   `classification_rules.includes` / `.excludes`, suggested thresholds, match keywords, an
   approach hint — which the user then **reviews and edits**. The AI drafts; the user owns.
3. **Advanced** — the raw form from S7, for the person who knows what `tbs=qdr:w` does.

### Preview is the feature

The S0 spike exists because **nobody can tell whether a query template is any good by reading
it.** Seven of eight looked fine and returned zero. A builder without a preview just moves that
discovery from a developer's spike into a customer's first silent week.

`PreviewSignalDefinitionAction` runs the draft's queries against the live provider with `num`
clamped small, classifies the first batch through `signals.extract_event` v2, and returns **what
would have been kept, and what would have been dropped and why** — scores, thresholds,
ignore-list hits, geo-qualification. It persists **no signals**. It is metered, rate-limited,
and shows its cost before it runs.

### Tasks

1. [x] **Prompt `signals.compose_definition` v1** (new, `AiTier::Reasoning` — this is a
       drafting task, not a classification one; the cheap tier writes bad templates). System
       prompt constraints that matter: plain-keyword templates only, with an explicit statement
       of **why** (the S0 finding — exact phrases and `-based` return nothing on `/news` with a
       tight recency window); templates about **events**, never stated intent; a `{location}`
       placeholder, never a baked-in city; the approach hint stays LinkedIn-or-referral;
       `excludes` must name round-ups, trackers, listicles and opinion; no contact finding; and
       a refusal (`is_viable: false`, with a reason) for a request that is really "find me rich
       people" rather than an event. Wrap the user's text in `UntrustedContent`. Bounded JSON
       schema with `maxItems` / `maxLength` on everything, per the S6 security review's
       standing rule. Seed it and document it in `docs/ai-prompts.md`.
2. [x] **`ComposeSignalDefinitionAction`** — prompt → `DraftSignalDefinition` DTO
       (spatie/laravel-data, `rules()` shared with the save path, so a draft can only contain
       what a definition can hold). Metered through `AiCreditGuard`, rate-limited per member,
       and it **never auto-saves**.
3. [x] **`PreviewSignalDefinitionAction`** - Builds queries from the draft via `SignalQueryBuilder` with **no watch row**: extract a
       `SignalQuerySpec` so the builder stops needing a persisted model. (A builder that needs
       a saved row is why previews are hard. Prefer the extraction over passing an unsaved
       model.) - Caps: `signals.preview.max_queries` (default 4), `num` 10, **one** AI batch,
       `signals.preview.rate_limit` per member per hour (default 10), and a per-team daily
       preview budget in micro-units. - Returns `list<PreviewedItem>` — headline, source, published date, the classification,
       both scores, and a **`verdict`** of `kept` / `below_confidence` / `below_relevance` /
       `not_an_event` / `wrong_location` / `ignored_domain`, so the user learns what to change
       instead of just seeing an empty list. - **Persists nothing** into `signals`. It **does** write a `signal_previews` row (team,
       draft hash, query count, cost micros, kept count), because the spend is real and must
       appear in cost reporting and the preview budget — a cost with no ledger row is a cost
       nobody can cap. - Outside any transaction, obviously.
4. [x] **Cost estimator, in the form, before anything runs** — "this definition is ~N queries
       per run; at daily cadence across your 2 locations that is ~$X/month", from
       `signals.cost_micros_per_request` × template count × locations. A standing instruction to
       spend money should say how much, where it is created.
5. [x] **Vertical preset packs** (platform presets, `team_id = null`), each with a pack label, a
       short "who this is for", and spiked templates. Ship at least six so the library reads as
       a product rather than a placeholder: - **Luxury real estate** (the v1 thesis, promoted to a pack): acquisition, funding
       round, relocation, family-office opening, senior expat appointment. - **Recruitment / staffing**: hiring surge, new office or market entry, funding round
       (they hire after), senior departure, RTO or restructure announcement. - **B2B SaaS**: new VP/Director in a target function, funding round, competitor migration
       or outage, expansion into a new region, security or compliance incident. - **Wealth management / private banking**: company sale, IPO or listing, large secondary,
       published estate or inheritance event, sports/entertainment contract. - **Construction / fit-out / interiors**: planning permission granted, project award, new
       HQ or relocation, hotel or tower launch, large tender result. - **M&A advisory / corporate finance**: PSC or shareholding change, auditor or CFO
       change, administration or insolvency filing, carve-out or divestment, PE platform
       add-on.
       Every pack's templates get the S0 treatment: run them, record the counts, drop or
       rephrase anything returning zero, and put the table in `docs/modules/signals.md`.
       **A preset never run against the live provider is a guess with a label on it.**
6. [x] **`Capability::SignalComposer`** (decision #1) behind the one `CapabilityGate`
       (ADR 0015), with the library and the advanced form on **every** plan — a team can always
       clone a preset and hand-write templates; the AI drafting is the paid shortcut. Enforced
       in the Action.
7. [x] **The builder UI** (`/signals/types`, rebuilt): the library browser with pack and
       category filters; the composer box with example prompts in brand voice; the review form
       with per-field "why this" explainers; the preview panel with its verdict chips; the cost
       line; save-as-draft vs activate. Brand tokens, EN/AR parity, RTL, keyboard-complete, and
       the shared `FilterToggle` / `FilterGroup` chips already used by the feed. Run
       `brand-reviewer`.
8. [x] **API** — `POST /signal-definitions/compose`, `POST /signal-definitions/preview` (both
       200 with the draft/preview, both metered and throttled), `openapi.json`.
9. [x] **Runbook** — extend `docs/runbooks/signals-demo.md` with the builder demo: compose a
       definition live, preview it, show the verdicts, save it, show it on a watch. That demo is
       the sales motion for this whole plan.

**Data model:** `signal_previews` (team-scoped ledger: draft hash, queries, cost micros, kept
count, created_by). No change to `signals`.
**Tests:** the composer refuses a find-rich-people request with a reason; a hostile instruction
inside the user's own text does not escape `UntrustedContent` (a prompt-injection test asserting
the draft contains no injected marker); preview persists zero signals and one `signal_previews`
row; preview respects the query cap, the member rate limit and the team budget; every verdict
branch is produced by a crafted fixture; the capability gate refuses on a plan without it while
the library still works; the cost estimate matches the query count.
**Acceptance:** a user goes from a sentence to an active, previewed definition without leaving
the screen, in under a minute, with the cost on screen before they commit.
**Dispatch prompt:** _"Execute Phase S9 of signals-v2-plan.md. Read docs/ai-prompts.md and
docs/decisions/0015. Add the signals.compose_definition prompt (reasoning tier, injection-safe,
plain-keyword templates per the S0 finding), ComposeSignalDefinitionAction,
PreviewSignalDefinitionAction with its caps and its signal_previews ledger, the six vertical
preset packs with live-spiked templates, Capability::SignalComposer, and the rebuilt
/signals/types builder. Run security-review on the composer prompt and the preview path, then
laravel-module-reviewer, brand-reviewer and phase-complete."_
**Risks:** (a) the composer writes plausible templates that find nothing — preview is the
mitigation, and that is why it is in the same phase rather than a later one; (b) preview is a
user-triggered spend loop, so the three caps are a blocker, not a nice-to-have; (c) prompt
injection via the user's own description, which is why it is wrapped and tested.

> **After S9 the original ask is fully delivered.** Everything below is the moat.

---

## Phase S10 — Watchlists: signals about the accounts you already care about

**Goal:** Signals stops being only a top-of-funnel discovery tool and becomes an
**account-monitoring** tool as well: watch named companies and people, including everything
already in the team's pipeline, and surface an event about an existing lead **onto that lead**
rather than as a new one.
**Depends on:** S7, S8.
**Modules & docs:** Signals; LeadsWorkspace; Notifications; `docs/modules/signals.md`;
`docs/modules/leads-workspace.md`.

### Why this is the highest-value idea in the plan

v1 answers "who just came into money?" — a cold question. This answers **"what just happened to
the forty accounts I am already working?"** — a warm one, with a known contact, an existing
relationship and a reason to call today. It is also what makes Signals sticky: a discovery feed
can be switched off; a monitor over your own pipeline cannot, without going blind.

### Tasks

1. [x] **Migrations** — `signal_watchlists` (team-scoped: name, `source_kind`
       (`manual` | `pipeline` | `segment`), `is_active`, sync settings) and
       `signal_watchlist_entries` (watchlist, `entity_type` (`company` | `person`), `name`,
       `aliases` json, `domain` nullable, `lead_id` nullable FK `nullOnDelete`, `is_active`).
       Unique `(watchlist_id, normalised_name)`. Plus `signal_watches.signal_watchlist_id`
       (nullable) — a watch is **either** topical (definitions × locations) **or** entity-scoped
       (definitions × entities), and the model should make that difference explicit rather than
       leaving two half-filled column sets.
2. [x] **`SignalQueryBuilder` learns entity queries** — per entity × region, with the same hard
       ceiling. The plan expected `"{entity}" {definition keyword}` and asked for the quoting
       assumption to be spiked rather than assumed. **It was, and it was wrong both ways:** every
       quoted query returned zero (12/12) and appending a keyword _lost_ genuine results, so a
       company is searched by **bare name alone** and a person by name + the definition's first
       match keyword (ADR 0021 §3, table in `docs/modules/signals.md`). The ceiling then stopped
       being a trim and became a **ring** (`signal_watches.entity_cursor`), because trimming a
       250-account list to the first forty would never search the eighty-ninth account at all.
3. [x] **`SyncPipelineWatchlistAction` + `SyncPipelineWatchlistJob`** — populate a
       `pipeline`-kind watchlist from the team's leads, filtered by stage and/or owner, capped
       (`signals.watchlist.max_entries`, default 250), scheduled nightly, idempotent, and
       **honouring suppression and erasure**: a lead anonymised by Compliance leaves the
       watchlist on the next sync, and a DSAR erasure removes its entries in the same
       transaction as the rest. Coordinate with `LeadAnonymiser` / `TeamDataArchiver` rather
       than discovering this in Compliance's tests.
4. [x] **Match a signal to an existing lead.** `SignalLeadMatcher` — subject company /
       counterparty / person against the team's leads using the **same** normalisation
       `SourcedLead::dedupeKey()` and `LeadDeduplicator` already use. **Reuse them; do not write
       a third name-matching rule** — three normalisers is how two leads become three. On a
       match, set `signals.matched_lead_id` (a new nullable FK, distinct from `lead_id`, which
       means "converted into") and write a `LeadActivityType::SignalMatched` timeline entry on
       the lead.
5. [x] **"Attach to lead" replaces "convert" when a match exists.** The card offers _Add to
       {Lead}_ — writing the timeline entry, the origin stamp and the activity, and creating
       **no** lead — or _Convert anyway_ for the false-positive case. Reuse
       `ConvertSignalToLeadAction`'s attach-or-create path; it already handles the
       fill-gaps-never-overwrite rule and the `field_sources` stamping (ADR 0008).
6. [x] **Account-signal notification** — `NotificationType::SignalOnAccount`, routed by
       `NotificationDispatcher` (never `$user->notify()`), aggregated per run the way
       `AnnounceTopLeadsJob` aggregates, and **addressed to the lead's owner** where there is
       one. An event about an account somebody owns should reach that person, not the team.
7. [x] **Feed + lead detail UI** — a _My accounts_ tab in the feed, scoped to matched signals;
       and the lead detail "The event" panel (S4) grows into a **list** of events in date order.
       A small change with a large effect: the lead page becomes a timeline of what has happened
       to that company.
8. [x] **API + docs** — watchlist CRUD, bulk-add entries (a bounded CSV-shaped payload),
       `matched_lead` on the signal resource, `openapi.json`, module docs.

**Data model:** `signal_watchlists`, `signal_watchlist_entries`, `signals.matched_lead_id`,
`signal_watches.signal_watchlist_id`.
**Tests:** an entity-scoped watch builds the right queries and respects the ceiling; the
pipeline sync is idempotent, capped, and drops an anonymised lead; a matched signal writes one
timeline entry and no duplicate on re-run; attach-to-lead creates no lead and fills only gaps;
the owner, not the team, gets the notification; cross-tenant watchlist entries are refused.
**Acceptance:** a team points a watch at its pipeline, and an acquisition of a company they are
already working shows on that lead's page and in its owner's notifications the morning it is
published.
**Dispatch prompt:** _"Execute Phase S10 of signals-v2-plan.md. Read docs/modules/signals.md,
docs/modules/leads-workspace.md and docs/modules/notifications.md. Add watchlists, entity-scoped
queries, the pipeline sync job, SignalLeadMatcher reusing SourcedLead/LeadDeduplicator
normalisation, attach-to-lead, the SignalOnAccount notification via NotificationDispatcher, and
the UI. Run laravel-module-reviewer, brand-reviewer and phase-complete."_
**Risks:** false-positive matching attaching an event to the wrong company. Err toward _no_
match, show the match confidence, and make _Convert anyway_ a first-class choice rather than a
buried escape hatch.

---

## Phase S11 — One event, one card: clustering and a dedupe key that survives redirects

**Goal:** Stop showing eight cards for one acquisition, and fix the `url_hash` identity defect
already written down in `docs/modules/signals.md`.
**Depends on:** S7.
**Modules & docs:** Signals; `docs/modules/signals.md`.

### Two defects, one phase

1. **Dedupe is per-URL, so coverage looks like noise.** A Dubai acquisition reported by Gulf
   News, Khaleej Times, Zawya, Arabian Business and three aggregators is five to eight signals,
   each costing an AI classification, each needing its own dismiss. The feed's perceived quality
   is dominated by this.
2. **`url_hash` stops being the article's identity behind a Google redirect.** Already
   documented: Serper sometimes returns `https://google.com/goto?url=<opaque token>`. If the
   token varies between requests, the same article re-enters as a new signal **on every run** —
   a fresh AI batch each time. The candidate fix in the doc (fall back to
   `sha1(source_name + normalised headline)` for known redirectors, and match the ignore list
   against `source_name` too) is adopted here, because clustering needs a content identity
   anyway.

### Tasks

1. [x] **`SignalItemNormaliser` gains a content identity.** `contentHash` =
       `sha1(normalised source_name + normalised headline)` — lower-cased, punctuation and stop
       words stripped, outlet suffixes ("| Gulf News") removed. For a **known redirector host**
       (a config list, `google.com/goto` first) the dedupe identity becomes the content hash;
       otherwise the canonical URL hash stays primary with the content hash stored beside it.
       The unique index moves to `(team_id, dedupe_hash)`; `url_hash` is kept as data.
2. [x] **The ignore list matches `source_name` too.** The documented consequence #2 — every
       redirected item's host is `google.com`, so a host-only ignore list cannot work. Match on
       host **or** normalised source name, which also makes the list behave the way a team
       expects when they type "Khaleej Times" instead of a domain.
3. [x] **Migration `signal_clusters`** — team-scoped: `cluster_key`, `signal_definition_id`,
       `event_key`, `primary_signal_id`, `sources_count`, `first_published_at`,
       `last_published_at`, `subject_fingerprint`. Plus `signals.signal_cluster_id`, nullable FK.
4. [x] **`SignalClusterer`** — cluster key from `(event_key, subject fingerprint, published
       week)`, where the subject fingerprint reuses the **same** normalisation as
       `SignalLeadMatcher` / `SourcedLead` (one normaliser; if S10 has not shipped, build it
       here and S10 reuses it). Deterministic, no AI. The **primary** signal is the
       highest-confidence one, earliest publication breaking ties — the first report of an event
       is usually the best one.
5. [x] **Classify once per cluster.** In `RunSignalWatchAction`, group candidate items by
       provisional cluster key **before** the AI batch, send one representative, and apply the
       result to the cluster. This is the phase's cost win and it is a large one: it cuts AI
       spend on a busy news week roughly in proportion to how well a story travels. Keep a
       `signals.cluster_before_ai` flag, default on, so a regression is a config flip rather
       than a rollback.
6. [x] **Remember what was rejected.** The documented S3 trade-off (b): non-event and
       below-threshold items are forgotten, so every run re-pays to classify the same round-ups.
       Add `signal_rejections` — team, `dedupe_hash`, `verdict`, `seen_count`, `last_seen_at`,
       with its own short retention (30 days) — and skip a hash rejected for the same definition
       inside the window. Counts-only logging, **no article text**, which is also why this table
       is cheap to retain.
7. [x] **UI** — the card shows "Reported by 6 sources", expanding to the list; dismissing
       dismisses the cluster; converting converts from the primary. The feed's counts count
       clusters, not articles, and the tab labels say so.
8. [x] **Backfill** — cluster existing signals in a chunked command
       (`signals:cluster-backfill --dry-run`), newest 90 days only, idempotent. Do **not** merge
       across the converted boundary: a converted signal stays its own primary, because its
       `lead_id` is provenance.

**Data model:** `signal_clusters`, `signals.signal_cluster_id`, `signals.content_hash` /
`dedupe_hash`, `signal_rejections`.
**Tests:** eight fixtures of one event produce one cluster with `sources_count = 8` and one AI
call; a varying Google redirect token produces **one** signal across two runs (the regression
this phase exists for); a rejected hash is skipped on the next run and counted; dismissing a
cluster dismisses its members; the backfill is idempotent and never merges a converted signal
away; the ignore list matches by source name.
**Acceptance:** a week of real Dubai news produces materially fewer, better cards; measured AI
spend per run falls; the redirect defect is closed and the doc's "Known limitations" entry is
struck.
**Dispatch prompt:** _"Execute Phase S11 of signals-v2-plan.md. Read the Known limitations
section of docs/modules/signals.md. Add the content-hash dedupe identity for redirector hosts,
source-name ignore matching, signal_clusters with cluster-before-AI classification,
signal_rejections, the clustered card UI and the backfill command. Run laravel-module-reviewer,
brand-reviewer and phase-complete, and strike the closed limitations from the module doc."_
**Risks:** over-clustering two genuinely different events in the same week for the same company
(a funding round and an acquisition differ by `event_key`, so the key handles it; two funding
rounds in one week is rare, and the amount is in the fingerprint). Ship the flag.

---

## Phase S12 — Ranking, learning and "why am I seeing this?"

**Goal:** Order the feed by how much a signal is worth to _this_ team, explain every card, and
let the team's own dismiss behaviour tune the thing without anyone editing a template.
**Depends on:** S7, S11 (clusters are what gets scored).
**Modules & docs:** Signals; Scoring; Reporting; `docs/modules/signals.md`;
`docs/modules/reporting-analytics.md`.

### Tasks

1. [x] **`SignalScorer`** — a composite 0–100 `heat` from components that are each **stored**,
       not just the total: AI `relevance`; AI `confidence`; **recency decay** (an event's value
       halves on a configurable half-life — the feed already orders by `published_at` for
       exactly this reason, so make it explicit); **amount**, banded against the team's own
       history rather than an absolute (a £2m exit matters to one team and not another);
       **source authority** (a per-team learned stat, below); **subject seniority** (founder /
       C-suite / other, from the extracted role); **cluster breadth** (`sources_count` — a story
       six outlets carried is likelier to be real); and **ICP fit**, reusing `Scoring`'s existing
       criteria match rather than inventing a second fit score. Weights in `config/signals.php`,
       per-definition overrides allowed. **Deterministic and unit-tested**; no AI in the scorer.
2. [x] **Migration** — `signals.heat` (int) + `signals.score_breakdown` (json). Index
       `(team_id, status, heat)` for the new default sort. Keep the `published_at` sort
       available; the feed gets a sort control, defaulting to heat.
3. [x] **`signal_source_stats`** — team, `source_name`, `signal_definition_id`, `kept_count`,
       `dismissed_count`, `converted_count`, `not_an_event_count`, `last_seen_at`. Updated on
       dismiss/convert/attach via a listener on the existing events. This is the v1 backlog's
       "learning from dismiss reasons", made concrete and bounded.
4. [x] **Tuning suggestions, not silent tuning.** A `SignalTuningAdvisor` reads
       `signal_source_stats`, `signal_rejections` and
       `SignalDismissReason::indicatesMisclassification()` and **proposes**: domains to add to
       the ignore list (a source dismissed N times at ≥X%), a threshold nudge per definition
       ("12 of your last 15 dismissals of _Hiring surge_ were 'not an event' — raise its
       confidence floor to 75?"), and templates to retire. Surfaced as cards the user **accepts
       or declines**, each with its evidence. **Nothing auto-applies** — a feature that silently
       retunes itself is a feature nobody can debug, and the dismiss reason is already documented
       as being "kept for tuning, not for display". Accepting a suggestion is an audited
       definition edit like any other.
5. [x] **"Why you're seeing this"** on every card: the definition and its version, the query
       that found it, the ICP criterion that lifted it, the score breakdown as a short list, the
       jurisdiction and what that means for the approach. Trust is a feature — and this is also
       the fastest debugging tool the people supporting this will ever get.
6. [x] **Full-article fetch for the top of the feed** (v1 backlog) — for a signal above a heat
       threshold, fetch the article through the existing `Core\Contracts\WebContentFetcher`
       (already used by Enrichment and Business Intelligence — reuse it, do not add an HTTP
       client) and re-extract amount, names and roles from the full text. Behind
       `signals.article_fetch.enabled`, a per-team monthly micro-unit cap (decision #4), a
       timeout, the circuit breaker, and `robots.txt` respect. Metered onto the run's cost.
       Store nothing but the extracted fields and a fetch flag — **not the article body**;
       retention and DSAR both get harder the moment we keep prose.
7. [x] **Per-definition performance in Reporting** — signals → kept → converted → **pipeline
       stage reached**, per definition, over the window, with the **cohort rule written down**
       the way every other Reporting figure is (`docs/modules/reporting-analytics.md`) and its
       bounds reaching the query in **UTC** (`DateRange::constrain()`). This is the number that
       tells a team which signals are worth paying for, and it is what makes S15's retirement
       suggestion credible rather than opinionated.
8. [x] **UI** — the sort control; the heat indicator (a **status** token or a neutral scale,
       **never Signal Orange** — §2a); the explanation disclosure; the tuning-suggestions panel
       on the settings screen. `brand-reviewer`.

**Data model:** `signals.heat`, `.score_breakdown`, `.article_fetched_at`;
`signal_source_stats`; `signal_tuning_suggestions`.
**Tests:** the scorer is deterministic and each component moves the total in the right
direction; recency decay reorders two otherwise identical signals; source stats update on
dismiss and convert and never cross teams; the advisor proposes and never applies; accepting a
suggestion writes an audited definition edit; article fetch respects the cap, the breaker and
the flag and stores no body; the Reporting figure is UTC-bounded and matches a hand-counted
fixture.
**Acceptance:** the feed's first ten cards are defensibly its best ten; every card can explain
itself; a team can see which of their definitions produced pipeline.
**Dispatch prompt:** _"Execute Phase S12 of signals-v2-plan.md. Read
docs/modules/reporting-analytics.md for the window and cohort rules. Add SignalScorer with its
stored breakdown, signal_source_stats, the SignalTuningAdvisor (propose, never apply), the
why-am-I-seeing-this panel, gated full-article fetch via Core's WebContentFetcher, and
per-definition conversion reporting. Run laravel-module-reviewer, brand-reviewer and
phase-complete."_
**Risks:** a scorer nobody understands is worse than date order. Store the breakdown, show it,
and keep the weights in config so they can be argued about with evidence.

---

## Phase S13 — Pluggable signal sources

**Goal:** A definition stops meaning "a Serper news query" and starts meaning "a thing to watch
for, wherever it is published" — news, registries, job boards, plain web search, a team's own
RSS feed, and an inbound webhook.
**Depends on:** S7, S8.
**Modules & docs:** Signals; LeadSourcing (the registry pattern to mirror); Core;
`docs/decisions/0020-*`; `docs/modules/signals.md`.

### Tasks

1. [ ] **ADR 0020 — "Signal sources are a registry, and an inbound event is untrusted"**:
       mirror `LeadSourceProvider` / `LeadSourceProviderRegistry` deliberately — same shape,
       same hardening, an `AbstractHttpSignalSourceProvider` in the lineage of
       `AbstractHttpLeadSourceProvider` (timeouts, retry on 429/5xx, the shared
       `Core\Support\CircuitBreaker` with its own prefix, fail-fast on a missing key). State the
       trust model for inbound events in the same ADR: a webhook payload is **data from
       outside**, so it is signed, replay-protected, bounded, rate-limited, wrapped in
       `UntrustedContent` before it reaches the AI, and can never set a score, a jurisdiction or
       a lead id directly.
2. [ ] **`Contracts\SignalSourceProvider`** — `key()`, `supports(SignalDefinition)`,
       `fetch(SignalSourceQuery): list<SourceItem>` (rename the DTO: `NewsItem` is now a lie),
       `estimatedCostMicros()`. `SignalSourceRegistry` resolves by key. `NewsSearchClient`
       becomes one implementation, unchanged in behaviour.
       `signal_definitions.source_keys` (json) says which sources a definition runs on, default
       `['news']`, so every S7–S12 definition keeps working untouched.
3. [ ] **`WebSearchSignalSource`** — Serper `/search` rather than `/news`, for events that never
       reach a news desk (planning registers, award announcements, company blog posts). A
       different noise profile, so its own ignore defaults and its own spike table.
4. [ ] **`JobPostingSignalSource`** — hiring-surge, new-office and new-function signals from
       job-board search. The single best source for the recruitment and B2B SaaS packs, and
       cheap. **Spike it before building it**; if the result is poor, say so in the doc and drop
       it rather than shipping a pack that finds nothing.
5. [ ] **`CompaniesHouseSignalSource` (UK)** — the v1 backlog's headline item: PSC changes,
       officer appointments and resignations, accounts overdue, insolvency and administration
       filings. **Reuse the existing `CompaniesHouseProvider` credentials and hardening**; do
       not open a second integration. Poll filings for a watchlist (S10) or by SIC code + region
       for a topical watch. A registry filing is **structured** — it needs no AI classification
       at all, which makes it both the cheapest and the highest-confidence source in the product.
       `RunSignalWatchAction` must therefore let a source return items that **skip the AI batch**
       and carry their own confidence. Say so in the ADR; it is a real shape change in the run.
6. [ ] **`FeedSignalSource` (RSS/Atom)** — a team pastes a feed URL (an industry newsletter, a
       regulator's filings feed, a portal's press releases) and events are classified out of it
       by the same prompt. SSRF-safe URL validation (reuse `Core\Support\Security` from Phase
       20), conditional GET with ETag, a size cap, a poll-interval floor, and a per-team feed
       count cap.
7. [ ] **Inbound webhook source** (decision #2 — last, behind `Capability::ApiAccess`):
       `POST /api/v1/signal-events` with a per-definition secret, an HMAC signature, a timestamp
       window, an idempotency key, a strict bounded schema and its own rate limit. The payload
       becomes a signal with `source_kind = webhook`, classified and scored like any other, and
       **never trusted** to set its own scores. This is what lets a customer wire their own data
       in, and it is the difference between a feature and a platform.
8. [ ] **Per-source cost and health** — each source reports its own cost micros onto the run;
       `signal_runs` grows a `source_breakdown` json. The admin `/admin/signal-runs` screen
       (Phase 19c) shows cost by source, and a source with an open circuit breaker is visible
       there — provider health is an operations question, and `/admin` is where operations
       already look.
9. [ ] **UI** — the definition form picks sources with their cost and "what this is good for";
       the card names the source kind; the feed can filter by it. API + `openapi.json` + docs.

**Data model:** `signal_definitions.source_keys`, `.source_config` (json, per-source settings
such as a feed URL or a SIC-code list); `signal_runs.source_breakdown`;
`signal_feed_subscriptions`; `signal_webhook_secrets`.
**Tests:** the registry resolves and refuses an unknown key; each source's client is tested
against recorded fixtures with the hardening cases (timeout, 429, 5xx, malformed 200, open
breaker); a Companies House filing becomes a signal with **no** AI call; a feed URL pointing at
a private address is refused (SSRF); a webhook with a bad signature, a stale timestamp or a
replayed idempotency key is refused through the standard envelope; a webhook payload cannot set
`heat`, `jurisdiction` or `lead_id`; per-source cost lands on the run.
**Acceptance:** a UK team watches Companies House and a Dubai team watches news from the same
screen, with one definition shape; a customer can push their own events in.
**Dispatch prompt:** _"Execute Phase S13 of signals-v2-plan.md. Write ADR 0020 first. Mirror
LeadSourceProviderRegistry to build SignalSourceRegistry, implement the news, web-search,
job-posting, Companies House (reusing the existing provider's credentials, no AI batch), RSS and
inbound-webhook sources with the ADR's trust model, and add per-source cost to signal_runs and
the admin screen. Run security-review on the webhook and feed paths, then
laravel-module-reviewer, brand-reviewer and phase-complete."_
**Risks:** this is the widest phase. If it needs splitting, ship **S13a** (registry +
web-search + job postings) and **S13b** (Companies House + RSS + webhook) — the registry is the
part everything else depends on.

---

## Phase S14 — Playbooks: a signal that does something

**Goal:** A signal arriving becomes an event the team can automate against — route it, stage it,
assign it, draft the approach — without a human triaging a feed every morning.
**Depends on:** S7, S10, S12 (a rule wants a score to threshold on).
**Modules & docs:** Signals; Outreach; LeadsWorkspace; Notifications; `docs/compliance.md`;
`docs/modules/outreach.md`; `docs/modules/signals.md`.

### The rule that governs this phase

**A playbook can prepare an approach. It cannot send one.** `OutreachGuard` answers whether a
prospect may be approached, and `docs/compliance.md` §3.2–3.3 makes suppression, sender
identification and the opt-out conditions of sending. A UAE-jurisdiction signal carries
`UaePriorConsent`, which nothing published can satisfy. So a playbook's outreach action
**drafts and queues for human review**, always — and that is a decision recorded in the module
doc, not an omission someone later "fixes".

### Tasks

1. [x] **Migration `signal_playbooks`** — team-scoped: name, `is_active`, `conditions` (json:
       definitions, sources, jurisdictions, min heat, amount band, watchlist membership,
       matched-lead-or-not), `actions` (json, ordered), `stop_after_first_match` (bool),
       priority. Plus `signal_playbook_runs` (which playbook fired on which signal, what it did,
       when, and what it **refused** to do and why) — an automation with no audit trail is an
       automation nobody trusts.
2. [x] **`SignalPlaybookEvaluator`** — deterministic, no AI, pure, fully unit-tested against a
       table of condition fixtures. Evaluated on the existing `SignalsDetected` /
       `SignalConverted` events via a listener, **after commit**.
3. [x] **Actions, each its own class, each calling the owning module's Action — never
       reimplementing it:** - `AssignToMember` (round-robin or named, via LeadsWorkspace's assignment Action). - `ConvertToLead` / `AttachToMatchedLead` (the S4 / S10 Actions). - `MoveToPipelineStage` (LeadsWorkspace's Action; a team-scoped stage id or nothing). - `AddTag`. - `Notify` (`NotificationDispatcher` only, aggregated, never a bare `notify()`). - `DraftOutreach` — a draft through Outreach's existing drafting path, **queued for
       review**, and **refused outright** when `OutreachGuard` / `LeadRestrictionResolver`
       says this prospect may not be approached. The refusal is recorded on the playbook run
       with its reason and shown in the UI. Signals reaches this through Outreach's
       **contract**, never by importing `App\Modules\Outreach` — the three-contracts rule in
       `CLAUDE.md` §3 cuts both ways. - `CreateTask`.
4. [x] **Idempotency and blast radius** — a playbook fires **once** per signal (unique
       `(signal_id, playbook_id)`), caps actions per run, and has a **dry-run mode** that
       records what it _would_ have done for a week before anyone switches it on. A rule that can
       convert every signal into a lead and draft a message for each is a rule that needs a
       rehearsal.
5. [x] **UI** — a rule builder in the same visual language as the definition builder; a
       playbook-run log with the refusals visible; and a "what this would have done to last
       week's signals" preview, which is the S9 preview idea applied to automation and is what
       makes someone brave enough to switch it on.
6. [x] **Mobile** — the playbook _inbox_ (what fired, what needs review) lands in `/mobile` per
       decision #5; building playbooks stays on the web. Read `docs/modules/mobile-app.md` and
       share types via `@shared`, **never by copy**.
7. [x] **API + docs** — playbook CRUD + run log, `openapi.json`, and `docs/compliance.md`
       updated with the drafts-never-sends rule.

**Data model:** `signal_playbooks`, `signal_playbook_runs`.
**Tests:** every condition type matches and fails correctly; a playbook fires once per signal
across two runs; `DraftOutreach` is refused for a `UaePriorConsent` signal and the refusal is
recorded; no action calls a module's internals instead of its Action; dry-run writes run rows
and changes nothing else; action caps hold; cross-tenant stage and member ids are refused.
**Acceptance:** a team configures "high-heat acquisition in my pipeline → assign to the owner,
move to Engaged, draft an intro for review, notify them" and it runs unattended, with a log that
explains every decision including the ones it declined.
**Dispatch prompt:** _"Execute Phase S14 of signals-v2-plan.md. Read docs/compliance.md §3 and
docs/modules/outreach.md. Add signal_playbooks with a deterministic evaluator and action classes
that call each owning module's Action through its contract, the once-per-signal guarantee, the
dry-run mode, the run log with recorded refusals, the rule-builder UI and the mobile playbook
inbox. DraftOutreach must draft for review and be refused by OutreachGuard where the
jurisdiction requires it. Run laravel-module-reviewer, brand-reviewer and phase-complete."_
**Risks:** an automation that mass-converts or mass-drafts. Dry-run, per-run caps, the
once-per-signal unique and the guard refusal are four independent brakes; keep all four.

---

## Phase S15 — Delivery, ROI and the curated library

**Goal:** Get signals to people where they already are, show which signals are worth their cost,
and turn the preset library into a curated asset rather than a seed file.
**Depends on:** S9 (library), S12 (per-definition performance).
**Modules & docs:** Signals; Notifications; Reporting; Admin; `docs/modules/admin.md`;
`docs/runbooks/admin-panel.md`; ADR 0016.

### Tasks

1. [ ] **Signal digest** (v1 backlog) — daily or weekly, per member, honouring
       `NotificationPreferences` and `DigestFrequency`, built by extending
       `Notifications\Services\DigestBuilder` rather than adding a second digest path. Top
       signals by heat, clustered, with the jurisdiction's approach note. Mail + push (the mobile
       app has FCM wired). Aggregated — `AnnounceTopLeadsJob` is the pattern — and the stored
       payload stays keys-and-params, never prose.
2. [ ] **Definition ROI, where a definition is managed** — the S12 figures on the definition
       card: signals, kept rate, converted, pipeline reached, **cost**, and a cost per converted
       lead. A definition with 400 signals, 3 kept and 0 converted over 60 days is the product
       telling the truth about itself, which is the only way a team learns to trust the ones that
       do work.
3. [ ] **Retirement suggestions** — a definition below a kept-rate floor over a full window gets
       a _pause or retune?_ card with its numbers. Propose, never apply (the S12 rule).
4. [ ] **Admin library curation** (decision #3) — in `/admin`, under
       `AdminAbility::ManageOperations`: CRUD over platform presets and packs; a **submission
       queue** of team-authored definitions a team offered to the library (author team
       anonymised, with that definition's cross-tenant performance); promote/reject with a note;
       and preset **versioning**, so a promoted change can be offered to the teams who cloned it.
       All behind `PlatformContext` and the admin panel's own four controls;
       `AdminRouteContractTest` is the gate. The panel **owns no business rule** here — it calls
       Signals' own Actions (`docs/modules/admin.md`).
5. [ ] **"Your preset has moved on"** — a team whose clone's `preset_version` is behind sees a
       diff and can take the change, per field. No silent updates; the S7 clone-not-reference
       decision exists precisely so this is a choice.
6. [ ] **Platform signal reporting** — adoption per preset, per pack and per source, plus
       cross-tenant kept/converted rates, in **Admin's own builders over
       `platform_daily_metrics`** (ADR 0016): platform reporting is not tenant reporting with the
       scope removed, and the nightly rollup must stay scheduled **before** the nightly sweeps.
       This is the data that tells the business which packs to invest in.
7. [ ] **Marketing-grade empty states and onboarding** — a team with no definitions sees the
       library with its packs and the composer, not an empty list; a team with a market set sees
       that market's pack first. The Onboarding nudge pattern applies
       (`memory/onboarding-not-hard-gated.md`): nudge, never hard-gate.

**Data model:** `signal_definition_submissions`; `signal_definitions.preset_version` (from S7);
`platform_daily_metrics` gains signal columns.
**Tests:** the digest respects preferences and off, aggregates, and carries no prose in its
payload; a member who left the team is dropped; ROI figures match a hand-counted fixture and are
UTC-bounded; promotion creates a preset and bumps its version without touching any clone; the
diff offer is per-field; admin routes pass the contract test; platform figures are built by
platform builders.
**Acceptance:** a team gets a Monday digest of last week's best signals; an owner can see which
definitions pay for themselves; the library grows by curation rather than by deploy.
**Dispatch prompt:** _"Execute Phase S15 of signals-v2-plan.md. Read docs/modules/notifications.md,
docs/modules/reporting-analytics.md, docs/modules/admin.md and ADR 0016. Extend DigestBuilder for
the signal digest, surface per-definition ROI and retirement suggestions, build the admin preset
library with the submission queue and preset versioning, add platform signal reporting over
platform_daily_metrics with its own builders, and the library-first empty states. Run
laravel-module-reviewer, brand-reviewer and phase-complete."_
**Risks:** a digest that becomes noise. Default to weekly, cap the items, and make the
unsubscribe path the same one every other notification uses.

---

## Phase S16 — Cost governance, plan limits, retention, compliance and close-out

**Goal:** Make everything S7–S15 added safe to sell: a team cannot spend unbounded money, a plan
means something, the new tables obey retention and DSAR, and the documentation tells the truth.
**Depends on:** whichever of S7–S15 shipped.
**Modules & docs:** Signals; Billing; Compliance; Admin; `docs/compliance.md`;
`docs/modules/billing-plans.md`; `docs/modules/signals.md`; `mvp.md`; `CHANGELOG.md`.

### Tasks

1. [ ] **`Signals\Contracts\SignalQuotaSource`** with a permissive `StubSignalQuotaSource` as
       the module default, and **Billing binding the real one over it** — the exact pattern
       `CreditSource` / `LeadQuotaSource` / `SeatLimitSource` already follow, with Billing
       registered last, so no module imports `App\Modules\Billing` (`CLAUDE.md` §3). Limits:
       `max_definitions`, `max_watches` (move the S7 config floor here),
       `max_watchlist_entries`, `monthly_source_queries`, `monthly_preview_queries`,
       `article_fetch_micros`, `playbooks_enabled`, `webhook_source_enabled`.
2. [ ] **Enforcement in the guards, not in middleware** (ADR 0012) — a `SignalSpendGuard` asked
       by `RunSignalWatchAction`, `TriggerSignalWatchAction`, `PreviewSignalDefinitionAction`,
       the sync job and every source provider. A scheduled watch and a queued job have no request
       behind them, so a middleware limit would miss exactly the spend that matters. A team at
       its ceiling gets a **clean refusal recorded on the run**, not a half-run.
3. [ ] **`usage_meters` gains signal measures as a record over the ledgers** — never a counter
       the app increments (ADR 0012). `signal_runs`, `signal_previews` and the per-source
       breakdown are the ledgers; the meter is derived from them.
4. [ ] **Plan definitions** in `config/billing.php` for every tier, plus
       `Capability::SignalComposer` and any other new capability wired into the one
       `CapabilityGate`, with its enforcement point documented in the enum (the existing rule: a
       capability that is not actually enforced does not get a case).
5. [ ] **Retention for every new table** — extend `SignalRetention` and `signals:prune`:
       `signal_previews` (30 days), `signal_rejections` (30 days), `signal_runs` (longer; it is
       cost history), `signal_playbook_runs` (180 days), `signal_source_stats` (rolling),
       `signal_watchlist_entries` (with their lead), `signal_clusters` (with their last member).
       Chunked, idempotent, counts-only logging, `--dry-run`, and the
       **re-apply-the-guards-at-delete-time** rule from the S6 fix (read ids then delete by id
       alone is the bug that already bit once).
6. [ ] **DSAR and erasure across the new surface** — `SignalsPersonalDataExporter` must find a
       data subject in `signal_watchlist_entries` and in clustered signals, and erasure must
       clear them. Mind the `LIKE ... ESCAPE` lesson already recorded in the module doc, and
       **test against MySQL**, not only SQLite (`memory/sqlite-tests-mysql-prod.md`).
7. [ ] **`security:posture` and the CSP** — a new screen needing an inline script takes
       `Vite::cspNonce()`; a new third-party host goes in `SECURITY_CSP_EXTRA_*`, never into a
       loosened directive (ADR 0017). New expected refusals go in `sentry.ignore_exceptions`
       **with a comment** — and never the base `DomainException`.
8. [ ] **API v1 final sweep** — every new route named, `auth:sanctum`, scoped with an `ability:`
       from `TokenAbility`, present in `openapi.json`, paginated via `PaginatedIndexRequest`,
       errors through `Core\Http\Responses\ApiError` only. `RouteContractTest` and
       `AdminRouteContractTest` are the gates. Bump the API version **only if something broke**
       (ADR 0013); document the additions in `docs/api/README.md`.
9. [ ] **Docs, properly** — rewrite `docs/modules/signals.md` for v2 (purpose, code map, data
       model ER diagram, key flows, the new limitations, a Changelog row per phase); update
       `docs/architecture.md`, `docs/compliance.md`, `docs/runbooks/signals-demo.md` (a v2 demo
       script: compose → preview → watchlist → cluster → playbook), `CHANGELOG.md`,
       `signals-plan.md` (point its backlog at this file and tick off what landed), `mvp.md`, and
       `CLAUDE.md` §3/§5 with ADRs 0018–0020. Strike every "Known limitations" entry this plan
       closed, and be honest about the ones it did not.
10. [ ] **Final reviews** — `laravel-module-reviewer` over the whole module, `security-review`
        over the webhook, feed, composer and preview paths, `brand-reviewer` over every new
        screen, and `/code-review` at a high effort level across the branch. Fix the findings,
        and record what was accepted and why, the way ADR 0017 and
        `docs/runbooks/observability.md` already do.

**Data model:** no new tables; retention and quota wiring only.
**Tests:** a team at each ceiling is refused cleanly and the run records it; the quota stub is
permissive and Billing's binding wins; `usage_meters` is derived and matches the ledgers; prune
deletes only eligible rows in every new table and re-applies its guards at delete time; DSAR
finds and erases a subject across watchlists and clusters **on MySQL**; `RouteContractTest`,
`AdminRouteContractTest` and `security:posture` green.
**Acceptance:** every gate green; every doc true; a plan limit actually bites; nothing in the
feature can spend unbounded money.
**Dispatch prompt:** _"Execute Phase S16 of signals-v2-plan.md. Read ADR 0012, ADR 0013,
ADR 0015, ADR 0017, docs/modules/billing-plans.md and docs/compliance.md. Add SignalQuotaSource
with its permissive stub and Billing's binding, enforce every limit in a SignalSpendGuard called
from the Actions and jobs, derive the usage meters from the ledgers, extend SignalRetention and
signals:prune across every new table, close the DSAR surface and test it on MySQL, complete the
API v1 sweep and regenerate openapi.json, then rewrite docs/modules/signals.md for v2 and update
every other doc. Finish with security-review, laravel-module-reviewer, brand-reviewer,
/code-review high and phase-complete."_
**Risks:** this is the phase that gets skipped when the demo already works. It is also the phase
that stops a team running a £900 Serper bill on a Tuesday. Do not skip it.

---

## Status

| Phase | Title                                           | Status                | Depends on   |
| ----- | ----------------------------------------------- | --------------------- | ------------ |
| S7    | Signal definitions become data                  | ☑ **Done** 2026-11-02 | —            |
| S8    | Geography, and the jurisdiction bug             | ☑ **Done** 2026-11-02 | S7           |
| S9    | The signal builder (library, composer, preview) | ☑ **Done** 2026-11-02 | S7, S8       |
| S10   | Watchlists & account monitoring                 | ☑ **Done** 2026-11-02 | S7, S8       |
| S11   | Clustering & redirect-safe dedupe               | ☑ **Done** 2026-11-03 | S7           |
| S12   | Ranking, learning & explainability              | ☑ **Done** 2026-11-03 | S7, S11      |
| S13a  | Pluggable sources: registry, web search         | ☑ **Done** 2026-11-03 | S7, S8       |
| S13b  | Companies House, RSS feeds, inbound webhook     | ☑ **Done** 2026-11-03 | S13a         |
| S14   | Playbooks                                       | ☑ **Done** 2026-11-03 | S7, S10, S12 |
| S15   | Delivery, ROI & curated library                 | ☐ Not started         | S9, S12      |
| S16   | Cost governance, limits, retention & close-out  | ☐ Not started         | S7–S15       |

**Minimum viable v2:** S7 + S8 + S9 + S16 — the ask, made safe to sell.
**Recommended first release:** S7–S11 + S16 — definitions, geography, the builder, account
monitoring and clustering, which together change what the feature _is_. S12–S15 then compound
on it.

## S14, as shipped — one addition the plan did not ask for, four decisions it left open, and a

review that found three product bugs

**Read `docs/modules/signals.md` → "What the S14 reviews changed" first.** The reviews found three
things that were wrong rather than merely rough, and all three were in the parts the plan was most
confident about:

- A rule **converted a signal a member had dismissed**, and drafted an approach for it. The plan's
  four brakes were all about volume; none of them was about a human having already said no.
- A per-job spend cap **claimed the signal and refused it permanently**, so with the plan's own
  numbers 175 of every 200 signals were refused for ever by design — and the batch cap the plan
  asked for by name dropped its tail entirely. Both are now deferrals.
- An exception in the listener **failed the watch run that raised the event**, and the retry
  re-billed its Serper search. The plan said to evaluate on the existing events and did not say that
  a listener on an after-commit event is inside the caller's job.

The ADR gained §§4a–4c for the three. Everything below was written against the phase as first
built and still holds.

## S14, as shipped — one addition the plan did not ask for, and four decisions it left open

Every task landed as written. What follows is what the plan did not say.

### The addition: ADR 0024

The plan said the drafts-never-sends rule was "a decision recorded in the module doc, not an
omission someone later 'fixes'", and it is — `docs/modules/signals.md` and `docs/compliance.md`
§3.12 both carry it. It is **also** ADR 0024, which the plan's ADR table did not anticipate.

The reason for the extra file: the rule crosses two modules and introduces a contract seam, which is
exactly the shape of thing `CLAUDE.md` §5's list exists to make findable. Somebody asking "why can't
a playbook send?" six months from now will look in `docs/decisions/` before they look in a module
doc, and the answer needs to be where they look. The ADR carries the four brakes and the
handler-calls-the-Action rule with it, for the same reason.

### Four decisions the plan left to the implementation

**1. The run row is claimed before any step executes, not written after.** The plan asked for a
unique on `(signal_id, playbook_id)` and a log of what happened; it did not say which came first,
and the order is the whole guarantee. Executing and then inserting would have the loser of a race
discover its duplicate _after_ creating a second task and spending a second draft. So the insert is
the lock, a caught `UniqueConstraintViolationException` is the guarantee working rather than an
error, and the row is filled in afterwards. The cost is that a crash in between leaves a claimed row
with no steps — which the log shows as "started and did not finish" rather than hiding, because a
silent gap in an audit trail is worse than a visible one.

**2. An unattended rule acts as the member who built it.** The plan did not say whose authority a
playbook acts under, and every module Action it calls wants a `User`: the assignment writes who
assigned, the stage move writes who moved it, the audit log writes an actor. A synthetic system user
was considered and rejected — every entry it wrote would be unattributable, which is the opposite of
what an audit trail is for, and it would need a row in `users` that can sign in to nothing. So
`PlaybookActor` resolves the creator while they are still on the team, then the team's owner, then
**records a refusal on every step** rather than running as nobody.

**3. Rehearsal is the default mode, and it is the only safety default in the phase.** The plan asked
for a dry-run mode and a UI that encourages a week of it; it did not say what a rule created without
one should be. `SignalPlaybookData` defaults to `DryRun`, which matters mostly for the API: a caller
who did not think about `mode` should get a rule that watches. The factory defaults to **live**, for
the opposite reason — a test that built a rule and asserted nothing happened would otherwise pass
for the wrong reason.

**4. The preview takes conditions, not a saved playbook, and has no budget.** S9's preview needed a
ledger row and three caps because it spent money on a provider. This one evaluates rows the team has
already paid for and executes nothing, so it has a **window** (`preview_days`) instead of a budget,
no ledger, no throttle, and a policy that lets a **read-only member** run it — reviewing a
colleague's rule is exactly the question the most cautious person on the team wants answered.
Taking conditions rather than an id is what lets somebody preview a rule before it exists, which is
when they actually want the answer.

### Two things worth knowing that are not in either doc

**The per-job spend counters live on `PlaybookBudget`, not on the handlers**, and that is not
cosmetic: the handlers are container singletons that outlive one job, so a counter on one would
carry last job's total into this one and the second batch of the morning would be refused
immediately. The budget is constructed per job precisely so it cannot. A rehearsal keeps its own
pair of counters for the same reason it does not consume the live ones — it has to be able to say
"this is where a live rule would have stopped" without stopping there itself.

**A notify step sends nothing at the moment it runs.** It records an intent and the job dispatches
once everything is through, which is `CLAUDE.md` §3's aggregation rule applied at the only point it
could be: a rule matching twelve of a morning's signals is twelve step executions and must be one
message. Doing it in the handler would have made the rule unusable within a week.

## S13b, as shipped — three deviations and a design the plan did not ask for

**1. The topical half of task 5 does not exist, and the spike is why.** The task asked for filings
polled "for a watchlist (S10) **or by SIC code + region for a topical watch**". There is no
cross-company filings endpoint — `/filing-history` and `/search/filings` both 404 — so filings can
only be polled per company, by number. `/advanced-search/companies` _can_ answer incorporations and
dissolutions, and measured **2,422 new London software companies in one month for one SIC code**,
which is a list rather than a trigger event about a prospect; its default ordering is by company
number, so a capped version would have the cap rather than the relevance decide what a team saw. So
Companies House is **entity-scoped**, enforced by the builder having nothing to address rather than
by a flag, and a topical watch gets no registry queries. The table is in `docs/modules/signals.md`.

**2. The webhook stores rather than processes, which is not how task 7 reads.** The task describes an
endpoint whose "payload becomes a signal … classified and scored like any other". It does — but not
synchronously. `POST /api/v1/signal-events` accepts and stores a `signal_inbound_events` row, and
`WebhookSignalSource` reads it back on the next run of a watch like any other source. Two reasons,
and the first is the one that matters: ADR 0023 §7's promise that "the customer supplies an
observation; Alima still decides what it means" is only _true_ if the deciding happens in the code
that decides for every other source, and a separate ingestion path would have been a second place
for thresholds, clustering, scoring and jurisdiction to drift — in the one place a caller's
assertions are most tempting to believe. The second is cost: a customer's integration retries in a
loop, and classifying at acceptance would make a retry storm an AI bill. The price is latency and
one silent state — an event pushed to a type nobody watches is stored, swept, and never classified —
both recorded under Known limitations.

It also meant the phase did **not** need `RunSignalWatchAction` opened up to share its clustering and
scoring, which was the riskiest thing on the plan.

**3. The secret is encrypted, not hashed — a correction made mid-build.** The first design hashed it,
which cannot work: a signature is an HMAC over the raw body, so verifying one means _recomputing_ it,
which needs the secret itself. A hash can check a secret somebody presents; it cannot check a
signature they derived from one. The alternatives were both worse — ask the caller to send the secret
on every request (throwing away the body integrity and replay binding the HMAC exists for) or not
verify at all. It is stored under Laravel's `encrypted` cast and the docblock says plainly that this
is the protection available for a shared secret the verifier must use, rather than implying a one-way
hash it is not.

**4. The builder grew a third addressing shape, which the plan did not anticipate.** A query-based
source takes a string and multiplies out by place, region and template. The registry takes a company
number — one query per account, no multiplication. A feed and the webhook take what is on the
definition — one query each. `SignalSourceKind::addressesSubject()` is the discriminator, and
building the third shape is what actually made the **feed** reachable: it was registered and tested
in isolation at the previous commit and never asked for a query, because the entity path required a
company number and the topical path only emitted query-based kinds.

**What the tests found, and it is the S12 lesson repeating.** Three bugs, all in code shared beyond
this phase, each surfaced by a test written for a _new_ behaviour rather than by review:

- `SafeUrl::normalise()` prepended `https://` to anything without an http prefix, so
  `file:///etc/passwd` became `https://file:///etc/passwd` — scheme `https`, host `file`,
  unresolvable, allowed through. Inherited from `HttpWebContentFetcher`, where only website fields
  reached it; a customer-supplied feed URL walked straight past the guard.
- `retryWhen()` was typed `Throwable`, but Laravel gates that callback on `Response::successful()`
  rather than `failed()` and hands it `$response->toException()` — which is `null` for a 3xx. A
  latent `TypeError` that no source could reach until one used HTTP caching properly and got a 304.
- The registry resolver cached a transport failure as "not on the register", permanently. Its
  outcome is now three-valued, like `WebContentFetcher::fetchLines()` and for the same reason: a
  caller enforcing a rule has to tell "there is no such thing" from "we could not find out".

**S13 is now complete, and `SignalSourceKind` has no declared-but-unoffered case left** — all five
have a provider. `isAvailable()` stays, because it is the mechanism that lets the next source be
declared before it is wired.

**What the reviews then changed, and the one lesson in it.** Four findings would have shipped
something broken and one of them completely — the webhook source could not read a single stored
event, a `CarbonImmutable`/`Carbon` sibling mismatch no test reached and PHPStan cannot see. The
three structural ones are a single omission, and it is the omission a plan that adds sources to an
existing hardening layer should expect: **ADR 0023 §4's two meanings of a 4xx were two questions
about us**, written when every source was addressed by a string we had composed. A source addressed
by a subject or by a definition has a third — the addressee is not there — and without it a
struck-off company number failed a watch on every run for ever, and a publisher moving a feed failed
a run whose news queries had all worked. Hence §4a and a `skipped` column.

The rest were the new sources inheriting assumptions that fitted the old ones: a byte cap that was
never streamed, a feed cache keyed by URL in a multi-tenant product, a per-run event cap spent on
events already dealt with, and a per-run **query** budget that counted the entity queries and not
the definition-addressed ones. Two more were found by writing the tests the review asked for rather
than by either review, both the same conflation of `! isQueryBased()` with "addresses a subject".

The lesson for the phases after this one: **a source is not finished when it is registered and unit
tested.** Every one of these lived in the seam between the new source and code that already existed, and the
tests that found them were run-level and cross-tenant rather than per-provider. S14's plan should
budget for that seam explicitly.

`docs/modules/signals.md`, "What the S13b reviews changed", has the full list and the five findings
accepted rather than fixed.

## S13a, as shipped — the phase was split, and four deviations

**The phase was split, with the plan's own blessing.** S13 asked for nine tasks: a registry, five
sources, an inbound webhook, per-source cost and health, and the UI for all of it. Its risk note
offered the split — _"ship **S13a** (registry + web-search + job postings) and **S13b** (Companies
House + RSS + webhook) — the registry is the part everything else depends on"_ — and that is what
happened. **ADR 0023 was written whole**, including the trust model for inbound events and the
AI-skipping shape change a registry filing needs, so S13b implements decisions rather than taking
them.

**1. `JobPostingSignalSource` was dropped, which the task authorised and the spike required.** Task 4
said to spike it first and _"if the result is poor, say so in the doc and drop it rather than
shipping a pack that finds nothing"_. It is poor for three structural reasons, and the third is not
about this week: `site:` queries are refused outright on this account tier so no board can be
targeted; half the results are board **index pages** ("11 Operations manager jobs in London"); and a
job advert **is not a trigger event** — the events the plan wanted (a hiring surge, a new office, a
new function) are _patterns over_ postings, which require counting postings per company over time and
which a search-and-classify source cannot compute. The signal it wanted is already shipping as
`rec_hiring_surge` on `/news`. The table and the reasoning are in `docs/modules/signals.md` so
nobody spikes it a second time.

**2. The spike found a defect the plan did not know about, and fixing it is ADR 0023 §4.** Serper
answers a `site:` or quoted-phrase query with `HTTP 400 "Query pattern not allowed for free
accounts."`. That is a 4xx, so it correctly never tripped the breaker — but the old client raised
`newsProviderFailed()` for _any_ failed response and **that exception is retryable**. So one template
a member had typed with a quotation mark in it failed their whole watch, was retried three times
(re-paying for every other query in the plan each time), and reported "We couldn't reach the news
service": untrue, and unfixable by them. A refused _pattern_ is now a non-retryable
`sourceQueryRejected()` caught **per query**, counted under `rejected` on
`signal_runs.source_breakdown`, with the rest of the plan proceeding and the breaker untouched. A run
whose every query was refused **completes having found nothing**, because nothing is broken.

This also reframes S10's finding. Its table recorded "every quoted query returned zero, twelve of
twelve" and concluded Google serves quoted queries badly with a recency window. The conclusion — an
entity query is a bare name — survives; the stated reason was wrong, and the real one is that the
account tier never accepted the pattern.

**3. Two DTOs were renamed, and a Core-adjacent contract was deleted rather than deprecated.** The
plan named only `NewsItem`; `SignalQuery` had become equally untrue, and the plan's own
`fetch(SignalSourceQuery)` signature named its replacement. `NewsSearchClient` and `SerperNewsClient`
are gone rather than kept as aliases: they are internal to the module, 23 files referenced them, and
a deprecated spelling kept "for safety" is how two names for one concept survive into the next
phase. `NewsSignalSource`'s behaviour is unchanged and `SerperNewsClientTest`'s cases moved with it.

**4. `signals.matched_template` was added, closing an S12 deviation a phase early.** S12 recorded
that its explanation panel could not name the query that found a signal, because no row stored it,
and said the column was "worth doing in S13, where a signal will also have to record which _source_
found it". Both columns land in the same migration, and the panel now names the template.

**What this leaves for S13b.** `SignalSourceKind` declares `companies_house`, `feed` and `webhook`
and `isAvailable()` returns false for all three, so the form does not offer them and a definition
cannot be saved against one. `SourceItem::$classification` and `isClassified()` exist with every
source returning null, which is the seam a registry filing uses to skip the AI batch (ADR 0023 §6) —
so S13b adds a source rather than reshaping a run. `signal_definitions.source_config` is the column a
feed URL and a SIC-code list go in, and a provider is already handed only its own slice of it.

## S7, as shipped — two deviations from the plan above

Both are recorded in ADR 0018 and both were taken for a concrete, repo-specific reason:

1. **A watch reaches its types through a pivot (`signal_watch_definition`), not a
   `signal_definition_ids` JSON column.** The delete path has to answer "which watches use this
   definition?", and over a JSON column that is `JSON_CONTAINS` on MySQL and `json_each` on
   SQLite — two different queries, only one of which this suite would ever run, which is exactly
   the class of difference `memory/sqlite-tests-mysql-prod.md` exists for. Over a pivot it is one
   join on both. The plan's second column, `signal_watches.event_keys`, went with it:
   `SaveSignalWatchAction` already records the keys in the audit row, and a second copy on the row
   is a copy that can go stale.
2. **`signal_definitions.key` is 32 characters, not 64**, so `signals.event_type` could be
   **renamed** to `event_key` and nothing else. Widening it would have meant a table rebuild on
   SQLite to enforce a width only MySQL checks, and the two columns have to agree because one is
   copied into the other. A key is a slug; the 120-character `name` is what anybody reads.

Three things the plan did not anticipate, all found by running the migrations against the local
MySQL rather than only the test suite — see the module doc's changelog row for the detail: a
**stored** generated column refuses a cascading FK on its base column in MySQL (so `scope_id` is
virtual); Laravel's generated pivot index name is 67 characters against MySQL's 64-character limit
(so both pivot indexes are named); and `Event::fake()` suppresses Eloquent model events, so a
`scope_id` maintained by a model hook was silently wrong across the whole suite (so the database
computes it).

## S11, as shipped — two deviations from the plan above

Both are recorded in `docs/modules/signals.md` and both were taken for a concrete reason.

1. **The amount is not in the subject fingerprint.** The plan put it there to keep two funding
   rounds for one company in one week apart. But an amount is exactly the field outlets disagree
   about — Gulf News writes "$40m", Zawya writes nothing — so including it splits the coverage this
   phase exists to merge, in the common case, to guard against a rare one. Two rounds for one
   company inside a week therefore merge, which is written down as a known limitation with
   `signals.cluster_before_ai` and the similarity knobs behind it; a funding round and an
   acquisition are already kept apart by `event_key`, which is the case the plan's own risk note
   was really about.
2. **A cluster key is looked up against the previous ISO week as well as its own.** A story that
   breaks on a Sunday and is covered on the Monday sits in two ISO weeks, and a week bucket read
   literally would make two cards out of the event most likely to be well covered. So
   `candidateKeysFor()` offers both and only ever _creates_ the first, which also forced the run and
   the backfill to file rows in **publication order** — oldest first — so the join is the same
   whichever order the provider happened to return them in.

Two more things the plan did not anticipate, both found by running it rather than reading it:

- **The similarity measure had to change.** The plan said nothing about which one, and Jaccard —
  the obvious choice — punishes the outlet that wrote the same headline plus "in an all-cash deal"
  for the words the shorter headline did not have room for. **Dice** is symmetric and forgiving
  about length without being blind to it, where the overlap coefficient is not: a three-word
  headline wholly inside a twelve-word one scores a perfect 1 under that, which is how a
  classification ends up attached to the wrong event.
- **The AI Platform's own response cache made two of these tests pass for the wrong reason.** It is
  keyed on the rendered prompt, so re-running a watch over the same article is a cache hit and the
  transport is never reached — which looks identical to the dedupe or the rejection ledger having
  worked. Every test that counts AI calls across two runs now turns
  `ai-platform.cache.enabled` off, and that is the difference between asserting the claim and
  asserting nothing.

**What the review caught, and why it is worth reading before S12.** Five of the seven findings were
one mistake wearing different clothes: **only the feed was taught about clusters.** The digest, the
dashboard KPI, the per-type breakdown, `signals_created` and a matched lead's timeline all still
counted articles, so the phase would have moved the "eight for one event" problem rather than
removed it. The predicate is now one model scope every one of them narrows with. The other two were
sharper: retention taking a cluster's primary hid its surviving members (the predicate is now
written as "nobody live stands in front of me" rather than "am I the primary", which fails toward
too many cards instead of none), and propagating a dismiss across a cluster could flip a **converted**
member — which has no retention window — to dismissed, which has ninety days, scheduling the
deletion of a working lead's provenance. The full list is in `docs/modules/signals.md`, "What the
S11 review changed".

The lesson to carry into S12: **a phase that changes what a row means has to find everything that
counts those rows**, not just the screen it was written for.

**And one defect the phase did not close.** There is no "classifications avoided" figure anywhere,
so the AI saving — the phase's headline number — is visible only in the AI ledger, with the gap
between `results_count` and `signals_created` as the hint. It belongs with S16's cost governance,
where the other spend figures live.

## S12, as shipped — four deviations from the plan above

**1. `signals.heat` is nullable, and the sort has to cope.** The plan said `int`. A plain integer
cannot tell "we judged this and it is cold" from "this was found before the scorer existed", and the
feed's default sort is heat — so every pre-S12 signal would have been pushed to the bottom as though
it had been assessed and found worthless. `heat` is nullable, `SignalSortOrder::apply()` orders nulls
last explicitly (`case when signals.heat is null then 1 else 0 end`, because MySQL and SQLite
disagree about where a null sorts), the card falls back to showing relevance **labelled as
relevance**, and `SignalScore::isMeaningful()` is what a caller asks before presenting a heat as a
judgement. The same distinction, one level down, is why a component's `value` is nullable: a
component with nothing to judge is left out of **both** sides of the average rather than scored zero,
or the heat would measure how much Alima knows about an event instead of how much the event is worth.

**2. The explanation panel does not name the query that found the signal.** The plan asked for "the
definition and its version, the query that found it, the ICP criterion that lifted it, …". The first,
the version, the watch, the breakdown and the jurisdiction are all there. The **query template is
not**, because no signal row records which of a definition's templates matched it — adding that
column was outside this phase and is worth doing in S13, where a signal will also have to record
which _source_ found it. The ICP criterion arrives as the ICP component's evidence rather than as a
field of its own.

**3. "Pipeline stage reached" became "picked up and worked".** The plan's per-definition report asks
for the pipeline stage a converted lead reached. Stages are team-defined rows, so there is no stage
every team has and no ordering that means the same thing across two teams — a column headed
"Qualified" would be empty for half of them. `BuildSignalPerformanceAction` reports leads that have
moved **off the default stage** (`pipeline_stages.is_default = 0`), which is the one comparable
question available: did anybody do anything with it. The cohort rule is written down in
`docs/modules/reporting-analytics.md` like every other figure, and the window reaches the query
through `DateRange::constrain()` in UTC.

**4. The full-article fetch changed a Core contract.** `robots.txt` respect was one clause of task 6
and it needed `Core\Contracts\WebContentFetcher::fetchLines()` — a new method on a seam three
modules share. The reason is in "What the S12 review changed": the existing `fetch()` collapses all
whitespace, which is right for an article and destroys a line-oriented format, so the first
implementation of `RobotsPolicy` parsed every policy as a single record and allowed everything. The
policy itself lives in `Core\Support`, not in Signals, because the next module that fetches a page
will want it and a second copy is a second answer.

**What the review caught, and what to carry into S13.** Nine findings, four of which would have
shipped something broken: a `robots.txt` parser that could not fail a test, a learning ledger whose
arithmetic made its own consumer unreachable, an "accept this suggestion" path that could lower a
floor a member had raised, and an enricher that could fail an already-committed run. Every one was a
**test that passed for the wrong reason** — a double that preserved newlines where the product does
not, fixtures seeded directly into states the ledger cannot produce, an AI response cache that made a
call-counting test pass without the call, and a `catch` on two leaf exception classes. The lesson for
S13, which adds sources that each fetch and parse something: **assert against the real collaborator
wherever the double is the thing being trusted**, and seed state by driving the product rather than
by writing the row you expect.

## New ADRs this plan introduces

| ADR  | Title                                                                                  | Phase  |
| ---- | -------------------------------------------------------------------------------------- | ------ |
| 0018 | Signal definitions are data, not an enum (and the preset library is platform data)     | S7     |
| 0019 | Search geography is not the compliance market; the restriction follows the subject     | S8     |
| 0020 | The signal builder: an AI that writes configuration is drafted, bounded, previewed     | S9     |
| 0021 | Account monitoring: a watch can be pointed at companies instead of places              | S10    |
| 0022 | A ranked feed that can explain itself, and a product that proposes rather than retunes | S12    |
| 0023 | Signal sources are a registry, and an inbound event is untrusted                       | S13a/b |
| 0024 | A playbook prepares an approach; it never sends one                                    | S14    |

S11 introduced no ADR: clustering changed what a row _means_ but took no decision the module doc
does not record. S14's was **not** planned — the phase said its one rule belonged in the module doc,
and it does, but the rule crosses two modules and opens a contract seam, so it is where somebody
would look for it as well (see "S14, as shipped"). The numbers above are the ones actually written — the planned numbering for S9 and
S10 ("none") and for S13 (0020) was overtaken as those phases shipped.

## Deliberately out of scope

- **Paid wealth data** (Altrata / Wealth-X / WealthEngine). Still in the v1 backlog; it is an
  Enrichment provider for converted leads, not a signal source, and it is a commercial decision
  before it is an engineering one.
- **Contact finding.** Signals holds a name, a public role and a URL, and that is a compliance
  position (`docs/compliance.md`), not a gap.
- **Sending outreach from a signal.** S14 drafts; Outreach sends, through its own guard.
- **A second AI provider or a learned ranker.** The scorer is deterministic on purpose; a
  learning-to-rank model is in `mvp.md`'s post-MVP backlog and belongs to Scoring.
- **A visual query language.** The composer plus the advanced form covers both ends; a
  drag-and-drop boolean builder is a month of work for the 2% who would use it.
