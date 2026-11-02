# ADR 0019 — Search geography is not the compliance market

- **Status:** Accepted
- **Date:** 2026-11-02
- **Extends:** ADR 0007 (markets & compliance), ADR 0011 (Signals module), ADR 0018 (signal definitions are data)
- **Phase:** S8 of `signals-v2-plan.md`

## Context

`signal_watches.market` was doing two unrelated jobs.

It picked the Serper country code (`config('signals.markets.uae.gl')` → `ae`), which is a question
about **where to look for news**. And it decided the outreach restriction stamped on every lead a
converted signal produced (`Market::outreachRestriction()`), which is a question about **which law
governs approaching this person**.

While every watch was UAE-only those two questions had the same answer, so one column could serve
both and nobody had to notice they were different. S8 lets a watch span places, and the moment it
does the conflation is wrong in **both** directions:

- A UAE-market watch catches a London funding round → the lead is stamped `uae_prior_consent`.
  Over-restrictive: a British founder is held to a rule that does not apply to them, and the team is
  told not to email somebody they lawfully may.
- A UK-market watch catches a Dubai relocation → the lead is stamped **nothing**. Under-restrictive,
  and the serious direction: UAE PDPL requires the person's prior consent for direct marketing, a
  news article cannot supply it, and Outreach would see no reason to refuse a cold email
  (`docs/compliance.md` §3.3, §5a).

The second case is a compliance defect rather than an inconvenience, which is why it is fixed in the
same phase that makes it reachable rather than after.

## Decision

### 1. Two concepts, two names

|          | `SearchRegion` (new, Signals)                                           | `Market` (existing, Core)                             |
| -------- | ----------------------------------------------------------------------- | ----------------------------------------------------- |
| Answers  | Where do we look?                                                       | Which rules apply?                                    |
| Carries  | A Serper `gl` + `hl`, a label, and a **nullable** pointer at a `Market` | A compliance regime, a default currency, locale hints |
| Lives on | A **watch** (`signal_watches.regions`)                                  | A **team**, and — resolved per event — a **signal**   |
| Set by   | The team, when they say where they sell                                 | ADR 0007, when the business takes a legal position    |

**`Core\Enums\Market` gains nothing in this phase.** It is tempting to add `Saudi`, `UnitedStates`,
`Global` so a watch can point anywhere, and it would be wrong: every case on that enum asserts that
the business holds a legal position for that jurisdiction, and ADR 0007 says we hold exactly two
(UK: legitimate interest, assessed per team via the LIA; UAE: PDPL prior consent). A `Market` case
with no position behind it is a compliance claim made by a programmer.

So `SearchRegion` is the enum that grows, and its `market()` is **nullable** — "we can search here,
and we have not taken a view on the law here" is a representable and honest state.

### 2. The restriction follows the subject, not the watch

`ConvertSignalToLeadAction` resolves it in this order:

1. **The signal's own `resolved_market`**, derived at extraction from the AI's free-text `location`
   via `SignalJurisdictionResolver`. This is the answer in the normal case, and it is the whole
   point of the ADR: the restriction protects the _person being approached_, and where that person
   is has nothing to do with which watch happened to find them.
2. **The watch's `market`** — the team's own declared regime for that watch. The backstop when the
   article named no place we recognise.
3. **The most restrictive restriction across the watch's regions**, where those regions map to a
   market at all. Only ever used to _add_ a restriction the first two did not supply.

Each step may **add** a restriction and none may remove one. That is the existing rule
(`docs/compliance.md` §5a — "a compliance limit may be added but never dropped") and the
de-duplicator merge already re-asserts it; S8 does not weaken it.

`signals.jurisdiction` (ISO-3166-1 alpha-2) and `signals.resolved_market` are both stored, because
a lead has to be able to explain its own restriction later, and because "the resolver said AE" is
a fact worth keeping rather than recomputing against a place map that will change.

### 2a. `offered` governs searching, and never restricting

The two readings of "this region is switched off" pull in opposite directions, and the review found
the code taking the wrong one. `config('signals.regions.<code>.offered')` is a statement about what
a watch **may search from now on** — withdraw a region and it must stop costing money immediately,
including for the watches that already named it. It is **not** a statement about the signals that
region has already produced: a lead found under `ae` was found there whatever the config says today,
and reading the filtered list in the restriction backstop meant a config change made for an
unrelated reason quietly turned a restricted lead into an unrestricted one.

So `SignalWatch` has two accessors on purpose. `searchRegions()` is filtered and only the query
builder reads it; `storedRegions()` is unfiltered and the restriction reads that. A compliance
switch that relaxes a restriction when you turn it off is worse than no switch.

That only holds while the stored row survives, which is the second half of the same fix. The editor
is sent the **offered** regions (`SignalWatchResource` sends `searchRegions()`), so a member editing
a watch never sees a withdrawn region and cannot have meant to untick it — and the save would have
dropped it, taking the backstop with it one edit later. `SaveSignalWatchAction` puts back any stored
region the form could not have shown. The watch stops looking there and still remembers that it did,
which costs nothing because the query builder filters on its own.

### 2b. An ambiguous place resolves to the most restrictive candidate

"UK founder relocates to Dubai" names two countries the map knows, and the first version settled it
with the watch's own region order — so a watch listing `gb` before `ae` resolved that sentence to
the United Kingdom. Because step 1 above treats a resolved market as **final** (deliberately: when
we know where the person is, that is the answer, and the over-restrictive direction is the one the
whole ADR exists to avoid), the lead about somebody moving to Dubai carried no restriction at all.
A restriction that depends on the order a member ticked boxes in is not a rule.

Two places named is an ambiguity, so the ambiguity rule applies: the candidate whose regime
restricts outreach the most wins, ranked by `allowsDirectMarketing()` rather than a hard-coded
ordering, and the watch's regions are consulted only between candidates that are equally
restrictive — where whichever wins carries the same restriction either way.

**Specificity outranks restrictiveness**, which is what makes those two rules live together.
"New South Wales" matches `AU` on `new south wales` and `GB` on `wales`, and that is one place
matched twice rather than two places named. A match whose alias sits inside another match's alias is
dropped as the artefact it is _before_ restrictiveness is consulted. Without that step the more
restrictive half of an artefact pair would win, and `london` or `ras al khaimah` would start
swallowing longer names from any country added later.

### 3. Unknown fails toward the restriction, never away from it

The resolver returns **null** when it cannot tell, and null does not mean "unrestricted":

- A signal with no resolvable jurisdiction on a watch covering `ae` + `gb` gets the **UAE**
  restriction, because that is the more restrictive of the two the watch could have found.
- A signal with no resolvable jurisdiction on a `gb`-only watch gets none, because none of the
  candidates implies one — and for the UK that is correct rather than permissive: ADR 0007 puts the
  UK on legitimate interest assessed per team, so the LIA is the control, not a per-lead flag.

**A region with no `market` contributes nothing to the restriction set.** It does not map to "the
restrictive default", which an earlier draft of the S8 plan proposed, and the reason is that the
only restriction the app has is `OutreachRestriction::UaePriorConsent` — a flag whose name, label
and guidance are all specifically about UAE PDPL. Stamping it on a Singapore lead would have
Outreach do the right thing (refuse cold email) for a reason that is simply false, and would tell a
member a lie about why. Inventing a second, vaguer case ("jurisdiction unknown — approach by
introduction") is a compliance-semantics decision for counsel and the business, not for this phase.

What follows from that, and is the safe half of the trade: **a region the app holds no position on
is not offered.** `SearchRegion` knows about seven regions because `gl` and `hl` are search facts
with no legal content, but `config('signals.regions.<code>.offered')` is what the watch form and the
API will accept, and it is true by default only for `ae` and `gb` — the two regimes ADR 0007
covers. Enabling `sa`, `us`, `in`, `sg` or `global` is a one-line config change **and** a compliance
question, recorded as ⚖️ in `docs/compliance.md` §5. That keeps the code ready for the markets the
business will take on without shipping a watch that can produce leads nobody has decided the rules
for.

### 4. A watch covers places, plural

`signal_watches.location` (one string) becomes `locations` (a list, max 10) and `regions` (a list of
`SearchRegion`, max 5). `market` **stays** on the watch, demoted to what it always should have been:
the team's declared regime for this watch, used to seed defaults and to backstop step 2 above. It no
longer picks `gl`.

The query builder multiplies **templates × locations**, which is where the cost lives: six
definitions of ten templates across ten locations is 600 paid searches for one run. The
`signals.max_queries_per_run` ceiling added in S7's review is what holds that, and S8 makes it bite
in a second dimension. The trim is deterministic and the run **records what it wanted** alongside
what it ran (`signal_runs.queries_requested`), so the watch card can say coverage was reduced. A
silent trim is a lie about what was searched.

### 5. `hl` comes from the region, which is what unlocks Arabic

`hl` was `config('signals.hl')` — one language for the whole product, documented as "v1: English
only". It is now a property of the region (`ae` → `ar` is available, `gb` → `en`), and a definition
may carry `query_templates_ar` beside its templates. A region whose `hl` is not `en` uses the
Arabic templates where a definition has them and falls back to the English ones where it does not,
so adding a region never silently empties a watch.

This phase ships the **plumbing only**. Writing Arabic query templates is a content task that wants
the native-speaker pass already pending in `docs/modules/signals.md`, and a machine-translated
search query is worse than no translation — it finds nothing and looks like it works.

## Consequences

**Good**

- The compliance defect is closed before it could be reached, and in the direction that matters.
- A team sells into Dubai and London from one watch, which is what a real customer looks like.
- A lead can explain its own restriction, because the jurisdiction that produced it is stored.
- `hl` per region makes Arabic a content decision rather than an architectural one.

**Costs, accepted**

- A fourth thing to get right on a watch form (name, places, regions, types). The form pre-selects
  the region matching the team's market, so the common case is still one field.
- The resolver is a deterministic place map and will not know every town. Its failure mode is null,
  which fails toward the restriction, and a missing place is a config line rather than a code change.
- `signals.resolved_market` is denormalised from `jurisdiction`. It is derived at extraction and
  never recomputed, so changing the place map does not retroactively re-label old signals — which is
  deliberate: a lead's restriction should not change because we tidied a lookup table.

**Found by review, after the first implementation**

Four of the five defects the `laravel-module-reviewer` pass found were in this ADR's own subject
matter, which is worth recording as a pattern rather than a list: every one of them was a place
where a _second_ reading of an existing concept had quietly been adopted. `offered` meaning two
things (§2a). The watch's region order standing in for a legal rule (§2b). `teams.market` being
re-applied on every save, so an edit moved a watch's regime — and because `teams.market` defaults
to `uk`, a UAE team that never visited its settings would have had its watches flip on the first
rename. A rollback deleting "its own" rows, where "its own" had been read to include a team's clone.
None of them would have failed a test that existed, and three of them failed permissively.

The fifth was arithmetic rather than meaning: the plan was nested region-major, so a ceiling smaller
than one region's worth of queries spent the whole budget on the first region and left the second
with **nothing**. A member who asked for two jurisdictions silently got one. The nesting is now
place-major (§4), which is the order that makes the loss survivable _and_ visible.

**Open (⚖️)**

- The compliance position for `sa`, `us`, `in`, `sg` and "global" — needed before those regions are
  offered. Recorded in `docs/compliance.md` §5.
- Whether an "unknown jurisdiction" restriction should exist as its own `OutreachRestriction` case,
  which is the cleaner answer to §3 above and needs a view from counsel on what it would oblige.
- **The approach notes are compliance copy and need signing off in both languages.** They are the
  sentence a member reads before choosing where to look, and the UK one in particular now asserts
  what is permitted ("may be emailed where your legitimate-interest assessment covers them") rather
  than naming a document. The Arabic was rewritten in the same pass — the first version said "sea"
  for "search", weakened "never" to "and not", and called prospects customers — and wants a native
  legal-register reader, not just a translator.
