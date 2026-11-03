# ADR 0023 — Signal sources are a registry, and an inbound event is untrusted

- **Status:** Accepted
- **Date:** 2026-11-03
- **Extends:** ADR 0011 (Signals module), ADR 0008 (lead providers behind a registry), ADR 0010 (Serper as the web-search provider), ADR 0018 (signal definitions are data), ADR 0019 (search geography is not the compliance market), ADR 0013 (the `/api/v1` contract)
- **Phase:** S13 of `signals-v2-plan.md` — accepted whole at **S13a**, so S13b implements it rather than deciding it

## Context

Until S13 a signal definition meant exactly one thing: a set of keyword templates sent to Serper's
`/news` endpoint. Every part of the module said so — the contract was called `NewsSearchClient`, the
DTO it returned was called `NewsItem`, and the only failure mode anybody had named was
`newsProviderFailed`.

That is too narrow for the events teams actually want. Three of the most valuable trigger events in
the product's own backlog are not news at all:

- a **registry filing** — a PSC change, an officer appointment, accounts going overdue, an
  administration notice — which is structured, authoritative, and free;
- something published **outside a news desk** — a planning decision, an award announcement, a
  company's own blog post;
- a **customer's own data**, pushed in, which is the difference between a feature and a platform.

Each of those is a different thing to fetch and a different thing to trust. A registry filing needs
no AI to tell you what it is. A web page found by a general search is noisier than a news article and
needs different exclusions. An inbound webhook payload is data from outside our trust boundary and
has to be treated like it.

So "where a signal comes from" had to become a first-class concept, with one seam, one hardening
story, and one place that says how far each source is believed.

## Decision

### 1. A source is a registry entry, and its key is an **enum**

`Signals\Contracts\SignalSourceProvider` is the seam — `kind()`, `supports()`, `fetch()`,
`estimatedCostMicros()` — and `Signals\Services\SignalSourceRegistry` resolves one by
`Signals\Enums\SignalSourceKind`. This mirrors `LeadSourcing`'s `LeadSourceProvider` /
`LeadSourceProviderRegistry` deliberately: same shape, same registration from the module's service
provider, same ability to register a fake and run the whole pipeline offline.

**The key is an enum, and a signal _type_ is a row, and that is not a contradiction.** ADR 0018 §1
settled which is which with one test — _does the application branch on the value?_ — and the two
answers genuinely differ:

- A signal **type** (`acquisition`, `uk_psc_change`) is a row, because nothing in the code branches
  on it. The run loads its templates, its thresholds and its prompt text and treats them all as
  data. A team inventing a new type is configuration.
- A signal **source** is an enum, because a class exists per case. `fetch()` for Companies House is
  not a different configuration of `fetch()` for news; it is different code, with a different
  credential, a different parser and a different claim to be believed. A team cannot invent one, and
  a case with nothing behind it is a bug rather than an empty configuration.

`SignalSourceKind::isAvailable()` is what separates a case that is wired up from one that is merely
declared, in the lineage of `SearchRegion::isOffered()` and `WatchlistSourceKind::Segment`. S13a
registers `news` and `web_search`; `companies_house`, `feed` and `webhook` are declared, not
offered, and the form does not show them.

### 2. Which sources a definition runs on is data on the definition

`signal_definitions.source_keys` (json, every existing row backfilled to `["news"]`) and
`signal_definitions.source_config` (json, per-source settings such as a SIC-code list or a feed URL).

The backfill is the whole compatibility story: every S7–S12 definition keeps behaving exactly as it
did, because `["news"]` is what it was always doing. `SignalDefinition::sourceKinds()` also treats a
null or empty column as `["news"]`, so a row written by an older code path cannot produce a
definition that searches nowhere — "searched nothing and completed successfully" is the most
confusing failure this feature could have, and S8 already learned it once with locations.

A source declines a definition it cannot serve through `supports()` rather than by failing: a
Companies House source asked for a definition with no SIC codes and no watchlist simply contributes
no queries.

### 3. Hardening is inherited, not re-written per source

`Signals\Services\AbstractHttpSignalSourceProvider` is the lineage of
`AbstractHttpLeadSourceProvider`: timeouts, retry on **429 and 5xx only**, a shared
`Core\Support\CircuitBreaker` **keyed per source** under the `signals:cb` prefix, and fail-fast on a
missing credential so a queued run does not burn its retries on a request that can never succeed.

Per-source keying matters more here than it did for leads: a team running news **and** web search
should not lose their news when Serper's `/search` is unwell. Each new breaker key is also added to
`Admin\Enums\PlatformProvider`, because task 8's "a source with an open breaker is visible to
operations" is only true if the health screen knows the key exists — and `PlatformProviderTest`
asserts the enum still matches the module that writes it.

### 4. A query the provider **refuses** is a skipped query, not a failed run

This is the one decision that came out of the spike rather than out of the plan, and it is a defect
fix.

Serper answers `site:` and quoted-phrase queries on this account tier with
`HTTP 400 "Query pattern not allowed for free accounts."` Before S13a, any `$response->failed()`
raised `SignalsException::newsProviderFailed()`, which is **retryable** — so one template a member
typed with a quote in it would fail their whole watch, three times over, re-paying for every other
query in the plan on each attempt, and report "we couldn't reach the news service", which is both
untrue and unfixable by them.

So a 4xx that is a verdict about **this query** (`400`, `422`) raises
`SignalsException::sourceQueryRejected()`, which is non-retryable, does not trip the breaker — it is
our query's fault, not the provider's health — and is caught **per query** by the run. The query is
counted as `rejected` in the run's source breakdown and the run carries on with the rest of the plan.
A run whose every query was rejected completes with nothing found rather than failing, because
nothing is broken: we asked badly.

The distinction to hold on to: a breaker answers _is this provider up?_, and a rejection answers
_was that a sensible thing to ask it?_ Conflating them makes one team's bad template everybody's
outage.

### 5. Each source reports its own cost and its own outcome onto the run

`signal_runs.source_breakdown` (json) carries, per source kind: `queries`, `results`, `rejected`,
`cost_micros`. `signal_runs.cost_micros` stays the single total, so nothing that reads it changes.

A breakdown rather than a second table because the question it answers is always "what did _this
run_ cost, and where did the money go" — and because S12 already established that a figure the UI
explains must be **stored as the figure the decision was made on**, not recomputed later from
today's prices.

The run also writes two columns onto each signal: `signals.source_kind` (which source found it, so
the card can name it and the feed can filter by it) and `signals.matched_template` (the query that
found it). The second closes a gap S12 recorded as deviation 2 — the explanation panel could not
name the query that found a signal because no row stored it.

### 6. A structured source may skip the AI batch and carry its own confidence

A registry filing is not a sentence to be interpreted. "Company 01234567 appointed a director on
3 November" arrives already parsed, already attributed, and already true — classifying it with a
language model would spend money to make it less certain.

So `SourceItem` carries `classification`: null for an item that needs the `signals.extract_event`
prompt (every news and web-search item), and a populated `ExtractedSignal` for a source that knows
what its item is. `RunSignalWatchAction` partitions the run's items on it, batches only the former
through the AI, and passes the latter straight to persistence with the source's own confidence and
relevance.

This is a real shape change in the run and it is **declared here, at S13a, and implemented at S13b**
with `CompaniesHouseSignalSource` — the first source that needs it. S13a ships the field and the
partition with every source returning null, so S13b adds a source rather than reshaping a run.

Two rules bound it, because "trust me" is what this field says:

- Only a source **we** wrote, against an authoritative provider, may populate it. A webhook may not
  (§7), and neither may anything whose content came from free text.
- A skipped classification still passes the definition's thresholds, so a team that has raised a
  floor is still obeyed. The source asserts a confidence; it does not assert that the team wants it.

### 7. An inbound event is untrusted, and the webhook is where that is enforced

`POST /api/v1/signal-events` (S13b, behind `Capability::ApiAccess`) lets a customer push their own
events in. It is the only path into this module that originates outside our own code, and it is
treated accordingly.

**It is data from outside.** Every one of these is a condition of acceptance, not a feature:

- a **per-definition secret** and an **HMAC signature** over the raw body, compared in constant time;
- a **timestamp window** inside the signature's scope, so a captured request expires;
- an **idempotency key**, so a replay inside the window is accepted once and acknowledged twice;
- a **strict bounded schema** — a fixed field set, each length-capped, rejected whole rather than
  coerced;
- its **own rate limit**, separate from the API's general one, because a webhook's caller is a
  machine that will retry in a loop;
- the payload's free text wrapped in `UntrustedContent` before it reaches the AI, exactly as a news
  snippet is.

And the payload **can never set**:

- `heat` or `score_breakdown` — the score is ours, computed by `SignalScorer` from stored
  components (ADR 0022 §1); a caller who could set it could rank themselves to the top of somebody's
  morning;
- `jurisdiction` or `resolved_market` — these decide which outreach restriction a converted lead
  carries (ADR 0019 §4, `docs/compliance.md` §3.11), so they are resolved from the event's own place
  by `SignalJurisdictionResolver` and an unresolvable one fails **toward** the restriction. A caller
  able to assert "no restriction applies" is a compliance hole with an API key;
- `lead_id` or `matched_lead_id` — provenance and matching are the product's conclusions about the
  team's own data, and `SignalLeadMatcher` reaches them from the subject's name;
- `team_id` — it comes from the authenticated token, never from the body;
- `signal_definition_id` by id — the body names a definition **key**, resolved within the
  authenticated team, so a guessed integer cannot write into another tenant's type.

A webhook event is therefore a `SourceItem` with `classification: null`: it is classified by the same
prompt, thresholded against the same floors, clustered, scored and matched like anything else. The
customer supplies an observation. Alima still decides what it means.

## Consequences

- **The two DTOs were renamed, because both had become lies.** `NewsItem` → `SourceItem` and
  `SignalQuery` → `SignalSourceQuery`. A clean rename with no alias: these are internal to the
  module, and a deprecated name kept "for safety" is how two spellings of one concept survive into
  the next phase. `NewsSearchClient` and `SerperNewsClient` are gone, replaced by
  `NewsSignalSource`; its behaviour is unchanged and `SerperNewsClientTest`'s cases moved with it.
- **A definition can cost more than it used to**, because it can run on more than one source. The
  per-run query ceiling (`signals.max_queries_per_run`) still bounds a run, and it now bounds it
  across sources rather than within one — so a definition on two sources halves the places it can
  cover before it is trimmed. The run already records `queries_requested` beside `queries_count` and
  the watch card already says coverage was trimmed (ADR 0019 §4); S16 is where a plan limit lands.
- **The feed gained a filter nobody can remove.** `signals.source_kind` has a default and an index,
  and it is never null, so "which source found this" is answerable for every row including every
  pre-S13 one (`news`, correctly — that is what they were).
- **Web search needs its own exclusions**, which the spike confirmed: the global `ignore_domains`
  list already catches the social noise that dominates `/search`, and what it does not catch is job
  aggregators and informational service pages. Those live in `signals.sources.web_search.ignore_domains`
  and are merged on top of the global list **for that source only** — a team's own list still applies
  to every source.
- **`JobPostingSignalSource` was dropped, on evidence.** The spike is in
  `docs/modules/signals.md`; the short version is that the account tier refuses `site:`, that a job
  advert is not a trigger event (the event is a _pattern over_ adverts, which is an aggregate this
  source shape cannot compute), and that the hiring-surge signal the pack actually wanted has
  shipped on `/news` as `rec_hiring_surge` since S9.

## Alternatives considered

- **One `SignalSource` model row per source, like a definition.** Rejected for the reason in §1: a
  source is code. A row per source would let somebody create one with no class behind it, and the
  only honest thing the product could do with it is fail.
- **Keep `NewsSearchClient` and add a sibling contract per source.** Rejected: the run would then
  branch on which client to call, which is the registry re-implemented badly, and the per-source
  cost and health reporting would have no single shape to read.
- **Let a source return finished `Signal` rows instead of `SourceItem`s.** Tempting for Companies
  House, and rejected because it would let a source bypass thresholds, clustering, scoring,
  jurisdiction resolution and lead matching — every one of which is a rule that must hold whatever
  found the event. §6 is the narrow version of this that keeps all of them.
- **Trust a webhook's own `occurred_at`, `jurisdiction` and severity.** Rejected in §7. The
  jurisdiction one is not a judgement call: it decides whether cold outreach is lawful.
- **Spike job postings with a paid Serper tier before dropping the source.** Rejected as the wrong
  order. The tier would fix `site:`, but it would not make a single job advert a trigger event, and
  that is the finding that kills it. If hiring-surge aggregation is wanted later it is a different
  source shape — one that counts postings per company over time — and a different decision.
