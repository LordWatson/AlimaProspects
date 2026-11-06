# ADR 0025 — Free lead sources expansion

- **Status:** Accepted
- **Date:** 2026-11-06
- **Phase:** L0 of `lead-sources-plan.md`
- **Supersedes / amends:** extends ADR 0008 (lead data providers) and ADR 0011/0023 (Signals and its
  source registry). Nothing is withdrawn.

> **Numbering note.** `lead-sources-plan.md` calls this decision "ADR 0018". That number was taken by
> _signal definitions are data_ while this plan sat unexecuted, and the Signals v2 work has since run
> to 0024. The decision is **0025**; every "ADR 0018" in the plan file means this document, and the
> plan's header now says so.

## Context

The product discovers companies through three providers (Companies House, Google Places, Serper web
search) and watches for trigger events through five signal sources (Serper news, Serper web search,
Companies House filings, a feed, an inbound webhook). ADR 0008 ruled paid enrichment out of the MVP.
That leaves a gap the plan set out to close with **free** public data: more company registers, more
event feeds, and one market (the UAE) where ADR 0007 recorded that no free company registry existed.

Phase L0 was a licence, terms and limits spike over seventeen candidate sources. Its findings are in
`docs/modules/lead-sourcing.md` → _Free sources — spike findings (L0)_, with the live calls, the
robots.txt rules and the quoted terms. This ADR records the decisions that came out of it.

## Decision

### 1. Two seams, and both of them already exist

A **company record** enters through `LeadSourcing\Contracts\LeadSourceProvider` (ADR 0008). An
**event** enters through `Signals\Contracts\SignalSourceProvider` + `SignalSourceRegistry` (ADR
0023). A new source is a registry entry behind one of those two contracts and nothing else: no
branch in `DiscoverLeadsJob`, no branch in `RunSignalWatchAction`.

The plan assumed the Signals seam had to be built first and gave it a phase (L6). **It does not —
S13a built it**, with a per-source circuit-breaker key, the three addressing shapes
(`isQueryBased()` / `addressesSubject()` / definition-addressed), the "a structured source may carry
its own classification and skip the AI batch" rule, and the 4xx trichotomy (breaker /
`SourceQueryRejected` / `SourceAddressNotFound`). L6 is therefore **superseded**, and every later
signal source in this plan is built against ADR 0023's contract as it stands. That is the single
largest saving L0 found: a phase of refactoring that was already paid for.

### 2. Reference datasets are platform-level, with no `team_id`

A bulk public file (HM Land Registry's CCOD/OCOD being the motivating case) is **reference data, not
tenant data**. Importing it per team would store the same several million rows once per customer to
answer the same question. So:

- its tables carry **no `team_id`**, which is a documented exception to ADR 0006 and the second one
  in the codebase after `signal_definitions`' platform presets (ADR 0018 §3);
- they are **read-only to tenants**. Nothing in a tenant request writes one; a scheduled import
  does, and a tenant query only joins against them;
- every import writes a **ledger row** (`reference_dataset_imports`), because an import that half
  ran is indistinguishable from one that never did without one;
- a refresh **imports into a new version, flips a pointer, then deletes the old one**. The live
  version is never the one being written.

The ADR 0018 §9 lesson applies with full force and is the one way this gets built wrong: **removing
a read scope and removing a write's auto-assignment are two different jobs.** A reference table has
no `BelongsToTeam` trait at all, so it has neither — but anything that _joins_ a reference table to
a tenant table is a query under the tenant scope, and an eager load re-applies that scope.

### 3. Personal data: the provider line is absolute, the signal line is narrow

- A `LeadSourceProvider` stores **no named individual, ever**. Officers, PSCs, trustees, registered
  managers, partners and licence holders are dropped at the mapping step, not filtered later.
- A `SignalSourceProvider` may store a person's **name, public role and source URL** and nothing
  else, which is ADR 0011's existing rule, unchanged.
- **Never ingested at all**, by any seam: personal (non-corporate) insolvency notices, individual
  planning applicants, residential proprietors, and any register's named-individual endpoint.
  Three sources make this a licence condition rather than a preference — the Charity Commission's
  terms oblige us to delete an individual's personal data on notice and forbid using it for
  marketing without consent, the Open Government Licence **does not cover personal data** at all
  (which is the rule that decides the Gazette's shape), and the FCA's register is largely about
  authorised individuals.

### 4. A source is dropped on its own terms, not worked around

Five of the seventeen are dropped, and the reasons are worth keeping because they generalise:

- **Adzuna** permits "personal job search", publishing its listings, and research, and says any
  other use "including commercial analysis, resale of data, and sales prospecting or lead
  generation" requires a written agreement. That sentence names our product. Dropped.
- **Reed** publishes **no** API terms. Silence is not permission, least of all for storing a third
  party's content in a commercial database. Dropped pending written permission.
- **PlanIt**'s `robots.txt` disallows `/api/applics/`, `/api/areas/` and `/ukplapi/` to every agent.
  We respect robots.txt (§4 of `docs/compliance.md`); a public JSON endpoint behind a `Disallow` is a
  maintainer asking politely. Dropped.
- **DMCC**'s member directory is an iframed Salesforce site whose `robots.txt` is `Disallow: /` for
  everyone but googlebot. **DIFC**'s public register is a search under `Disallow: /search/` and
  `/api/`. Both dropped.
- **ADGM** publishes no API, no bulk file and no machine-readable register; the search is a
  Salesforce Lightning app and `www.adgm.com` disallows `/api/`. Dropped as unbuildable rather than
  as forbidden, which is a different reason with the same answer.

Consequently **L4 (UAE free zones), L8 (hiring signals) and L10 (planning applications) are marked
❌ Dropped** in the plan's status table. The honest version of ADR 0007's "no free UAE registry" gap
is that it is still open: the one lawful machine route found is Dubai Pulse's
`dld_free_zone_companies_licensing` open dataset, which needs the same permission request as the DLD
transactions dataset and so is folded into L12's account request rather than guessed at now.

### 5. Attribution travels with the data

Each source's required attribution is stored **on the record** and shown wherever the record is
shown. OGL v3 sources (FSA, CQC, Charity Commission, Contracts Finder, Find a Tender, The Gazette)
carry the Crown-copyright acknowledgement; OpenStreetMap carries "© OpenStreetMap contributors"
(ODbL). This is not a footer: the lead screen, the export and the API resource all show it, for the
same reason §3.13 of `docs/compliance.md` gives for the outreach restriction — a surface that can
show the data can show where it came from, or it does not get to show the data.

One source adds a condition that is not about attribution at all: the FSA's hygiene rating is a
**point-in-time** fact and presenting a stale one as current may be an offence. So we store the
register entry as a company record with its `RatingDate`, and the rating is never rendered as a
current rating.

### 6. Companies House streaming is a supervised worker, not a queued job

The streaming API is a long-lived HTTP connection with a resumable `timepoint`, limited to two
concurrent connections per key and explicitly ban-on-abuse. A queued job with `$timeout` is the
wrong shape for it. It runs as a **supervised long-running process** (`artisan` command under the
process manager), persists its `timepoint` after each batch, reconnects with backoff, and hands each
event to the ordinary `SignalSourceProvider` path. Horizon does not enter into it, and — the Windows
note from CLAUDE.md §1 — it cannot be run locally the way production runs it.

### 7. Plan gating: none

**Every new source is available on every plan.** Lead quotas (`LeadQuotaSource`) and the Signals
allowances (`SignalQuotaSource`, ADR 0012/S16) stay the only limits. No new `Capability` case, no
new seam, no plan matrix.

The reasoning, recorded because the opposite is the tempting default: every source here is free to
us, so there is no cost to recover, and `Capability`'s own rule is that every case is enforced
somewhere. Gating would also make the cheaper plans **worse than they are today** the moment the
first source ships — a picker that hides a source a customer could use yesterday — which is the
argument `config/billing.php` already makes about the AI credit allowance. A source that later turns
out to cost real money (our own Overpass instance, say) is a cost question for the guards, not a
feature flag.

### 8. Volume politeness is a property of the source, not of the caller

Every source gets its own `timeout`, `retry_times` and circuit-breaker key in config, as ADR 0008
and 0023 require. Two carry a stronger obligation that the spike found in the service's own words:

- **Overpass**'s public endpoint answered a fourth query in a row with "This public endpoint is
  intended for browser-based and human-operated tools. For automated or high-volume use, run a local
  Overpass API instance." So `OSM_OVERPASS_URL` is configurable from day one, concurrency is capped
  at 1, 429 and 502 back off, and production volume means **our own instance** — which is the point
  at which this free source stops being free.
- **The Gazette**'s `robots.txt` sets `Crawl-delay: 10` and disallows the per-notice `data.xml`
  / `data.rdf` URLs. We read the search **feed** (`/insolvency/notice/data.feed`), not per-notice
  data URLs, at no more than one request every ten seconds.

## Consequences

- Three phases (L4, L8, L10) are dropped and one (L6) is superseded: the plan's fourteen phases
  become ten. L2, L3, L5, L7, L9, L11, L12, L13 and L14 stand.
- Four sources are blocked on the user, not on us: a Companies House **streaming** key, the HM Land
  Registry **account and licence acceptance**, CQC/FCA/Charity Commission **keys**, and the Dubai
  Pulse **permission grant**. Each phase that needs one says so.
- The `LeadSource` enum's `isAvailable()` becomes config-driven (L1), because "built" and "has its
  key" are now two different questions and a source can be true for one and false for the other.
- `docs/compliance.md` §3.14 and §5 carry the new personal-data lines and the two new ⚖️ questions
  (the Land Registry licence's commercial terms; the FCA's silence on commercial reuse).

## Alternatives considered

- **Build the Signals seam as L6 planned.** Rejected: it exists (ADR 0023). Writing a second one
  would be the "logo that exists twice" failure one layer up.
- **Scrape the three UAE free-zone registers anyway.** Rejected on their own robots.txt. The gap in
  ADR 0007 is real and this would have closed it, which is exactly why the decision had to be made
  against the terms rather than against the roadmap.
- **Gate the property-ownership and contract-award sources as premium.** Rejected per §7.
- **Import reference datasets per team.** Rejected: the same public rows once per tenant, and a
  tenancy scope asserted over data that has no tenant.
- **Store CCOD/OCOD title addresses as lead addresses.** Deferred to L3 under the licence: the
  address data derives from Ordnance Survey and Royal Mail and the dataset's individual licence,
  which is readable only inside the account, appears to restrict its use. Until that is read, L3
  plans on the **count** of titles owned, not on their addresses.
