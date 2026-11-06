# ADR 0018 — Signal definitions are data, not an enum

- **Status:** Accepted
- **Date:** 2026-11-02
- **Extends:** ADR 0002 (module structure), ADR 0006 (tenancy), ADR 0011 (Signals module), ADR 0016 (platform vs tenant data)
- **Phase:** S7 of `signals-v2-plan.md`

## Context

Signals v1 (ADR 0011) shipped with three event types in a PHP enum —
`SignalEventType::{Acquisition, FundingRound, Relocation}` — chosen for one customer: a
Dubai luxury real-estate agency looking for liquidity events. The enum was the right
call for v1: a closed set, three cases, written down once.

It is the wrong call for a product. A recruiter wants "hiring surge". A wealth manager
wants "IPO filed". A fit-out contractor wants "planning permission granted". None of
them can have it, because adding an event type currently means a code change in nine
files, a new case in the `signals.extract_event` JSON schema's `enum`, a release and a
deploy. The feature's own module doc already said the quiet part out loud: _"new types
are added as data (query templates) and a new case here."_ The templates were data. The
type was not.

`CLAUDE.md` §2 says **"Enums for every status/type column. No magic strings."** This ADR
breaks that for exactly one column, so the reason is on the record rather than left to be
discovered by whoever next reads the diff.

## Decision

### 1. The branching test

The line between "this is an enum" and "this is a row" is **whether the application
branches on the value**.

These **stay enums**, and the rule is unchanged for them:

| Enum                   | What the code does with it                                                |
| ---------------------- | ------------------------------------------------------------------------- |
| `SignalStatus`         | A `Converted` signal refuses a dismiss, a restore and a second conversion |
| `SignalRunStatus`      | `isTerminal()` / `isActive()` decide whether a run can be claimed         |
| `SignalWatchCadence`   | `staleAfterHours()` → 24 / 168, which is the scheduler's whole rule       |
| `SignalDismissReason`  | `indicatesMisclassification()` reads the reason to decide what it means   |
| `SignalCategory` (new) | Groups the preset library into sections the UI renders                    |

`SignalEventType` **becomes a row**. Grep every use of it before this phase: it picks
query templates (data), renders a `label()` and a `description()` (data), maps an ICP
buying signal by keyword (data), filters the feed (a value), and groups a report (a key).
Not one of those is a decision about which case it is. A "type" the code only ever
carries, displays, templates and groups by is **a row with columns** — and keeping it as
an enum is precisely what makes adding one a deploy.

### 2. A definition is a row; the preset library is platform data

`signal_definitions` is team-scoped (`BelongsToTeam`, ADR 0006) with one deliberate
exception: a row with `team_id = null` is a **platform preset**, readable by every team
and writable only by the platform.

That exception is handled the way ADR 0016 handles platform reporting — with its own
reasoning written down, not as "tenancy with the scope removed":

- An `orWhereNull('team_id')` inside the `BelongsToTeam` global scope is **not
  acceptable**. It would make every query in the module, forever, silently able to return
  rows that belong to nobody, and the next model to need the same trick would copy it.
- Instead, **one** class removes the scope: `Signals\Services\PlatformPresetReader`. It is
  the only place in the module that calls `withoutGlobalScope`, it can only ever return
  rows where `team_id is null`, and it refuses to return a tenant row even if asked for
  one by id. This mirrors how `Admin\Support\PlatformContext` isolates scope removal
  behind one door rather than spreading it.

### 3. A team **clones** a preset; it never references one

Using a preset copies it into the team's own definition, carrying `preset_key` and
`preset_version` as provenance. It does not point at it.

Two consequences, both wanted:

- **A platform edit never silently changes what a team is paying to run.** A definition is
  a standing instruction to spend money on searches; changing one under a team without
  asking is changing their bill. S15 offers the diff instead ("your preset has moved on"),
  which is only possible because the clone is independent.
- **A team's tuning never leaks upward.** A team that narrows a template has narrowed
  _their_ template.

### 4. `event_key` is denormalised onto `signals`

`signals` carries both `signal_definition_id` (nullable FK, `nullOnDelete`) and
`event_key` (a plain string copy).

A definition can be deleted; a signal from it is history and must still render, group and
report. This is the same shape, for the same reason, as `signals.lead_id` — one-way and
`nullOnDelete`, so the record outlives the thing it points at. The S6 migration
(`protect_converted_signals_from_watch_deletion`) learned this the hard way: a cascade
destroyed the provenance of leads teams were still working.

The v1 column `signals.event_type` is **renamed** to `event_key` and widened to 64,
rather than a new column added beside it. A rename needs no backfill and leaves no dead
column, and `event_type` would have become a misleading name the moment the enum went.

### 5. A watch reaches its definitions through a pivot, not a JSON array

`signal_watches.event_types` (a JSON list of enum values) becomes the pivot table
`signal_watch_definition`. **This is a deliberate deviation from the S7 plan**, which
specified a `signal_definition_ids` JSON column, and the reason is portability:

> The delete path has to answer "which watches use this definition?". Over a JSON column
> that is `JSON_CONTAINS` on MySQL and `json_each` on SQLite — two different queries, one
> of which this repo's test suite would never exercise. `memory/sqlite-tests-mysql-prod.md`
> exists because that class of difference has already bitten here. Over a pivot it is a
> join, identical on both.

Two further things fall out of it: `$watch->definitions` is a real relation, so the
resource, the run and the screen eager-load instead of hand-rolling a `whereIn`; and the
FK means the **database** keeps a watch from pointing at a definition that no longer
exists, rather than only the Action doing so.

The plan's second column, `signal_watches.event_keys` ("the audit trail of what was
watched when"), is dropped with it: `SaveSignalWatchAction` already records the keys in
the audit row on every save, so a second copy on the row would be a copy that can go
stale.

The pivot carries `team_id`, per the rule that every new table does. It is redundant with both
parents, and it is **defence in depth rather than a database guarantee** — the three foreign keys
are single-column, so nothing in the schema stops a row claiming one team while pairing another's
watch with a third's definition. What prevents that is `SaveSignalWatchAction`, the only writer,
which resolves every id through a team-scoped query and stamps the acting team, and
`RunSignalWatchAction`, which re-checks `team_id` on both sides before running. The column is what
makes a bad row findable, and what a composite foreign key would hang off if one is ever wanted.
(An earlier draft of this ADR claimed the database made it impossible; review corrected that.)

### 6. Portable uniqueness: `scope_id`

A definition's key is unique **within its scope**: one `acquisition` per team, one
`acquisition` preset. The obvious index, `unique(team_id, key)`, does not express that,
because MySQL treats NULLs as distinct in a unique index — two presets could share a key
and the test suite (SQLite) would never say so. The plan anticipated this and called for a
generated scope column.

It is implemented as `scope_id`: an unsigned integer that is the `team_id` for a tenant
row and **0** for a preset, maintained in the model's `creating`/`updating` hooks, with
`unique(scope_id, key)`. Portable, one index, and testable in both engines.

### 7. The AI schema loses its `enum`, so the Action becomes the guard

`signals.extract_event` v1 constrained `event_type` to a JSON-schema `enum` of the three
cases, which meant a hijacked or hallucinated response **could not** return a type outside
the set — the schema was the guard. With types as data that enum cannot exist, so v2
constrains `event_type` to a bounded string and injects the watch's own keys, names and
classification rules into the prompt body.

**That is a real safety regression, and it is closed in the Action, not the prompt.**
`RunSignalWatchAction` rejects any returned `event_type` that was not among the keys it
offered for that batch, and drops the item. A prompt cannot be trusted to enforce a
constraint it merely describes. There is a test that feeds a hostile key back
(`SignalDefinitionRunTest`).

The prompt is a **new version** (v2), never an edit of v1 — the standing rule in
`docs/ai-prompts.md`, which protects prompts whose outputs are already logged against
their version. `ai_requests` rows do reference v1.

### 8. What a definition may _not_ contain

A definition is authored by a customer and reaches an AI prompt, so its free text is
bounded in characters at the DTO (ADR 0017's "bounded in characters by a truncation and a
refusal" applies here too), its query templates are clamped and whitespace-squished, and
its `icon` is an **allowlisted** enum rather than a free string — the client maps an icon
key to an imported component, and an arbitrary string there is a hole, not a label.

The compliance posture of ADR 0011 is untouched and is not a definition's business: a
definition cannot ask for contact details, cannot change the suggested-approach rule
(`ExtractedSignal::approach()` still enforces LinkedIn-or-referral in code), and cannot
widen the data a signal stores.

## Consequences

**Good**

- A user creates their own signal types. That is the phase's point, and it needs no deploy.
- The preset library is a product surface: S9 fills it with vertical packs, S15 curates it.
- `SignalQueryBuilder` becomes a pure function of (watch, definitions, ICP) — which is what
  makes S9's "preview before you save" possible at all.
- Per-definition thresholds, which v1 had only globally in config.

**Costs, accepted**

- One column in the codebase is a string keyed to a row rather than an enum. §1 is the
  defence; `SignalCategory` and `SignalIcon` are enums for exactly the reason that one is
  not.
- Two writers of the platform presets: the frozen snapshot inside the S7 data migration
  (a migration is a snapshot, so it must not read a catalogue that will grow in S9) and
  `SignalPresetSeeder` reading `SignalPresetCatalogue` for fresh installs. The catalogue is
  the living source of truth; the migration's copy is dead data by design.
- The data migration repoints every existing team's watches and signals. It is reversible,
  idempotent, and must be run against a MySQL copy before production — the rename, the
  widened column and the new unique index are all things SQLite would wave through.

**Superseded / amended**

- ADR 0011's "the set is deliberately small for v1 … new types are added as data and a new
  case here" is amended: there is no case to add.
- `config/signals.php`'s `templates` key is now **seed data only**. The query builder no
  longer reads it. The comment in the file says so.
