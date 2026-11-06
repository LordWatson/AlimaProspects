# ADR 0022 — A ranked feed that can explain itself, and a product that proposes rather than retunes

- **Status:** Accepted
- **Date:** 2026-11-03
- **Extends:** ADR 0011 (Signals module), ADR 0018 (signal definitions are data), ADR 0012 (`usage_meters` is a record over a ledger, not a counter)
- **Phase:** S12 of `signals-v2-plan.md`

## Context

Until S12 the feed was in date order. That answers "what is newest" and never "what is worth my
morning", and after S11 made one event one card the ordering problem was the whole remaining
problem: a team watching four definitions across three cities gets thirty cards a day, of which
perhaps three matter.

Ranking is easy to do and easy to do unaccountably. A score a member cannot interrogate is worse
than date order, because date order is at least honest about what it is — nobody wonders whether
the newest article is newest for a reason they would disagree with. And a ranking the team cannot
influence becomes a ranking they work around, by dismissing everything from one outlet by hand,
every day, for ever.

S12 therefore had three jobs that are really one: score the feed, show the working, and let the
team's own behaviour change the score — without any of those three making the feature harder to
support than it already is.

## Decision

### 1. The score is deterministic, and the components are stored, not just the total

`Signals\Services\SignalScorer` produces `signals.heat` (0–100) from eight components — AI relevance,
AI confidence, recency decay, amount banded against the team's own history, learned source authority,
subject seniority, cluster breadth, ICP fit. **No AI in the scorer.** The AI's own judgements are
inputs to it; the arithmetic over them is ours, because an unexplainable score is the thing this
phase exists to avoid and a second model call would be exactly that, at a price.

Weights live in `config/signals.php` with per-definition overrides in
`signal_definitions.score_weights`, and are **normalised**: the heat is `contributed / assessed`, so
nobody can lift a signal by adding components and an untuned definition sits on the same scale as a
tuned one.

ICP fit reuses `Scoring\Services\DeterministicScorer` through an unsaved `Lead` rather than
reimplementing a fit rule. A second definition of "does this match our ideal customer" is a second
thing to keep in step with the first.

### 2. Unknown is not zero — on both sides of the average

A component with nothing to judge — no amount in the article, no history for the outlet, no active
ICP — is **left out of both the numerator and the denominator** rather than scored zero.

This is the decision most likely to be undone by somebody tidying up, so: scoring an unknown
component zero would make the heat measure **how much Alima knows about an event** rather than how
much the event is worth, and the practical effect is that a thinly-reported £40m acquisition ranks
below a well-covered appointment. `Scoring\Services\DeterministicScorer` already documents the same
rule for leads; this follows it.

The same distinction one level up is why **`signals.heat` is nullable**. A plain integer cannot
distinguish "judged, and cold" from "found before the scorer existed", and the feed's default sort
is heat — so every pre-S12 signal would have sunk to the bottom as though it had been assessed and
found worthless. `SignalSortOrder::apply()` orders nulls last explicitly, because MySQL and SQLite
disagree about where a null sorts; the card falls back to showing **relevance, labelled as
relevance**; and `SignalScore::isMeaningful()` is what any caller asks before presenting a heat as a
judgement.

### 3. The breakdown is stored, because an explanation is of the decision that was taken

`signals.score_breakdown` is written with the row and the card reads it back. It is not recomputed on
render, and that is deliberate: a member who retunes their weights this afternoon must not change
what this morning's feed says about why it was ordered that way. The panel has to explain the score
the feed **was** ranked by.

JSON on the row rather than a table, for the reason `lead_scores.breakdown` is: it is read with the
signal every time and on its own never. Read back **forgivingly** — an unrecognised component is
dropped, so a component removed in a later release does not make every signal scored before it
unrenderable.

Evidence in a breakdown is **grounded in the row** and never prose from a model: "4 outlets",
"$40m, higher than 80% of the deals you have seen". Because the breakdown is stored, Alima's own
sentences ("No amount given.") must be **translation keys** rather than finished English — an English
sentence written into JSON today would reach an Arabic card untranslated for as long as that signal
lives, and nothing in an English build would ever show it. `evidence_translatable` marks which is
which, the same flag and the same reason as `event_label_translatable` (S7).

### 4. A system that learns proposes; it never applies

`signal_source_stats` tallies what each team does with each outlet's stories, per signal type, from
the existing keep/dismiss/restore/convert events. `Signals\Services\SignalTuningAdvisor` turns a
one-sided tally into a **suggestion**: silence this outlet, raise this type's confidence or relevance
floor, retire this query template. Nothing auto-applies.

A feature that silently retunes itself is a feature nobody can debug — not the team, and not whoever
is supporting them six months from now. So accepting a suggestion is an **ordinary audited edit** to
the same columns a member could have typed themselves, with the same audit action a hand edit writes;
the suggestion row is the extra context for _why_. A **declined** suggestion is kept, and the advisor
reads it before proposing, because a suggestion that comes back every week is not a suggestion.

Two bounds that matter. Accepting "raise this floor" may **never lower** one: a member who tightened
a type by hand after the suggestion was written would otherwise have it loosened by agreeing with the
product, with an audit entry reading like an improvement. And `observations()` is **kept stories
only** — the first version counted kept + dismissed while a dismissal incremented both, which capped
every rate at 0.5 against a threshold of 0.7 and made the advisor unreachable code.

### 5. Reading the full article is a bounded exception, and the body is never stored

The oldest limitation in the module is snippet-only extraction. S12 addresses it for the few signals
somebody will actually read: above a heat floor, for cluster representatives only, five a run, under
a per-team monthly ceiling, behind `SIGNALS_ARTICLE_FETCH_ENABLED` (**off**), and honouring
`robots.txt`.

It **reuses**: `Core\Contracts\WebContentFetcher` (the SSRF-safe seam Enrichment already uses — a
second fetcher is a second place for the host checks and byte caps to drift) and the **existing**
`signals.extract_event` prompt with the article where the snippet was (one prompt, one schema, one
set of clamps, one place where `UntrustedContent` wraps a publisher's prose).

**The body is read, used, and dropped.** `signals.article_fetched_at` records _that_ a page was read
and nothing of what it said. Retention and subject-access requests both get harder the moment the
product keeps prose, and a signal is supposed to hold a name, a role and a URL (ADR 0011).

The type is **not** re-decided from the full text: that would move a signal between a team's types
behind their back, and the guard that makes an event key trustworthy lives in `RunSignalWatchAction`
(ADR 0018 §7). Fields fill gaps and never overwrite with nothing — a re-extraction that found no
amount must not erase the one the snippet found (ADR 0008's rule).

`robots.txt` parsing lives in **`Core\Support\RobotsPolicy`**, not in Signals, because the next
module that fetches a page will want it. It needed a new method on the shared contract,
`fetchLines()`, since `fetch()` collapses all whitespace — right for an article, and fatal for a
line-oriented format: the first implementation parsed every policy as a single record and allowed
everything.

### 6. Enrichment runs after the commit, so nothing it does may escape

The fetch and the re-extraction are external calls and therefore outside the transaction
(`CLAUDE.md` §2). That places them **after** the run has been committed as Completed, which means
anything escaping fails an already-finished job: it retries, re-pays for the Serper search, and
alerts somebody about a run that succeeded.

So: a guard per article, `AiPlatformException` round the model call rather than a list of leaf
classes, `AiCreditExceededException` **re-thrown** so the loop stops instead of buying a page per
remaining candidate to meet the same refusal, and an outer guard in the Action. A successful read
**re-scores** the signal, because the amount, the role and the company name are inputs to four of the
eight components and the candidate was chosen on the pre-enrichment heat.

### 7. Signal performance is reported over representatives, and "worked" is not a named stage

`Reporting\Actions\BuildSignalPerformanceAction` counts **cluster representatives**
(`Signal::representatives()`), the same rows the feed and the tab counts use, so the three agree; the
alternative is a report that rewards the noisiest publishers.

Its cohort is `signals.created_at` — when Alima found the event — and the rule is written down in
`docs/modules/reporting-analytics.md` like every other figure, with its bounds reaching the query in
UTC (`DateRange::constrain()`).

"Picked up and worked" is a converted lead that has moved **off the default pipeline stage**, not a
named one. Stages are team-defined rows: there is no stage every team has and no ordering that means
the same thing across two of them, so a column headed "Qualified" would be empty for half the
accounts and incomparable across the rest.

## Consequences

**Good.** The feed answers the question a member actually has, and answers "why is this here" in the
card rather than in a support conversation. The score is arguable with evidence, because the weights
are configuration and the breakdown is on the row. The team's dismissals now do something, visibly,
without anybody editing a template. And the module gained a cost-bounded way to improve its worst
data without storing a word of anybody's prose.

**Costs, accepted.** A number on a card invites trust it may not deserve, which is why the breakdown
is one tap away and an unjudgeable signal says so. Retuning weights does not re-score history —
`signals:rescore` is a command rather than a hook, because a team retuning three times in an
afternoon would otherwise rewrite every signal they own three times. An enriched signal is re-scored
and its cluster siblings are not, which is invisible in the feed and visible to anyone comparing rows
in the database. And the advisor can only ever propose four things: every other useful suggestion
needs evidence of what the team is **not** seeing, and a dismissal ledger is evidence only about what
they were shown.

**Rejected.** _An AI ranker_ — unexplainable, and priced per signal. _Auto-tuning_ — see §4.
_Scoring unknowns zero_ — see §2. _A second fetcher and a second extraction prompt_ — see §5.
_Recomputing the breakdown on render_ — see §3. _A named pipeline stage in the report_ — see §7.
