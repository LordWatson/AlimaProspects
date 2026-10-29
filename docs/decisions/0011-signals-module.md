# ADR 0011 — Signals module & Serper News

- **Status:** Accepted
- **Date:** 2026-10-29
- **Extends:** ADR 0002 (module structure), ADR 0007 (markets & compliance), ADR 0008 (lead providers), ADR 0010 (Serper)

## Context

Beyond discovering companies to pitch, the primary v1 customer — a Dubai luxury
real-estate agency — wants to know **when a wealth or liquidity event happens** in
their geography: a company sold, a founder exits, a large funding round, a founder
or executive relocating to Dubai. These events are leading indicators of a
high-net-worth buyer. They are reported in published business news, not in company
registries, and they are **events about a person or company**, not company records
to be discovered and deduped.

Three shapes were considered:

- **A new `LeadSourceProvider`.** Rejected. Providers return company leads keyed for
  dedupe and are driven by the discovery pipeline; a signal is an _event_, has its
  own lifecycle (New → Dismissed/Converted), stores a named person, and must not be
  swept into runs automatically. Forcing it through the provider contract would
  distort both.
- **A feature inside Lead Sourcing.** Rejected. It would couple event-watching to
  discovery runs and blur ownership (ADR 0002).
- **Its own domain module that hands off to Lead Sourcing only on conversion.**
  Chosen.

## Decision

- **Signals is its own domain module** (`app/Modules/Signals/`, ADR 0002), with its
  own tables (`signal_watches`, `signal_runs`, `signals`), enums, policies, jobs and
  UI. It is **not** a `LeadSourceProvider`.
- **Serper News is the source.** Signals reuses the **Serper account from ADR 0010**
  (same key/base URL in `config/lead-sourcing.php`) via the **`/news`** endpoint,
  with its own timeout/retry/circuit-breaker settings in `config/signals.php`.
- **v1 is UAE-only** (`gl=ae`, `hl=en`, `tbs=qdr:w`), reflecting ADR 0007's market
  priority and the first customer. The event types and query templates are **data in
  config**, so other markets and verticals reuse the module later without code
  changes (UK/Companies House streaming is in the post-v1 backlog).
- **Snippet-only.** A signal is built from the news item's **title + snippet +
  source + date** only; no full-page fetch in v1 (full-article enrichment is backlog,
  behind a cost cap).
- **AI classifies and extracts**, cheap tier, treating article text as **data, never
  instructions** (AI Platform prompt-injection safety). It returns a structured,
  schema-validated event with `confidence`/`relevance` scores; below-threshold items
  are dropped.
- **Conversion is the only route from a signal to a `Lead`.** A team member converts
  a signal, which creates a standard company-level `Lead` (new `LeadSource::Signal`)
  with the named person as a `LeadContact`, then flows through the existing
  Enrichment → Scoring → Leads Workspace pipeline. The **FK is one-way**
  (`signals.lead_id` → `leads`), so Lead Sourcing never depends on Signals.
- **Data minimisation is designed in.** A signal stores a person's **name, public
  role and source URL only** — never email or phone. Converted UAE leads are flagged
  **"prior-consent outreach only"** (LinkedIn / referral, never cold email), per
  ADR 0007 / `docs/compliance.md`.

## Consequences

- One more metered use of the existing Serper dependency; per-run cost is logged on
  `signal_runs.cost_micros` exactly as `discovery_runs` does (≈ $0.04 / team / day;
  verify rates against live pricing).
- A new, small personal-data surface (named individuals from published news). It is
  governed by a legitimate-interest basis with a dedicated LIA section, a retention
  sweep, and a DSAR hook — see `docs/compliance.md`. Marked ⚖️ for the pre-launch
  legal review already required by ADR 0007.
- Signals and Lead Sourcing stay decoupled: Signals reads nothing of Lead Sourcing
  except the public `Lead`/`LeadContact` creation path at conversion, and exposes the
  origin panel to Leads Workspace through a Signals-owned resource/query.
- **Serper `/news` returns at most ~10 items per query** (the spike below), so query
  _breadth_ (several templates per event type), not a large `num`, drives coverage.
- **The plan's exact-phrase query templates are too narrow** for `/news` over a
  one-week window and are loosened to plain keywords before S2 (see the spike in
  `docs/modules/signals.md`).

## Serper News spike (2026-10-29)

Ran the plan's S2 templates against `POST https://google.serper.dev/news` with
`gl=ae, hl=en, tbs=qdr:w, num=20`. The strict exact-phrase templates (e.g.
`"Dubai-based" company acquired`) returned **0** results for 7 of 8 queries;
loosened plain-keyword variants returned **3–10** genuine Dubai/UAE events per event
type — comfortably past the ~3-real-events/week bar. **Decision: proceed with
Signals, with loosened templates.** Full result tables, the working templates and
the noisy domains to ignore are recorded in `docs/modules/signals.md`.
